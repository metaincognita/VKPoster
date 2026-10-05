"""Read-only Telethon adapter. No send, edit, forward or join operations."""

import asyncio
from typing import Protocol

from telethon import TelegramClient, errors, events, functions, types


class ReadTransport(Protocol):
    async def latest(self): ...
    async def newer(self, minimum_id): ...
    async def by_ids(self, ids): ...


class TelegramTransport:
    def __init__(
        self,
        session,
        api_id,
        api_hash,
        store,
        on_message,
        enable_updates=True,
        client=None,
    ):
        self.client = client or TelegramClient(
            str(session),
            api_id,
            api_hash,
            sequential_updates=True,
            flood_sleep_threshold=0,
            receive_updates=enable_updates,
        )
        self.store = store
        self.on_message = on_message
        self.entity = None
        self.client.add_event_handler(self.receive, events.NewMessage())
        self.client.add_event_handler(self.receive, events.MessageEdited())
        self.client.add_event_handler(
            self.raw, events.Raw(types.UpdateDeleteChannelMessages)
        )
        self.rpc_lock = asyncio.Lock()

    async def call(self, factory):
        # One RPC at a time; long waits are durable and shared by all tasks.
        async with self.rpc_lock:
            while True:
                remaining = self.store.get("flood_until", 0) - self.store.clock()
                if remaining > 0:
                    await asyncio.sleep(remaining)
                try:
                    return await factory()
                except errors.FloodWaitError as error:
                    self.store.set("flood_until", self.store.clock() + error.seconds)
                    print("FLOOD_WAIT: requests paused", flush=True)

    async def receive(self, event):
        if self.entity and event.chat_id == -1000000000000 - self.entity.id:
            await self.on_message(event.message)

    async def raw(self, event):
        if self.entity and event.channel_id == self.entity.id:
            self.store.delete(self.entity.id, event.messages)

    async def resolve(self, username, initialize_updates=True):
        entity = await self.call(lambda: self.client.get_entity(username))
        if (
            not isinstance(entity, types.Channel)
            or not entity.broadcast
            or not entity.username
        ):
            raise ValueError("A public broadcast channel is required")
        saved = self.store.get("channel_id")
        if saved is not None and str(entity.id) != saved:
            raise ValueError(
                "This state belongs to another channel; use a separate volume"
            )
        self.entity = entity
        self.store.set("channel_id", str(entity.id))
        self.store.set("channel_username", entity.username)
        if initialize_updates and self.store.get("channel_pts") is None:
            full = await self.call(
                lambda: self.client(functions.channels.GetFullChannelRequest(entity))
            )
            pts = getattr(full.full_chat, "pts", None)
            if pts is None:
                raise ValueError("Telegram did not provide channel update state")
            self.store.set("channel_pts", pts)
        return entity

    async def latest(self):
        return await self.call(lambda: self.client.get_messages(self.entity, limit=10))

    async def newer(self, minimum_id):
        # Paginate fully; a restart with >10 unseen posts must not skip history.
        found = []
        offset = 0
        while True:
            page = await self.call(
                lambda offset=offset: self.client.get_messages(
                    self.entity, limit=100, min_id=minimum_id, offset_id=offset
                )
            )
            if not page:
                return list(reversed(found))
            found.extend(page)
            offset = page[-1].id

    async def by_ids(self, ids):
        if not ids:
            return []
        return await self.call(lambda: self.client.get_messages(self.entity, ids=ids))

    async def difference(self):
        # Explicit channel difference also supports a public channel without joining.
        while True:
            pts = self.store.get("channel_pts")
            result = await self.call(
                lambda pts=pts: self.client(
                    functions.updates.GetChannelDifferenceRequest(
                        channel=self.entity,
                        filter=types.ChannelMessagesFilterEmpty(),
                        pts=pts,
                        limit=100,
                    )
                )
            )
            if isinstance(result, types.updates.ChannelDifferenceTooLong):
                self.store.set("recovery_gap", True)
                for message in result.messages:
                    await self.on_message(message)
                self.store.set("channel_pts", result.dialog.pts)
                print(
                    "RECOVERY_GAP: difference too long; reconciling current messages",
                    flush=True,
                )
            else:
                for message in getattr(result, "new_messages", []):
                    await self.on_message(message)
                for update in getattr(result, "other_updates", []):
                    if isinstance(
                        update,
                        (types.UpdateNewChannelMessage, types.UpdateEditChannelMessage),
                    ):
                        await self.on_message(update.message)
                    elif isinstance(update, types.UpdateDeleteChannelMessages):
                        self.store.delete(self.entity.id, update.messages)
                self.store.set("channel_pts", result.pts)
            if result.final:
                return max(1, getattr(result, "timeout", None) or 1)

    async def download(self, message, output, size_type, max_bytes):
        def progress(current, total):
            if current > max_bytes or (total and total > max_bytes):
                raise ValueError("photo_size_limit")

        return await self.call(
            lambda: self.client.download_media(
                message, file=str(output), thumb=size_type, progress_callback=progress
            )
        )
