"""Phone/login smoke tests; no network or actual credentials."""

import io
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import AsyncMock

from telethon import errors

from reader.auth import AuthInputError, authenticate, normalize_phone
from reader.service import Reader
from reader.smoke import smoke_test
from reader.store import Store
from tests.test_reader import FakeTransport, message, photo


class AuthTests(unittest.IsolatedAsyncioTestCase):
    def client(self, authorized=False):
        return SimpleNamespace(
            connect=AsyncMock(),
            is_user_authorized=AsyncMock(return_value=authorized),
            send_code_request=AsyncMock(
                return_value=SimpleNamespace(phone_code_hash="fake-hash")
            ),
            sign_in=AsyncMock(),
        )

    def test_normalize_phone_without_guessing_prefix(self):
        self.assertEqual(normalize_phone(" +1 (202) 555-0101 "), "+12025550101")
        for value in (
            "",
            "12025550101",
            "0012025550101",
            "+0 1234567",
            "+123",
            "test@example.com",
            "+1234567890123456",
        ):
            with self.subTest(value=value), self.assertRaises(AuthInputError):
                normalize_phone(value)

    async def test_env_phone_only_prompts_for_otp_and_password(self):
        client = self.client()
        client.sign_in.side_effect = [
            errors.SessionPasswordNeededError(request=None),
            None,
        ]
        prompts = []
        answers = iter(["fake-otp", "fake-password"])

        def prompt(label):
            prompts.append(label)
            return next(answers)

        await authenticate(client, "+12025550101", True, prompt)
        self.assertEqual(len(prompts), 2)
        self.assertTrue(prompts[0].startswith("OTP"))
        self.assertTrue(prompts[1].startswith("2FA"))
        client.send_code_request.assert_awaited_once_with("+12025550101")

    async def test_invalid_phone_local_and_remote_are_redacted(self):
        client = self.client()
        with self.assertRaises(AuthInputError) as result:
            await authenticate(client, "private-invalid", True)
        self.assertNotIn("private-invalid", str(result.exception))
        client.send_code_request.assert_not_awaited()
        client.send_code_request.side_effect = errors.PhoneNumberInvalidError(
            request=None
        )
        with self.assertRaises(AuthInputError) as result:
            await authenticate(client, "+12025550101", True)
        self.assertNotIn("12025550101", str(result.exception))

    async def test_authorized_session_does_not_request_otp(self):
        client = self.client(True)
        await authenticate(client, "", False)
        client.send_code_request.assert_not_awaited()

    async def test_unattended_smoke_requires_login(self):
        client = self.client()
        with self.assertRaises(AuthInputError):
            await authenticate(client, "+12025550101", False)
        client.send_code_request.assert_not_awaited()


class SmokeTests(unittest.IsolatedAsyncioTestCase):
    async def test_only_ten_messages_one_photo_no_post_bodies_in_log(self):
        with tempfile.TemporaryDirectory() as directory:
            store = Store(Path(directory) / "db")
            try:
                messages = [
                    message(i, "private test body", photo=photo(i))
                    for i in range(1, 13)
                ]
                transport = FakeTransport(messages)
                reader = Reader(store, transport, directory)
                reader.channel = "123"
                output = io.StringIO()
                with redirect_stdout(output):
                    self.assertTrue(await smoke_test(reader, transport))
                self.assertNotIn("private test body", output.getvalue())
                self.assertEqual(store.summary()["messages"], 10)
                self.assertEqual(transport.download_calls, 1)
                self.assertTrue((Path(directory) / "smoke_report.json").exists())
            finally:
                store.close()
