"""Internal HTTP contract and durable SQLite outbox; no access to application DB."""

import asyncio
import hashlib
import json
import os
import urllib.error
import urllib.request
from datetime import datetime, timezone
from urllib.parse import urlsplit

from telethon import TelegramClient

from .auth import authenticate
from .image_delivery import ImageDelivery
from .service import Reader
from .transport import TelegramTransport


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class InternalAPI:
    def __init__(self, base, secret):
        url = urlsplit(base)
        if (
            url.scheme not in ("http", "https")
            or not url.hostname
            or url.username
            or url.password
            or url.query
            or url.fragment
            or (
                url.scheme == "http"
                and url.hostname
                not in ("localhost", "127.0.0.1", "nginx", "host.docker.internal")
            )
            or len(secret) < 32
        ):
            raise ValueError("internal_api_configuration")
        self.base = base.rstrip("/")
        self.secret = secret
        self.opener = urllib.request.build_opener(NoRedirect())

    def request(self, path, event=None):
        encoded = (
            None if event is None else json.dumps(event, ensure_ascii=False).encode()
        )
        request = urllib.request.Request(
            self.base + path,
            data=encoded,
            headers={
                "Authorization": "Bearer " + self.secret,
                "Content-Type": "application/json",
                "Accept": "application/json",
            },
        )
        with self.opener.open(request, timeout=10) as response:
            raw = response.read(1048577)
            if len(raw) > 1048576:
                raise ValueError("internal_response_limit")
            return json.loads(raw)

    async def heartbeat(self):
        result = await asyncio.to_thread(self.request, "/internal/reader-heartbeat", {})
        if result.get("ack") is not True:
            raise ValueError("invalid_heartbeat_ack")

    async def sources(self):
        result = await asyncio.to_thread(self.request, "/internal/sources")
        if result.get("version") != 1 or not isinstance(result.get("sources"), list):
            raise ValueError("invalid_source_list")
        return result["sources"]

    async def send(self, event):
        result = await asyncio.to_thread(self.request, "/internal/source-events", event)
        if result.get("ack") is not True or result.get("event_id") != event["event_id"]:
            raise ValueError("invalid_ack")

    async def image_jobs(self):
        result = await asyncio.to_thread(self.request, "/internal/source-image-jobs")
        if result.get("version") != 1 or not isinstance(result.get("jobs"), list):
            raise ValueError("invalid_image_job_list")
        return result["jobs"]

    async def send_image(self, payload):
        result = await asyncio.to_thread(self.request, "/internal/source-image-results", payload)
        if result.get("ack") is not True or result.get("job_id") != payload["job_id"]:
            raise ValueError("invalid_image_ack")


