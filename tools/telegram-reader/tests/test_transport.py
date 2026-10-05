"""Adapter behavior through fake SDK calls; no live Telegram requests."""

import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import AsyncMock, patch

from telethon import errors, types

from reader.store import Store
from reader.transport import TelegramTransport
from tests.test_reader import message


class AdapterTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.store = Store(Path(self.tmp.name) / "db")
        self.handler = AsyncMock()
        self.adapter = TelegramTransport(
            Path(self.tmp.name) / "session", 12345, "a" * 32, self.store, self.handler
        )
        self.adapter.entity = types.Channel(
            id=123,
            title="Test",
            photo=types.ChatPhotoEmpty(),
            date=message(1).date,
            broadcast=True,
            username="Test_channel",
            access_hash=99,
        )

    async def asyncTearDown(self):
        await self.adapter.client.disconnect()
        self.store.close()
        self.tmp.cleanup()

    async def test_live_and_edit_scoped_to_channel(self):
        event = SimpleNamespace(chat_id=-1000000000123, message=message(5))
        await self.adapter.receive(event)
        await self.adapter.receive(
            SimpleNamespace(chat_id=-1000000000456, message=message(6))
        )
        self.handler.assert_awaited_once_with(event.message)

    async def test_difference_checkpoint_only_after_persistence(self):
        self.store.set("channel_pts", 10)
        result = types.updates.ChannelDifference(
            pts=12,
            new_messages=[message(1)],
            other_updates=[],
            chats=[],
            users=[],
            final=True,
            timeout=5,
        )
        self.adapter.call = AsyncMock(return_value=result)
        self.handler.side_effect = RuntimeError("fake persistence failure")
        with self.assertRaises(RuntimeError):
            await self.adapter.difference()
        self.assertEqual(self.store.get("channel_pts"), 10)
        self.handler.side_effect = None
        self.assertEqual(await self.adapter.difference(), 5)
        self.assertEqual(self.store.get("channel_pts"), 12)

    async def test_nonfinal_difference_continues(self):
        self.store.set("channel_pts", 10)
        self.adapter.call = AsyncMock(
            side_effect=[
                types.updates.ChannelDifference(11, [], [], [], [], final=False),
                types.updates.ChannelDifferenceEmpty(12, final=True, timeout=7),
            ]
        )
        self.assertEqual(await self.adapter.difference(), 7)
        self.assertEqual(self.store.get("channel_pts"), 12)
        self.assertEqual(self.adapter.call.await_count, 2)

    async def test_difference_too_long_records_gap(self):
        self.store.set("channel_pts", 10)
        result = types.updates.ChannelDifferenceTooLong(
            dialog=SimpleNamespace(pts=100),
            messages=[message(99)],
            chats=[],
            users=[],
            final=True,
        )
        self.adapter.call = AsyncMock(return_value=result)
        await self.adapter.difference()
        self.assertTrue(self.store.get("recovery_gap"))
        self.assertEqual(self.store.get("channel_pts"), 100)

    async def test_offline_history_paginated_not_truncated_to_ten(self):
        self.adapter.client.get_messages = AsyncMock(
            side_effect=[[message(201), message(200)], [message(199)], []]
        )
        result = await self.adapter.newer(190)
        self.assertEqual([m.id for m in result], [199, 200, 201])
        self.assertEqual(
            self.adapter.client.get_messages.await_args_list[1].kwargs["offset_id"], 200
        )

    async def test_flood_wait_is_persisted_before_retry(self):
        call = AsyncMock(
            side_effect=[errors.FloodWaitError(request=None, capture=2), "ok"]
        )
        now = self.store.clock()
        with patch("reader.transport.asyncio.sleep", new=AsyncMock()):
            self.assertEqual(await self.adapter.call(call), "ok")
        self.assertGreaterEqual(self.store.get("flood_until"), now + 2)
        self.assertEqual(call.await_count, 2)

    async def test_wrong_channel_state_refused(self):
        self.store.set("channel_id", "456")
        self.adapter.client.get_entity = AsyncMock(return_value=self.adapter.entity)
        with self.assertRaises(ValueError):
            await self.adapter.resolve("Test_channel")

    async def test_private_or_nonbroadcast_peer_refused(self):
        self.adapter.entity.username = None
        self.adapter.client.get_entity = AsyncMock(return_value=self.adapter.entity)
        with self.assertRaises(ValueError):
            await self.adapter.resolve("Test_channel")
