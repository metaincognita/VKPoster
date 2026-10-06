"""Outbox, multiple Sources and HTTP ACK tests with synthetic messages only."""

import contextlib
import io
import json
import os
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import AsyncMock

from reader.integration import InternalAPI, Outbox, SourceStore, SourceWorker
from reader.model import normalize
from reader.service import Reader
from reader.store import Store
from tests.test_reader import message, photo


class OutboxTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.path = Path(self.tmp.name) / "reader.sqlite"
        self.now = [100]
        self.store = Store(self.path, clock=lambda: self.now[0])
        self.box = Outbox(self.store)

    async def asyncTearDown(self):
        self.store.close()
        self.tmp.cleanup()

    async def test_durable_retry_ambiguous_ack_restart_and_no_log_content(self):
        eid = self.box.enqueue("source-a", "item", {"text": "private-post-body"})
        api = SimpleNamespace(
            send=AsyncMock(side_effect=ValueError("sensitive-response"))
        )
        logs = io.StringIO()
        with contextlib.redirect_stdout(logs):
            self.assertEqual(await self.box.deliver(api, {"source-a"}), 0)
        self.assertNotIn("private-post-body", logs.getvalue())
        self.assertNotIn("sensitive-response", logs.getvalue())
        self.store.close()
        self.store = Store(self.path, clock=lambda: self.now[0])
        self.box = Outbox(self.store)
        self.now[0] += 5
        api.send.side_effect = None
        self.assertEqual(await self.box.deliver(api, {"source-a"}), 1)
        self.assertEqual(
            self.box.enqueue("source-a", "item", {"text": "private-post-body"}), eid
        )
        self.assertEqual(await self.box.deliver(api, {"source-a"}), 0)
        self.assertEqual(
            self.store.db.execute("SELECT COUNT(*) FROM outbox").fetchone()[0], 1
        )

    async def test_album_one_snapshot_and_edit_same_message(self):
        for mid in [10, 11]:
            self.store.upsert(normalize(123, message(mid, group=999, photo=photo(mid))))
        self.box.export("source-a", 123)
        self.assertEqual(
            self.store.db.execute("SELECT COUNT(*) FROM outbox").fetchone()[0], 0
        )
        self.now[0] += 6
        self.store.finalize_albums()
        self.box.export("source-a", 123)
        event = json.loads(
            self.store.db.execute("SELECT payload FROM outbox").fetchone()[0]
        )
        self.assertEqual(len(event["payload"]["messages"]), 2)
        self.box.export("source-a", 123)
        self.assertEqual(
            self.store.db.execute("SELECT COUNT(*) FROM outbox").fetchone()[0], 1
        )
        self.store.upsert(normalize(123, message(10, group=999, text="changed")))
        self.now[0] += 6
        self.store.finalize_albums()
        self.box.export("source-a", 123)
        self.assertEqual(
            self.store.db.execute("SELECT COUNT(*) FROM outbox").fetchone()[0], 2
        )
        self.assertEqual(
            self.store.db.execute("SELECT COUNT(*) FROM messages").fetchone()[0], 2
        )

    async def test_disabled_backlog_does_not_starve_enabled_source_and_retry_keeps_order(
        self,
    ):
        for i in range(110):
            self.box.enqueue("disabled", "item", {"i": i})
        self.box.enqueue("active", "item", {"i": 1})
        api = SimpleNamespace(send=AsyncMock(side_effect=ValueError("ack_lost")))
        await self.box.deliver(api, {"active"})
        self.box.enqueue("active", "item", {"i": 2})
        api.send.side_effect = None
        self.assertEqual(await self.box.deliver(api, {"active"}), 0)
        self.now[0] += 5
        self.assertEqual(await self.box.deliver(api, {"active"}), 2)

    async def test_same_channel_in_two_sources_and_disabled_delivery(self):
        self.store.upsert(normalize(123, message(1)))
        self.box.export("source-a", 123)
        self.box.export("source-b", 123)
        api = SimpleNamespace(send=AsyncMock())
        self.assertEqual(await self.box.deliver(api, {"source-a"}), 1)
        self.assertEqual(await self.box.deliver(api, {"source-b"}), 1)
        a, b = SourceStore(self.store, "source-a"), SourceStore(self.store, "source-b")
        a.set("channel_pts", 10)
        b.set("channel_pts", 20)
        self.assertEqual(a.get("channel_pts"), 10)
        self.assertEqual(b.get("channel_pts"), 20)
        a.set("flood_until", 1000)
        self.assertEqual(b.get("flood_until"), 1000)

    async def test_worker_multiple_sources_disable_and_existing_history_export(self):
        api = SimpleNamespace(
            image_jobs=AsyncMock(return_value=[]),
            sources=AsyncMock(
                return_value=[
                    {"id": "a", "username": "channel_a"},
                    {"id": "b", "username": "channel_b"},
                ]
            ),
            send=AsyncMock(),
        )
        transports = []

        def factory(_sid):
            reader = Reader(self.store, None, self.tmp.name)
            transport = SimpleNamespace(
                resolve=AsyncMock(return_value=SimpleNamespace(id=123)),
                latest=AsyncMock(return_value=[message(1)]),
                newer=AsyncMock(return_value=[]),
                by_ids=AsyncMock(return_value=[message(1)]),
                difference=AsyncMock(return_value=1),
                entity=123,
            )
            reader.transport = transport
            transports.append(transport)
            return reader

        worker = SourceWorker(self.store, api, factory, self.tmp.name)
        await worker.tick()
        self.assertEqual(len(worker.readers), 2)
        self.assertEqual(
            self.store.db.execute(
                "SELECT COUNT(*) FROM outbox WHERE state='acked'"
            ).fetchone()[0],
            4,
        )
        api.sources.return_value = [{"id": "b", "username": "channel_b"}]
        await worker.tick()
        self.assertIsNone(transports[0].entity)
        self.assertEqual(set(worker.readers), {"b"})

    async def test_outbox_keeps_old_binding_identity_after_retarget(self):
        box = Outbox(self.store)
        self.store.set("binding:source", 1)
        first = box.enqueue(
            "source", "status", {"status": "connected", "peer_id": "123"}
        )
        self.store.set("binding:source", 2)
        second = box.enqueue(
            "source", "status", {"status": "connected", "peer_id": "456"}
        )
        rows = self.store.db.execute(
            "SELECT payload FROM outbox WHERE event_id IN (?,?) ORDER BY rowid",
            (first, second),
        ).fetchall()
        events = [json.loads(row[0]) for row in rows]
        self.assertEqual([event["connection_version"] for event in events], [1, 2])
        self.assertNotEqual(first, second)
        api = SimpleNamespace(send=AsyncMock())
        self.assertEqual(await box.deliver(api, {"source"}), 1)
        api.send.assert_awaited_once()
        self.assertEqual(api.send.call_args.args[0]["connection_version"], 2)
        self.assertEqual(
            self.store.db.execute(
                "SELECT error FROM outbox WHERE event_id=?", (first,)
            ).fetchone()[0],
            "obsolete_source_binding",
        )


