"""Approved-job photo delivery with actual encoded fixtures and durable restart/ACK recovery."""

import base64
import contextlib
import io
import os
import json
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import AsyncMock

from reader.image_delivery import ImageDelivery
from reader.integration import InternalAPI
from reader.service import Reader
from reader.store import Store
from tests.test_reader import FakeTransport, message, photo


class ImageDeliveryTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.state = Path(self.tmp.name)
        self.now = [100]
        self.store = Store(self.state / "reader.sqlite", clock=lambda: self.now[0])
        self.reader = Reader(
            self.store,
            FakeTransport([message(10, photo=photo(77)), message(11, photo=photo(78))]),
            self.state,
        )
        self.reader.channel = "123"
        self.box = ImageDelivery(self.store)
        self.jobs = [
            dict(
                job_id="job-" + str(mid),
                source_id="active",
                peer_id="123",
                message_id=mid,
                photo_id=str(pid),
                max_bytes=16777216,
            )
            for mid, pid in [(10, 77), (11, 78)]
        ]
        self.api = SimpleNamespace(
            image_jobs=AsyncMock(return_value=self.jobs), send_image=AsyncMock()
        )
        self.readers = {"active": ("channel", self.reader)}

    async def asyncTearDown(self):
        self.store.close()
        self.tmp.cleanup()

    async def test_two_photos_largest_size_hash_and_no_unapproved_download(self):
        self.assertEqual(await self.box.tick(self.api, {}), 0)
        self.assertEqual(self.reader.transport.download_calls, 0)
        self.assertEqual(await self.box.tick(self.api, self.readers), 2)
        self.assertEqual(self.reader.transport.download_calls, 2)
        for call in self.api.send_image.call_args_list:
            payload = call.args[0]
            self.assertNotIn("path", payload)
            self.assertNotIn("file_reference", payload)
            self.assertTrue(base64.b64decode(payload["data"]).startswith(b"\xff\xd8"))
            self.assertEqual(len(payload["sha256"]), 64)
        asset = self.store.db.execute("SELECT asset FROM downloads LIMIT 1").fetchone()[
            0
        ]
        self.assertIn('"type": "w"', asset)
        self.assertIn('"width": 2560', asset)
        self.assertEqual(await self.box.tick(self.api, self.readers), 0)
        self.assertEqual(self.reader.transport.download_calls, 2)

    async def test_ack_loss_restart_reuses_cached_bytes_no_secrets_in_logs(self):
        self.api.send_image.side_effect = ValueError("full-content-private-api-secret")
        logs = io.StringIO()
        with contextlib.redirect_stdout(logs):
            self.assertEqual(await self.box.tick(self.api, self.readers), 0)
        self.assertNotIn("full-content", logs.getvalue())
        self.assertNotIn("private-api-secret", logs.getvalue())
        self.assertEqual(self.reader.transport.download_calls, 2)
        self.store.close()
        self.store = Store(self.state / "reader.sqlite", clock=lambda: self.now[0])
        self.reader.store = self.store
        self.box = ImageDelivery(self.store)
        self.now[0] += 5
        self.api.image_jobs.return_value = []  # PHP committed before ACK was lost.
        self.api.send_image.side_effect = None
        self.assertEqual(
            await self.box.tick(self.api, {}), 0
        )  # Disabled sources stay idle.
        self.assertEqual(await self.box.tick(self.api, self.readers), 2)
        self.assertEqual(self.reader.transport.download_calls, 2)

    async def test_rejected_job_no_longer_offered_does_not_download(self):
        with self.store.db:
            self.store.db.execute(
                "INSERT INTO image_outbox(job_id,source_id,job) VALUES(?,?,?)",
                (self.jobs[0]["job_id"], "active", json.dumps(self.jobs[0])),
            )
        self.api.image_jobs.return_value = []
        self.assertEqual(await self.box.tick(self.api, self.readers), 0)
        self.assertEqual(self.reader.transport.download_calls, 0)
        self.api.send_image.assert_not_called()

    async def test_missing_cache_recovers_without_marking_success(self):
        self.api.image_jobs.return_value = [self.jobs[0]]
        self.api.send_image.side_effect = ValueError("ack_lost")
        await self.box.tick(self.api, self.readers)
        result = json.loads(
            self.store.db.execute("SELECT result FROM image_outbox").fetchone()[0]
        )
        Path(result["path"]).unlink()
        self.now[0] += 5
        self.api.send_image.side_effect = None
        self.assertEqual(await self.box.tick(self.api, self.readers), 0)
        self.now[0] += 10
        self.assertEqual(await self.box.tick(self.api, self.readers), 1)
        self.assertEqual(self.reader.transport.download_calls, 2)

    async def test_replaced_photo_safe_terminal_failure(self):
        self.jobs[0]["photo_id"] = "999"
        self.api.image_jobs.return_value = [self.jobs[0]]
        self.assertEqual(await self.box.tick(self.api, self.readers), 1)
        payload = self.api.send_image.call_args.args[0]
        self.assertEqual(payload["error"], "photo_unavailable")
        self.assertNotIn("data", payload)
        self.assertEqual(self.reader.transport.download_calls, 0)

    async def test_internal_api_validates_image_ack_and_job_version(self):
        api = InternalAPI(
            "http://localhost:8080", "fixture-secret-not-a-real-credential-12345"
        )
        api.request = lambda *args: {"version": 1, "jobs": []}
        self.assertEqual(await api.image_jobs(), [])
        api.request = lambda *args: {"ack": False, "job_id": "job-10"}
        with self.assertRaises(ValueError):
            await api.send_image(self.jobs[0])
        api.request = lambda *args: {"ack": True, "job_id": "job-10"}
        await api.send_image(self.jobs[0])

    async def test_requests_only_available_sources_for_fair_delivery(self):
        await self.box.tick(self.api, self.readers)
        self.api.image_jobs.assert_awaited_once_with(list(self.readers))