class Outbox:
    def __init__(self, store):
        self.store = store
        store.db.executescript("""
            CREATE TABLE IF NOT EXISTS outbox (
                event_id TEXT PRIMARY KEY, source_id TEXT NOT NULL, payload TEXT NOT NULL,
                state TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0,
                retry_at REAL NOT NULL DEFAULT 0, error TEXT);
        """)

        columns = {r[1] for r in store.db.execute("PRAGMA table_info(outbox)")}
        if "acked_at" not in columns:
            store.db.execute("ALTER TABLE outbox ADD COLUMN acked_at REAL")
            store.db.commit()

    def summary(self):
        return {
            "counts": {
                r[0]: r[1] for r in self.store.db.execute(
                    "SELECT state,COUNT(*) FROM outbox GROUP BY state"
                )
            },
            "failed_ids": [r[0] for r in self.store.db.execute(
                "SELECT event_id FROM outbox WHERE state='failed' LIMIT 10"
            )],
        }

    def retry_failed(self, event_id):
        """Explicit operator requeue after repairing a quarantined event, never automatic."""
        with self.store.db:
            return self.store.db.execute(
                "UPDATE outbox SET state='pending',attempts=0,retry_at=0,error=NULL "
                "WHERE event_id=? AND state='failed'", (event_id,)
            ).rowcount

    def compact(self, retention_days=0):
        """Opt-in acknowledged payload cleanup, retaining event IDs forever for dedup.

        Legacy ACKs without a timestamp and every pending/error event are retained.
        Message revisions, image cache, session and recovery state are untouched.
        """
        if retention_days < 7:
            return 0
        cutoff = self.store.clock() - retention_days * 86400
        with self.store.db:
            return self.store.db.execute(
                "UPDATE outbox SET payload='{}' WHERE event_id IN "
                "(SELECT event_id FROM outbox WHERE state='acked' AND acked_at<? "
                "AND payload!='{}' LIMIT 100)",
                (cutoff,),
            ).rowcount

    def enqueue(self, source_id, kind, payload):
        event = {"version": 1, "source_id": source_id, "kind": kind, "payload": payload}
        encoded = json.dumps(event, sort_keys=True, ensure_ascii=False)
        event_id = hashlib.sha256(encoded.encode()).hexdigest()
        event["event_id"] = event_id
        with self.store.db:
            self.store.db.execute(
                "INSERT OR IGNORE INTO outbox(event_id,source_id,payload) VALUES(?,?,?)",
                (
                    event_id,
                    source_id,
                    json.dumps(event, sort_keys=True, ensure_ascii=False),
                ),
            )
        return event_id

    def export(self, source_id, channel):
        rows = self.store.db.execute(
            "SELECT * FROM messages WHERE channel=? AND deleted=0 ORDER BY id",
            (str(channel),),
        ).fetchall()
        groups = {}
        for row in rows:
            payload = json.loads(row["payload"])
            group = payload["grouped_id"]
            if group is not None:
                album = self.store.db.execute(
                    "SELECT status FROM albums WHERE channel=? AND grouped_id=?",
                    (str(channel), group),
                ).fetchone()
                if not album or album[0] != "quiescent":
                    continue
            key = "album:" + group if group else "message:" + str(payload["message_id"])
            groups.setdefault(key, []).append(payload)
        for members in groups.values():
            self.enqueue(
                source_id,
                "item",
                {
                    "peer_id": str(channel),
                    "grouped_id": members[0]["grouped_id"],
                    "messages": members,
                },
            )

    async def deliver(self, api, enabled):
        delivered = 0
        for source in sorted(enabled):
            rows = self.store.db.execute(
                "SELECT * FROM outbox WHERE state='pending' AND source_id=? ORDER BY rowid LIMIT 100",
                (source,),
            ).fetchall()
            for row in rows:
                if row["retry_at"] > self.store.clock():
                    break
                try:
                    await api.send(json.loads(row["payload"]))
                except (OSError, ValueError, urllib.error.URLError) as error:
                    attempts = row["attempts"] + 1
                    with self.store.db:
                        self.store.db.execute(
                            "UPDATE outbox SET attempts=?,retry_at=?,error=?,state=? WHERE event_id=?",
                            (
                                attempts,
                                self.store.clock() + min(300, 2 ** min(attempts, 9)),
                                type(error).__name__,
                                "failed" if isinstance(error, urllib.error.HTTPError) and error.code == 422 else "pending",
                                row["event_id"],
                            ),
                        )
                    print("delivery_retry error=" + type(error).__name__, flush=True)
                    break
                else:
                    with self.store.db:
                        self.store.db.execute(
                            "UPDATE outbox SET state='acked',error=NULL,acked_at=? WHERE event_id=?",
                            (self.store.clock(), row["event_id"]),
                        )
                    delivered += 1
        return delivered


class SourceStore:
    """Namespaced update state; account/FLOOD_WAIT and raw message identities stay shared."""

    def __init__(self, store, source):
        self.store, self.prefix = store, "source:" + source + ":"

    def get(self, key, default=None):
        return self.store.get(
            key if key == "flood_until" else self.prefix + key, default
        )

    def set(self, key, value):
        self.store.set(key if key == "flood_until" else self.prefix + key, value)

    def __getattr__(self, name):
        return getattr(self.store, name)


