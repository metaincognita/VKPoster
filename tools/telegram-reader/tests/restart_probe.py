"""Cross-container offline recovery probe. Use a disposable test volume only."""

import argparse
import asyncio
from datetime import datetime, timezone
from pathlib import Path

from reader.service import Reader
from reader.store import Store
from tests.test_reader import FakeTransport, message


async def run(stage):
    path = Path("/state")
    store = Store(path / "offline-probe.sqlite")
    try:
        messages = (
            [message(1)] if stage == "seed" else [message(i) for i in range(1, 42)]
        )
        if stage != "seed":
            messages[0] = message(
                1, "Offline edit", edit=datetime(2026, 10, 4, 1, tzinfo=timezone.utc)
            )
        reader = Reader(store, FakeTransport(messages), path)
        reader.channel = "123"
        await reader.startup()
        summary = store.summary()
        expected = (1, 1) if stage == "seed" else (41, 42)
        assert (summary["messages"], summary["revisions"]) == expected, summary
        print({"stage": stage, **summary})
    finally:
        store.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("stage", choices=("seed", "recover", "replay"))
    asyncio.run(run(parser.parse_args().stage))