@unittest.skipUnless(
    os.environ.get("TEST_INTERNAL_URL"), "requires isolated VKPoster HTTP test service"
)
class PHPImageContractTests(unittest.IsolatedAsyncioTestCase):
    async def test_image_bytes_commit_ack_duplicate_multiple_sources_and_restart(self):
        api = InternalAPI(
            os.environ["TEST_INTERNAL_URL"], os.environ["TEST_READER_SECRET"]
        )
        jobs = await api.image_jobs()
        self.assertEqual(len(jobs), 4)
        sources = await api.sources()
        with tempfile.TemporaryDirectory() as tmp:
            state = Path(tmp)
            store = Store(state / "reader.sqlite")
            readers = {}
            try:
                transport = FakeTransport(
                    [message(10, photo=photo(10)), message(11, photo=photo(11))]
                )
                for source in sources:
                    reader = Reader(store, transport, state)
                    reader.channel = "123"
                    readers[source["id"]] = (source["username"], reader)
                box = ImageDelivery(store)
                self.assertEqual(await box.tick(api, readers), 4)
                self.assertEqual(
                    transport.download_calls, 2
                )  # Same Telegram channel in two workspaces/jobs uses immutable cache.
                row = store.db.execute(
                    "SELECT job,result FROM image_outbox LIMIT 1"
                ).fetchone()
                result = json.loads(row["result"])
                path = result.pop("path")
                result["data"] = base64.b64encode(Path(path).read_bytes()).decode()
                await api.send_image(
                    result
                )  # Ambiguous/lost ACK replay: PHP must retain one original.
                self.assertEqual(await api.image_jobs(), [])
                store.close()
                store = Store(state / "reader.sqlite")
                box = ImageDelivery(store)
                self.assertEqual(await box.tick(api, readers), 0)
            finally:
                store.close()
