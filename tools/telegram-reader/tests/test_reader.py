"""Offline contract/restart tests using real TL objects and a fake transport."""

import json
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

from PIL import Image, UnidentifiedImageError
from telethon import types

from reader.media import inspect_asset
from reader.model import channel_username, normalize, photo_candidates
from reader.service import Reader
from reader.store import Store


def message(mid, text="Test", group=None, edit=None, photo=None):
    media = types.MessageMediaPhoto(photo=photo) if photo else None
    return types.Message(
        id=mid,
        peer_id=types.PeerChannel(123),
        date=datetime(2026, 10, 4, tzinfo=timezone.utc),
        message=text,
        grouped_id=group,
        edit_date=edit,
        media=media,
        entities=[types.MessageEntityBold(0, 4)],
    )


def photo(pid=77):
    return types.Photo(
        id=pid,
        access_hash=987,
        file_reference=b"secret-reference",
        date=datetime(2026, 10, 4, tzinfo=timezone.utc),
        sizes=[
            types.PhotoStrippedSize("i", b"tiny"),
            types.PhotoSize("x", 800, 600, 1234),
            types.PhotoSizeProgressive("w", 2560, 1920, [1000, 9000]),
        ],
        dc_id=2,
    )


class FakeTransport:
    def __init__(self, messages):
        self.messages = {m.id: m for m in messages}
        self.download_calls = 0

    async def latest(self):
        return sorted(self.messages.values(), key=lambda m: m.id, reverse=True)[:10]

    async def newer(self, minimum_id):
        return sorted(
            (m for m in self.messages.values() if m.id > minimum_id), key=lambda m: m.id
        )

    async def by_ids(self, ids):
        return [self.messages.get(mid) for mid in ids]

    async def download(self, msg, output, size_type, max_bytes):
        self.download_calls += 1
        Image.new("RGB", (2560, 1920), "blue").save(output, format="JPEG")
        return str(output)


class ModelTests(unittest.TestCase):
    def test_only_public_username_urls(self):
        self.assertEqual(channel_username("https://t.me/Test_channel"), "Test_channel")
        for value in (
            "http://t.me/test",
            "https://evil.test/test",
            "https://t.me/+invite",
            "https://t.me/test/5",
            "https://t.me/test?x=1",
            "https://user@t.me/test",
            "https://t.me/s/test",
            "https://t.me:444/test",
        ):
            with self.subTest(value=value), self.assertRaises(ValueError):
                channel_username(value)

    def test_entities_ids_and_original_text(self):
        item = normalize(123, message(9, "Test 😀", group=2**63 - 1, photo=photo()))
        self.assertEqual(item["text"], "Test 😀")
        self.assertEqual(item["grouped_id"], "9223372036854775807")
        self.assertEqual(item["entities"][0]["length"], 4)
        self.assertNotIn("secret-reference", json.dumps(item))
        self.assertNotIn("access_hash", json.dumps(item))

    def test_photo_chooses_full_largest_size(self):
        sizes = photo_candidates(photo())
        self.assertEqual(
            sizes[-1], {"type": "w", "width": 2560, "height": 1920, "bytes": 9000}
        )
        self.assertEqual(len(sizes), 2)


class StoreTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.now = 100
        self.path = Path(self.tmp.name) / "reader.sqlite"
        self.store = Store(self.path, lambda: self.now)

    def tearDown(self):
        self.store.close()
        self.tmp.cleanup()

    def put(self, msg):
        return self.store.upsert(normalize(123, msg))

    def test_repeat_delivery_and_restart(self):
        self.assertTrue(self.put(message(1)))
        self.assertFalse(self.put(message(1)))
        self.store.close()
        self.store = Store(self.path)
        self.assertFalse(self.put(message(1)))
        self.assertEqual(self.store.summary()["messages"], 1)
        self.assertEqual(self.store.summary()["revisions"], 1)

    def test_edit_preserves_identity_and_old_snapshot_cannot_overwrite(self):
        original = message(1)
        edited = message(1, "Edited", edit=original.date + timedelta(seconds=5))
        self.put(original)
        self.assertTrue(self.put(edited))
        self.assertFalse(self.put(original))
        self.assertEqual(self.store.summary()["messages"], 1)
        self.assertEqual(self.store.summary()["revisions"], 2)

    def test_durable_album_and_late_part(self):
        self.put(message(3, group=55))
        self.put(message(1, group=55))
        self.now += 6
        self.store.finalize_albums()
        self.assertEqual(
            self.store.db.execute("SELECT members FROM albums").fetchone()[0], "[1, 3]"
        )
        self.store.close()
        self.store = Store(self.path, lambda: self.now)
        self.put(message(2, group=55))
        self.now += 6
        self.store.finalize_albums()
        self.assertEqual(self.store.summary()["albums"], 1)
        self.assertEqual(
            self.store.db.execute("SELECT members FROM albums").fetchone()[0],
            "[1, 2, 3]",
        )

    def test_deleted_message_not_resurrected_by_history(self):
        self.put(message(1, group=55))
        self.store.delete(123, [1])
        self.assertFalse(self.put(message(1, group=55)))
        self.now += 6
        self.store.finalize_albums()
        self.assertEqual(
            self.store.db.execute("SELECT members FROM albums").fetchone()[0], "[]"
        )

    def test_download_retry_is_durable(self):
        self.put(message(1, photo=photo()))
        task = self.store.pending_download(123)
        self.store.fail_download(task, "Offline")
        self.assertIsNone(self.store.pending_download(123))
        self.now += 3
        self.assertEqual(self.store.pending_download(123)["attempts"], 1)


class ServiceTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.path = Path(self.tmp.name)
        self.store = Store(self.path / "reader.sqlite")
        self.transport = FakeTransport([])
        self.reader = Reader(self.store, self.transport, self.path)
        self.reader.channel = "123"

    async def asyncTearDown(self):
        self.store.close()
        self.tmp.cleanup()

    async def test_offline_more_than_ten_messages_and_edit(self):
        self.transport.messages = {1: message(1)}
        await self.reader.startup()
        self.store.close()
        self.store = Store(self.path / "reader.sqlite")
        self.reader.store = self.store
        self.transport.messages = {i: message(i) for i in range(1, 42)}
        self.transport.messages[1] = message(
            1, "Offline edit", edit=datetime(2026, 10, 4, 1, tzinfo=timezone.utc)
        )
        await self.reader.startup()
        self.assertEqual(self.store.summary()["messages"], 41)
        self.assertEqual(self.store.summary()["revisions"], 42)
        await self.reader.startup()
        self.assertEqual(self.store.summary()["revisions"], 42)

    async def test_backfill_extends_album_boundary(self):
        self.transport.messages = {
            i: message(i, group=55 if i in (1, 2, 3) else None) for i in range(1, 13)
        }
        await self.reader.startup()
        self.assertEqual(self.store.summary()["messages"], 12)

    async def test_photo_saved_once_with_hash_dimensions_and_ids(self):
        self.transport.messages = {1: message(1, photo=photo())}
        await self.reader.startup()
        self.assertTrue(await self.reader.download_once())
        self.assertFalse(await self.reader.download_once())
        asset = json.loads(
            self.store.db.execute("SELECT asset FROM downloads").fetchone()[0]
        )
        self.assertEqual((asset["width"], asset["height"]), (2560, 1920))
        self.assertEqual(asset["telegram_photo_id"], "77")
        self.assertEqual(asset["message_id"], 1)
        self.assertEqual(len(asset["sha256"]), 64)
        self.assertEqual(self.transport.download_calls, 1)

    async def test_replaced_photo_task_becomes_terminal(self):
        await self.reader.ingest(message(1, photo=photo()))
        self.transport.messages = {1: message(1, photo=photo(88))}
        await self.reader.download_once()
        self.assertEqual(
            self.store.db.execute("SELECT status FROM downloads").fetchone()[0],
            "failed",
        )

    async def test_photo_limit_does_not_save_asset(self):
        self.transport.messages = {1: message(1, photo=photo())}
        self.reader.max_photo_bytes = 5
        await self.reader.startup()
        await self.reader.download_once()
        self.assertEqual(
            self.store.db.execute("SELECT status FROM downloads").fetchone()[0],
            "failed",
        )


class SecurityTests(unittest.TestCase):
    def test_no_write_telegram_operations_in_transport(self):
        import ast

        tree = ast.parse(
            (Path(__file__).parents[1] / "reader" / "transport.py").read_text()
        )
        forbidden = {
            "send_message",
            "send_file",
            "edit_message",
            "delete_messages",
            "forward_messages",
            "JoinChannelRequest",
            "SendMessageRequest",
        }
        attrs = {
            node.attr for node in ast.walk(tree) if isinstance(node, ast.Attribute)
        }
        self.assertFalse(attrs & forbidden)

    def test_corrupt_photo_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "bad.jpg"
            path.write_bytes(b"not an image")
            with self.assertRaises(UnidentifiedImageError):
                inspect_asset(path, 1000)
