"""Message persistence, album boundary recovery and independent photo work."""

import asyncio
from pathlib import Path

from telethon import errors

from .media import keep_asset
from .model import normalize


class Reader:
    def __init__(self, store, transport, state, max_photo_bytes=50 * 1024 * 1024):
        self.store = store
        self.transport = transport
        self.state = Path(state)
        self.channel = None
        self.max_photo_bytes = max_photo_bytes
        self.expanded_groups = set()

    async def ingest(self, message):
        if (
            self.channel is None
            or not getattr(message, "date", None)
            or not hasattr(message, "message")
        ):
            return
        payload = normalize(self.channel, message)
        changed = self.store.upsert(payload)
        if changed:
            print(
                f"message_saved id={message.id} edited={bool(message.edit_date)} album={bool(message.grouped_id)}",
                flush=True,
            )

    async def startup(self):
        cursor = self.store.high_water(self.channel)
        latest = await self.transport.latest()
        await self.apply(latest)
        if cursor:
            await self.apply(await self.transport.newer(cursor))
        await self.reconcile()

    async def apply(self, messages):
        for message in messages:
            if message:
                await self.ingest(message)
        # Include neighboring messages when a page cuts through an album.
        for message in messages:
            group = getattr(message, "grouped_id", None)
            if group is None or group in self.expanded_groups:
                continue
            self.expanded_groups.add(group)
            ids = list(range(max(1, message.id - 10), message.id + 11))
            for neighbor in await self.transport.by_ids(ids):
                if neighbor and getattr(neighbor, "grouped_id", None) == group:
                    await self.ingest(neighbor)

    async def reconcile(self):
        ids = self.store.recent_ids(self.channel)
        messages = await self.transport.by_ids(ids)
        for mid, message in zip(ids, messages):
            if message and getattr(message, "date", None):
                await self.ingest(message)
            else:
                self.store.delete(self.channel, [mid])

    async def download_once(self, task=None):
        task = task if task is not None else self.store.pending_download(self.channel)
        if not task:
            return False
        temporary = self.state / "photos" / "download.part.jpg"
        temporary.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        try:
            messages = await self.transport.by_ids([task["message_id"]])
            message = messages[0] if messages else None
            if (
                not message
                or not message.photo
                or str(message.photo.id) != task["photo_id"]
            ):
                self.store.fail_download(
                    task, "photo_unavailable_or_replaced", terminal=True
                )
                return True
            payload = normalize(self.channel, message)
            selected = payload["media"]["selected"]
            if selected["bytes"] > self.max_photo_bytes:
                raise ValueError("photo_size_limit")
            result = await self.transport.download(
                message, temporary, selected["type"], self.max_photo_bytes
            )
            if not result:
                raise ValueError("photo_download_empty")
            asset = keep_asset(result, temporary.parent, self.max_photo_bytes)
            asset.update(
                {
                    "telegram_photo_id": task["photo_id"],
                    "channel_id": task["channel"],
                    "message_id": task["message_id"],
                    "selected_size": selected,
                }
            )
            self.store.finish_download(task, asset)
            print(
                f"photo_saved message_id={task['message_id']} dimensions={asset['width']}x{asset['height']}",
                flush=True,
            )
        except errors.UnauthorizedError:
            raise
        except (OSError, ValueError, errors.RPCError) as error:
            # Persist only the exception class: SDK messages can contain private data.
            self.store.fail_download(
                task, type(error).__name__, terminal=isinstance(error, ValueError)
            )
        finally:
            temporary.unlink(missing_ok=True)
        return True

    async def photo_loop(self):
        while True:
            await self.download_once()
            await asyncio.sleep(1)

    async def album_loop(self):
        while True:
            self.store.finalize_albums()
            await asyncio.sleep(1)

    async def recovery_loop(self):
        count = 0
        while True:
            try:
                timeout = await self.transport.difference()
                if count % 5 == 0:
                    cursor = self.store.high_water(self.channel)
                    await self.apply(await self.transport.newer(cursor))
                    await self.reconcile()
                count += 1
                await asyncio.sleep(timeout)
            except (errors.UnauthorizedError, errors.ChannelPrivateError):
                raise
            except (OSError, errors.RPCError) as error:
                print(f"recovery_error={type(error).__name__}", flush=True)
                await asyncio.sleep(30)