class HTTPTests(unittest.IsolatedAsyncioTestCase):
    async def test_actual_http_secret_ack_validation_and_retry(self):
        received = []
        valid_ack = [False]
        secret = "internal-test-secret-not-real-123456789"

        class Handler(BaseHTTPRequestHandler):
            def do_POST(self):
                received.append(
                    (
                        self.headers.get("Authorization"),
                        json.loads(
                            self.rfile.read(int(self.headers["Content-Length"]))
                        ),
                    )
                )
                self.send_response(200)
                self.end_headers()
                self.wfile.write(
                    json.dumps(
                        {"ack": valid_ack[0], "event_id": received[-1][1]["event_id"]}
                    ).encode()
                )

            def log_message(self, *_args):
                pass

        server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            api = InternalAPI(f"http://127.0.0.1:{server.server_port}", secret)
            event = {"event_id": "a" * 64}
            with self.assertRaises(ValueError):
                await api.send(event)
            valid_ack[0] = True
            await api.send(event)
            self.assertEqual(received[0][0], "Bearer " + secret)
        finally:
            server.shutdown()
            server.server_close()
            thread.join()


@unittest.skipUnless(
    os.environ.get("TEST_INTERNAL_URL"), "requires isolated VKPoster HTTP test service"
)
class PHPContractTests(unittest.IsolatedAsyncioTestCase):
    async def test_reader_php_http_commit_duplicate_album_edit_restart(self):
        api = InternalAPI(
            os.environ["TEST_INTERNAL_URL"], os.environ["TEST_READER_SECRET"]
        )
        sources = await api.sources()
        self.assertEqual(len(sources), 2)
        with tempfile.TemporaryDirectory() as tmp:
            now = [100]
            path = Path(tmp) / "outbox.sqlite"
            store = Store(path, clock=lambda: now[0])
            try:
                box = Outbox(store)
                for mid in [10, 11]:
                    store.upsert(
                        normalize(123, message(mid, group=999, photo=photo(mid)))
                    )
                now[0] += 6
                store.finalize_albums()
                for source in sources:
                    box.export(source["id"], 123)
                self.assertEqual(await box.deliver(api, {s["id"] for s in sources}), 2)
                event = json.loads(
                    store.db.execute("SELECT payload FROM outbox LIMIT 1").fetchone()[0]
                )
                await api.send(event)
                store.close()
                store = Store(path, clock=lambda: now[0])
                box = Outbox(store)
                self.assertEqual(await box.deliver(api, {s["id"] for s in sources}), 0)
                edit = normalize(
                    123, message(10, group=999, text="edited", photo=photo(10))
                )
                edit["edit_date"] = "2026-10-05T11:00:00+00:00"
                store.upsert(edit)
                now[0] += 6
                store.finalize_albums()
                for source in sources:
                    box.export(source["id"], 123)
                self.assertEqual(await box.deliver(api, {s["id"] for s in sources}), 2)
            finally:
                store.close()
