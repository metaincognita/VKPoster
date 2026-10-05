"""Durable message identities, revisions, album buffers and download tasks."""

import json
import sqlite3
import time


class Store:
    def __init__(self, path, clock=time.time):
        self.clock = clock
        self.db = sqlite3.connect(path)
        self.db.row_factory = sqlite3.Row
        self.db.executescript("""
            PRAGMA journal_mode=WAL;
            PRAGMA foreign_keys=ON;
            CREATE TABLE IF NOT EXISTS messages (
                channel TEXT, id INTEGER, grouped_id TEXT, payload TEXT,
                content_hash TEXT, deleted INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY(channel,id));
            CREATE TABLE IF NOT EXISTS revisions (
                channel TEXT, id INTEGER, content_hash TEXT, payload TEXT, observed_at REAL,
                UNIQUE(channel,id,content_hash));
            CREATE TABLE IF NOT EXISTS albums (
                channel TEXT, grouped_id TEXT, due_at REAL, status TEXT, members TEXT,
                PRIMARY KEY(channel,grouped_id));
            CREATE TABLE IF NOT EXISTS downloads (
                channel TEXT, message_id INTEGER, photo_id TEXT, status TEXT, attempts INTEGER DEFAULT 0,
                retry_at REAL DEFAULT 0, asset TEXT, error TEXT,
                PRIMARY KEY(channel,message_id,photo_id));
            CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY, value TEXT);
        """)

    def upsert(self, payload):
        channel, mid = payload["channel_id"], payload["message_id"]
        old = self.db.execute(
            "SELECT * FROM messages WHERE channel=? AND id=?", (channel, mid)
        ).fetchone()
        if old:
            if old["deleted"]:
                return False
            prior = json.loads(old["payload"])
            if (prior.get("edit_date") or prior["date"]) > (
                payload.get("edit_date") or payload["date"]
            ):
                return False
            if old["content_hash"] == payload["content_hash"] and not old["deleted"]:
                return False
        encoded = json.dumps(payload, ensure_ascii=False)
        now = self.clock()
        with self.db:
            self.db.execute(
                "INSERT INTO messages VALUES(?,?,?,?,?,0) ON CONFLICT(channel,id) DO UPDATE SET grouped_id=excluded.grouped_id,payload=excluded.payload,content_hash=excluded.content_hash,deleted=0",
                (channel, mid, payload["grouped_id"], encoded, payload["content_hash"]),
            )
            self.db.execute(
                "INSERT OR IGNORE INTO revisions VALUES(?,?,?,?,?)",
                (channel, mid, payload["content_hash"], encoded, now),
            )
            for group in {payload["grouped_id"], old["grouped_id"] if old else None} - {
                None
            }:
                self.db.execute(
                    "INSERT INTO albums VALUES(?,?,?,'collecting','[]') ON CONFLICT(channel,grouped_id) DO UPDATE SET due_at=excluded.due_at,status='collecting'",
                    (channel, group, now + 5),
                )
            media = payload.get("media")
            if media and media["kind"] == "photo" and media["selected"]:
                self.db.execute(
                    "INSERT OR IGNORE INTO downloads(channel,message_id,photo_id,status) VALUES(?,?,?,'pending')",
                    (channel, mid, media["telegram_id"]),
                )
        return True

    def delete(self, channel, ids):
        with self.db:
            for mid in ids:
                row = self.db.execute(
                    "SELECT grouped_id FROM messages WHERE channel=? AND id=?",
                    (str(channel), mid),
                ).fetchone()
                self.db.execute(
                    "UPDATE messages SET deleted=1 WHERE channel=? AND id=?",
                    (str(channel), mid),
                )
                if row and row[0]:
                    self.db.execute(
                        "UPDATE albums SET status='collecting',due_at=? WHERE channel=? AND grouped_id=?",
                        (self.clock() + 5, str(channel), row[0]),
                    )

    def finalize_albums(self):
        ready = self.db.execute(
            "SELECT * FROM albums WHERE status='collecting' AND due_at<=?",
            (self.clock(),),
        ).fetchall()
        with self.db:
            for album in ready:
                ids = [
                    r[0]
                    for r in self.db.execute(
                        "SELECT id FROM messages WHERE channel=? AND grouped_id=? AND deleted=0 ORDER BY id",
                        (album["channel"], album["grouped_id"]),
                    )
                ]
                self.db.execute(
                    "UPDATE albums SET status='quiescent',members=? WHERE channel=? AND grouped_id=?",
                    (json.dumps(ids), album["channel"], album["grouped_id"]),
                )
        return len(ready)

    def get(self, key, default=None):
        row = self.db.execute(
            "SELECT value FROM metadata WHERE key=?", (key,)
        ).fetchone()
        return json.loads(row[0]) if row else default

    def set(self, key, value):
        with self.db:
            self.db.execute(
                "INSERT INTO metadata VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
                (key, json.dumps(value)),
            )

    def high_water(self, channel):
        return self.db.execute(
            "SELECT COALESCE(MAX(id),0) FROM messages WHERE channel=?", (str(channel),)
        ).fetchone()[0]

    def recent_ids(self, channel):
        return [
            r[0]
            for r in self.db.execute(
                "SELECT id FROM messages WHERE channel=? AND deleted=0 ORDER BY id DESC LIMIT 100",
                (str(channel),),
            )
        ]

    def pending_download(self, channel):
        return self.db.execute(
            "SELECT d.* FROM downloads d JOIN messages m ON m.channel=d.channel AND m.id=d.message_id WHERE d.channel=? AND d.status='pending' AND d.retry_at<=? AND m.deleted=0 ORDER BY d.message_id LIMIT 1",
            (str(channel), self.clock()),
        ).fetchone()

    def finish_download(self, task, asset):
        with self.db:
            self.db.execute(
                "UPDATE downloads SET status='ready',asset=?,error=NULL WHERE channel=? AND message_id=? AND photo_id=?",
                (
                    json.dumps(asset),
                    task["channel"],
                    task["message_id"],
                    task["photo_id"],
                ),
            )

    def fail_download(self, task, code, terminal=False):
        with self.db:
            attempts = task["attempts"] + 1
            self.db.execute(
                "UPDATE downloads SET status=?,attempts=?,retry_at=?,error=? WHERE channel=? AND message_id=? AND photo_id=?",
                (
                    "failed" if terminal or attempts >= 5 else "pending",
                    attempts,
                    self.clock() + min(300, 2**attempts),
                    code,
                    task["channel"],
                    task["message_id"],
                    task["photo_id"],
                ),
            )

    def summary(self):
        return {
            table: self.db.execute("SELECT COUNT(*) FROM " + table).fetchone()[0]
            for table in ("messages", "revisions", "albums", "downloads")
        }

    def close(self):
        self.db.close()
