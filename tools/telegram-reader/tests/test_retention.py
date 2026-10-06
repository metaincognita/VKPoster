"""Outbox retention preserves pending/recovery events and durable dedup tombstones."""

import unittest

from reader.integration import Outbox
from reader.store import Store


class RetentionTest(unittest.TestCase):
    def test_compact_keeps_ids_pending_and_legacy_ack(self):
        store = Store(":memory:", clock=lambda: 100 * 86400)
        outbox = Outbox(store)
        outbox.enqueue("source", "post", {"text": "old"})
        first = store.db.execute("SELECT event_id FROM outbox").fetchone()[0]
        with store.db:
            store.db.execute(
                "UPDATE outbox SET state='acked',acked_at=?", (10 * 86400,)
            )
        outbox.enqueue("source", "post", {"text": "pending"})
        outbox.enqueue("source", "post", {"text": "legacy"})
        with store.db:
            store.db.execute(
                "UPDATE outbox SET state='acked' WHERE payload LIKE '%legacy%'"
            )
        self.assertEqual(outbox.compact(), 0)
        self.assertEqual(outbox.compact(30), 1)
        outbox.enqueue("source", "post", {"text": "old"})
        self.assertEqual(store.db.execute("SELECT COUNT(*) FROM outbox").fetchone()[0], 3)
        self.assertEqual(
            store.db.execute(
                "SELECT payload FROM outbox WHERE event_id=?", (first,)
            ).fetchone()[0],
            "{}",
        )
        self.assertEqual(outbox.compact(30), 0)
        self.assertEqual(
            store.db.execute("SELECT COUNT(*) FROM outbox WHERE state='pending'").fetchone()[
                0
            ],
            1,
        )
        store.db.close()


class FailureTest(unittest.IsolatedAsyncioTestCase):
    async def test_invalid_payload_quarantined_and_operator_requeues(self):
        import urllib.error

        store = Store(":memory:", clock=lambda: 100)
        outbox = Outbox(store)
        outbox.enqueue("source", "post", {"text": "untrusted"})

        class InvalidAPI:
            async def send(self, event):
                raise urllib.error.HTTPError("https://example.test", 422, "bad", {}, None)

        self.assertEqual(await outbox.deliver(InvalidAPI(), {"source"}), 0)
        summary = outbox.summary()
        self.assertEqual(summary["counts"], {"failed": 1})
        identity = summary["failed_ids"][0]
        self.assertEqual(outbox.compact(30), 0)
        self.assertEqual(outbox.retry_failed(identity), 1)
        self.assertEqual(outbox.retry_failed(identity), 0)
        self.assertEqual(outbox.summary()["counts"], {"pending": 1})
        store.db.close()

    async def test_server_commits_before_ack_restart_does_not_duplicate(self):
        import tempfile
        from pathlib import Path

        now = [100.0]
        accepted = set()

        class LostAckAPI:
            lose = True

            async def send(self, event):
                accepted.add(event["event_id"])
                if self.lose:
                    self.lose = False
                    raise ValueError("lost_ack")

        api = LostAckAPI()
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "fixture.sqlite"
            store = Store(path, clock=lambda: now[0])
            outbox = Outbox(store)
            outbox.enqueue("source", "post", {"text": "test"})
            self.assertEqual(await outbox.deliver(api, {"source"}), 0)
            self.assertEqual(outbox.summary()["counts"], {"pending": 1})
            store.db.close()
            now[0] += 3
            store = Store(path, clock=lambda: now[0])
            outbox = Outbox(store)
            self.assertEqual(await outbox.deliver(api, {"source"}), 1)
            self.assertEqual(await outbox.deliver(api, {"source"}), 0)
            self.assertEqual(len(accepted), 1)
            self.assertEqual(outbox.summary()["counts"], {"acked": 1})
            store.db.close()
