"""Approved photo jobs only; private cache plus durable result outbox, never direct PHP DB access."""

import base64
import json
import urllib.error
from pathlib import Path

from .media import inspect_asset


class ImageDelivery:
    def __init__(self, store):
        self.store = store
        store.db.executescript("""
            CREATE TABLE IF NOT EXISTS image_outbox (
                job_id TEXT PRIMARY KEY, source_id TEXT NOT NULL, job TEXT NOT NULL,
                result TEXT, state TEXT NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0, retry_at REAL NOT NULL DEFAULT 0,
                error TEXT);
        """)

    async def tick(self, api, readers):
        jobs = await api.image_jobs(list(readers))
        with self.store.db:
            for job in jobs:
                if job["source_id"] in readers:
                    self.store.db.execute(
                        "INSERT OR IGNORE INTO image_outbox(job_id,source_id,job) VALUES(?,?,?)",
                        (job["job_id"], job["source_id"], json.dumps(job)),
                    )
        offered = {job["job_id"] for job in jobs}
        delivered = 0
        rows = []
        for sid in sorted(readers):
            rows.extend(
                self.store.db.execute(
                    "SELECT * FROM image_outbox WHERE state='pending' AND source_id=? ORDER BY rowid LIMIT 100",
                    (sid,),
                ).fetchall()
            )
        for row in rows:
            sid = row["source_id"]
            if sid not in readers or row["retry_at"] > self.store.clock():
                continue
            if row["result"] is None and row["job_id"] not in offered:
                continue  # No download/processing of a job no longer approved by PHP.
            reader = readers[sid][1]
            job = json.loads(row["job"])
            try:
                result = (
                    json.loads(row["result"])
                    if row["result"]
                    else await self.download(job, reader)
                )
                if result is None:
                    continue
                with self.store.db:
                    self.store.db.execute(
                        "UPDATE image_outbox SET result=? WHERE job_id=?",
                        (json.dumps(result), row["job_id"]),
                    )
                payload = dict(result)
                path = payload.pop("path", None)
                if path:
                    try:
                        asset = inspect_asset(Path(path), job["max_bytes"])
                        if asset["sha256"] != payload["sha256"]:
                            raise ValueError("image_cache_digest")
                    except (OSError, ValueError):
                        with self.store.db:
                            self.store.db.execute(
                                "UPDATE image_outbox SET result=NULL WHERE job_id=?",
                                (row["job_id"],),
                            )
                            self.store.db.execute(
                                "UPDATE downloads SET status='pending',asset=NULL,retry_at=0 WHERE channel=? AND message_id=? AND photo_id=?",
                                (
                                    str(job["peer_id"]),
                                    job["message_id"],
                                    str(job["photo_id"]),
                                ),
                            )
                        raise ValueError("image_cache_unavailable") from None
                    payload["data"] = base64.b64encode(Path(path).read_bytes()).decode()
                await api.send_image(payload)
            except (OSError, ValueError, urllib.error.URLError) as error:
                # Server validation failures become a safe terminal result, ACKed durably on next tick.
                if isinstance(error, urllib.error.HTTPError) and error.code == 404:
                    with self.store.db:
                        self.store.db.execute(
                            "UPDATE image_outbox SET state='cancelled',error='job_removed' WHERE job_id=?",
                            (row["job_id"],),
                        )
                    continue
                if isinstance(error, urllib.error.HTTPError) and error.code == 422:
                    result = {
                        k: job[k]
                        for k in ("job_id", "peer_id", "message_id", "photo_id")
                    }
                    result["error"] = "invalid_image"
                    with self.store.db:
                        self.store.db.execute(
                            "UPDATE image_outbox SET result=? WHERE job_id=?",
                            (json.dumps(result), row["job_id"]),
                        )
                attempts = row["attempts"] + 1
                with self.store.db:
                    self.store.db.execute(
                        "UPDATE image_outbox SET attempts=?,retry_at=?,error=? WHERE job_id=?",
                        (
                            attempts,
                            self.store.clock() + min(300, 2 ** min(attempts, 9)),
                            type(error).__name__,
                            row["job_id"],
                        ),
                    )
                print("image_delivery_retry error=" + type(error).__name__, flush=True)
            else:
                with self.store.db:
                    self.store.db.execute(
                        "UPDATE image_outbox SET state='acked',error=NULL WHERE job_id=?",
                        (row["job_id"],),
                    )
                delivered += 1
        return delivered

    async def download(self, job, reader):
        if str(reader.channel) != str(job["peer_id"]):
            raise ValueError("image_peer_identity")
        if (
            not isinstance(job["max_bytes"], int)
            or not 1 <= job["max_bytes"] <= 16777216
        ):
            raise ValueError("image_job_limit")
        identity = (str(job["peer_id"]), int(job["message_id"]), str(job["photo_id"]))
        with self.store.db:
            self.store.db.execute(
                "INSERT OR IGNORE INTO downloads(channel,message_id,photo_id,status) VALUES(?,?,?,'pending')",
                identity,
            )
        task = self.store.db.execute(
            "SELECT * FROM downloads WHERE channel=? AND message_id=? AND photo_id=?",
            identity,
        ).fetchone()
        if task["status"] == "pending" and task["retry_at"] <= self.store.clock():
            old_limit = reader.max_photo_bytes
            reader.max_photo_bytes = min(old_limit, job["max_bytes"])
            try:
                await reader.download_once(task)
            finally:
                reader.max_photo_bytes = old_limit
            task = self.store.db.execute(
                "SELECT * FROM downloads WHERE channel=? AND message_id=? AND photo_id=?",
                identity,
            ).fetchone()
        result = {k: job[k] for k in ("job_id", "peer_id", "message_id", "photo_id")}
        if task["status"] == "failed":
            return dict(result, error="photo_unavailable")
        if task["status"] != "ready":
            return None
        asset = json.loads(task["asset"])
        path = (reader.state / asset["path"]).resolve()
        if not path.is_relative_to((reader.state / "photos").resolve()):
            raise ValueError("image_cache_path")
        return dict(result, path=str(path), sha256=asset["sha256"])
