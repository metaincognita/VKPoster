"""Local interactive entry point; never logs credentials or post bodies."""

import argparse
import asyncio
import fcntl
import json
import logging
import os
import signal
from pathlib import Path

from .auth import AuthInputError, authenticate
from .integration import run_sources
from .model import channel_username
from .service import Reader
from .smoke import smoke_test
from .store import Store
from .transport import TelegramTransport


async def live(args, state, store):
    api_id = int(os.environ.get("TELEGRAM_API_ID") or "0")
    api_hash = os.environ.get("TELEGRAM_API_HASH") or ""
    if (
        api_id <= 0
        or len(api_hash) != 32
        or any(c not in "0123456789abcdefABCDEF" for c in api_hash)
    ):
        raise ValueError("Invalid API credentials")
    reader = Reader(store, None, state)
    transport = TelegramTransport(
        state / "telegram",
        api_id,
        api_hash,
        store,
        reader.ingest,
        enable_updates=args.command == "run",
    )
    reader.transport = transport
    stop = asyncio.Event()
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGINT, signal.SIGTERM):
        loop.add_signal_handler(sig, stop.set)
    tasks = []
    try:
        await authenticate(
            transport.client,
            os.environ.get("TELEGRAM_PHONE", ""),
            interactive=args.command == "login",
        )
        me = await transport.call(lambda: transport.client.get_me())
        if me.bot:
            raise ValueError("Use a test user account, not a bot")
        saved_account = store.get("account_id")
        if saved_account is not None and saved_account != str(me.id):
            raise ValueError("State belongs to a different test account")
        store.set("account_id", str(me.id))
        if args.command == "login":
            print("Persistent user session ready")
            return
        value = (
            args.channel
            or os.environ.get("TELEGRAM_TEST_CHANNEL")
            or input("Controlled public channel URL: ")
        )
        entity = await transport.resolve(
            channel_username(value), initialize_updates=args.command == "run"
        )
        reader.channel = str(entity.id)
        print(f"resolved channel_id={entity.id}")
        if args.command == "smoke":
            if not await smoke_test(reader, transport):
                raise AuthInputError(
                    "Smoke не завершён: нужны 10 сообщений с текстом и хотя бы одна фотография среди них, либо требуется проверить ошибку загрузки."
                )
            return
        await reader.startup()
        await transport.call(lambda: transport.client.catch_up())
        await transport.difference()
        if args.once:
            while await reader.download_once():
                pass
            print(json.dumps(store.summary()))
            return
        tasks = [
            asyncio.create_task(reader.photo_loop()),
            asyncio.create_task(reader.recovery_loop()),
            asyncio.create_task(reader.album_loop()),
            asyncio.create_task(stop.wait()),
        ]
        print("Read-only monitoring active; Ctrl+C to stop")
        done, _ = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
        for task in done:
            task.result()
    finally:
        for task in tasks:
            task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)
        await transport.client.disconnect()


def main():
    # SDK diagnostics can expose request parameters; only our redacted events log.
    sdk_logger = logging.getLogger("telethon")
    sdk_logger.handlers = [logging.NullHandler()]
    sdk_logger.propagate = False
    parser = argparse.ArgumentParser(
        description="Изолированный read-only Telegram spike"
    )
    parser.add_argument("command", choices=("login", "smoke", "run", "status", "sources"))
    parser.add_argument("--channel")
    parser.add_argument("--source-id", help="Restrict a controlled smoke run to one enabled Source")
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--state", default="/state")
    args = parser.parse_args()
    os.umask(0o077)
    state = Path(args.state)
    state.mkdir(mode=0o700, parents=True, exist_ok=True)
    lock = (state / "reader.lock").open("a")
    store = None
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        store = Store(state / "reader.sqlite")
        if args.command == "status":
            print(
                json.dumps(
                    {
                        **store.summary(),
                        "channel_id": store.get("channel_id"),
                        "recovery_gap": store.get("recovery_gap", False),
                    }
                )
            )
        elif args.command == "sources":
            asyncio.run(run_sources(args, state, store))
        else:
            asyncio.run(live(args, state, store))
    except (KeyboardInterrupt, EOFError):
        print("Stopped")
    except AuthInputError as error:
        print(str(error))
        raise SystemExit(1) from None
    # Top-level boundary redacts SDK exceptions, including unexpected failures.
    except Exception as error:  # noqa: BLE001
        print(
            f"Reader stopped: {type(error).__name__}. Check local configuration/account; credentials are not logged."
        )
        raise SystemExit(1) from None
    finally:
        if store:
            store.close()
        lock.close()


if __name__ == "__main__":
    main()