class SourceWorker:
    def __init__(self, store, api, factory, state, source_id=None):
        self.store, self.api, self.factory, self.state = store, api, factory, state
        self.outbox = Outbox(store)
        self.images = ImageDelivery(store)
        self.readers = {}
        self.source_id = source_id

    def drop(self, sid):
        reader = self.readers.pop(sid)[1]
        transport = reader.transport
        transport.entity = None
        if isinstance(transport, TelegramTransport):
            transport.client.remove_event_handler(transport.receive)
            transport.client.remove_event_handler(transport.raw)

    async def tick(self):
        try:
            sources = await self.api.sources()
        except (OSError, ValueError, urllib.error.URLError):
            for sid in list(self.readers):
                self.drop(sid)
            raise
        if self.source_id is not None:
            sources = [s for s in sources if s["id"] == self.source_id]
        enabled = {s["id"] for s in sources}
        for sid in list(self.readers):
            if sid not in enabled:
                self.drop(sid)
        for source in sources:
            sid, username = source["id"], source["username"]
            prior = self.readers.get(sid)
            if prior and prior[0] != username:
                self.drop(sid)
                for key in ("channel_id", "channel_pts", "channel_username"):
                    SourceStore(self.store, sid).set(key, None)
            scope = SourceStore(self.store, sid)
            saved_username = scope.get("channel_username")
            if saved_username and saved_username.lower() != username.lower():
                for key in ("channel_id", "channel_pts", "channel_username"):
                    scope.set(key, None)
            try:
                if sid not in self.readers:
                    reader = self.factory(sid)
                    entity = await reader.transport.resolve(username)
                    reader.channel = str(entity.id)
                    self.readers[sid] = (username, reader)
                    await reader.startup()
                    self.outbox.enqueue(
                        sid,
                        "status",
                        {
                            "status": "connected",
                            "peer_id": reader.channel,
                            "observed_at": datetime.now(timezone.utc).isoformat(),
                        },
                    )
                    print("source_connected source_id=" + sid, flush=True)
                else:
                    reader = self.readers[sid][1]
                    await reader.transport.difference()
                    await reader.apply(
                        await reader.transport.newer(
                            self.store.high_water(reader.channel)
                        )
                    )
                    await reader.reconcile()
                self.store.finalize_albums()
                self.outbox.export(sid, reader.channel)
            except Exception as error:  # noqa: BLE001
                # SDK messages and request data are deliberately discarded.
                if sid in self.readers:
                    self.drop(sid)
                self.outbox.enqueue(
                    sid,
                    "status",
                    {
                        "status": "error",
                        "error_code": "read_failed",
                        "observed_at": datetime.now(timezone.utc).isoformat(),
                    },
                )
                print("source_read_error type=" + type(error).__name__, flush=True)
        delivered = await self.outbox.deliver(self.api, enabled)
        await self.images.tick(self.api, self.readers)
        return delivered


async def run_sources(args, state, store):
    api = InternalAPI(
        os.environ.get("VKPOSTER_INTERNAL_URL", "http://host.docker.internal:8080"),
        os.environ.get("SOURCES_READER_SECRET", ""),
    )
    api_id = int(os.environ.get("TELEGRAM_API_ID") or "0")
    api_hash = os.environ.get("TELEGRAM_API_HASH") or ""
    if api_id <= 0 or len(api_hash) != 32:
        raise ValueError("telegram_credentials")
    client = TelegramClient(
        str(state / "telegram"),
        api_id,
        api_hash,
        sequential_updates=True,
        flood_sleep_threshold=0,
    )
    shared_lock = asyncio.Lock()
    try:
        await authenticate(
            client, os.environ.get("TELEGRAM_PHONE", ""), interactive=False
        )
        me = await client.get_me()
        if me.bot or store.get("account_id", str(me.id)) != str(me.id):
            raise ValueError("test_account_identity")
        store.set("account_id", str(me.id))

        def factory(sid):
            reader = Reader(store, None, state)
            transport = TelegramTransport(
                state / "telegram",
                api_id,
                api_hash,
                SourceStore(store, sid),
                reader.ingest,
                client=client,
            )
            transport.rpc_lock = shared_lock
            reader.transport = transport
            return reader

        worker = SourceWorker(store, api, factory, state, args.source_id)
        while True:
            try:
                count = await worker.tick()
                await api.heartbeat()
                worker.outbox.compact(int(os.environ.get("READER_ACK_RETENTION_DAYS", "0")))
                print(
                    f"sources_cycle active={len(worker.readers)} acked={count}",
                    flush=True,
                )
            except (OSError, ValueError, urllib.error.URLError) as error:
                print("sources_api_error type=" + type(error).__name__, flush=True)
            if args.once:
                if not worker.readers:
                    raise ValueError("no_sources_connected")
                # Let startup albums settle before exporting, without any media download.
                await asyncio.sleep(6)
                store.finalize_albums()
                for sid, (_, reader) in worker.readers.items():
                    worker.outbox.export(sid, reader.channel)
                await worker.outbox.deliver(api, set(worker.readers))
                for sid in worker.readers:
                    pending = store.db.execute(
                        "SELECT COUNT(*) FROM outbox WHERE state='pending' AND source_id=?",
                        (sid,),
                    ).fetchone()[0]
                    if pending:
                        raise ValueError("delivery_pending")
                return
            await asyncio.sleep(10)
    finally:
        await client.disconnect()
