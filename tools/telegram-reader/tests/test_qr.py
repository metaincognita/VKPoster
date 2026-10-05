"""QR authorization tests with SDK fakes; no real Telegram credentials."""

import asyncio
import io
import unittest
import getpass
import threading
import warnings
from contextlib import redirect_stdout
from unittest.mock import AsyncMock, Mock, patch
from urllib.error import HTTPError
from urllib.request import Request, urlopen

from telethon import errors

from reader.auth import AuthInputError
from reader.qr import QRDisplay, authenticate_qr, hidden_password


class QRTests(unittest.IsolatedAsyncioTestCase):
    def client(self, authorized=False):
        qr = Mock(url="tg://login?token=synthetic-test-only")
        qr.wait = AsyncMock()
        qr.recreate = AsyncMock()
        client = Mock()
        client.connect = AsyncMock()
        client.is_user_authorized = AsyncMock(return_value=authorized)
        client.qr_login = AsyncMock(return_value=qr)
        client.sign_in = AsyncMock()
        return client, qr

    async def test_authorized_session_skips_qr_and_sign_in(self):
        client, _ = self.client(True)
        display = Mock()
        await authenticate_qr(client, display)
        client.qr_login.assert_not_awaited()
        client.sign_in.assert_not_awaited()
        display.publish.assert_not_called()

    async def test_wait_installed_before_display_and_no_token_in_logs(self):
        client, qr = self.client()
        ready = []

        async def wait(**_kwargs):
            ready.append("waiting")
            await asyncio.sleep(0)

        qr.wait.side_effect = wait
        display = Mock()
        display.publish.side_effect = lambda _url: self.assertEqual(ready, ["waiting"])
        output = io.StringIO()
        with redirect_stdout(output):
            await authenticate_qr(client, display)
        self.assertNotIn("synthetic-test-only", output.getvalue())
        self.assertNotIn("tg://", output.getvalue())
        display.clear.assert_called()
        client.sign_in.assert_not_awaited()

    async def test_expired_token_refresh_and_cleanup(self):
        client, qr = self.client()
        qr.wait.side_effect = [asyncio.TimeoutError(), None]
        display = Mock()
        await authenticate_qr(client, display)
        qr.recreate.assert_awaited_once()
        self.assertEqual(display.publish.call_count, 2)
        display.clear.assert_called()

    async def test_timeout_and_render_failure_clear_ephemeral_image(self):
        client, qr = self.client()
        display = Mock()
        with self.assertRaises(AuthInputError):
            await authenticate_qr(client, display, duration=0)
        display.clear.assert_called()
        display.publish.side_effect = RuntimeError("renderer failed")
        with self.assertRaises(RuntimeError):
            await authenticate_qr(client, display)
        display.clear.assert_called()

    async def test_2fa_required_noninteractive_stops_without_prompt(self):
        client, qr = self.client()
        qr.wait.side_effect = errors.SessionPasswordNeededError(None)
        prompt = Mock()
        with self.assertRaises(AuthInputError):
            await authenticate_qr(client, Mock(), prompt=prompt)
        prompt.assert_not_called()
        client.sign_in.assert_not_awaited()

    async def test_2fa_hidden_prompt_only_when_required(self):
        client, qr = self.client()
        qr.wait.side_effect = errors.SessionPasswordNeededError(None)
        prompt = Mock(return_value="synthetic-password")
        await authenticate_qr(client, Mock(), allow_password=True, prompt=prompt)
        client.sign_in.assert_awaited_once_with(password="synthetic-password")
        client.sign_in.side_effect = errors.PasswordHashInvalidError(None)
        with self.assertRaises(AuthInputError) as caught:
            await authenticate_qr(client, Mock(), allow_password=True, prompt=prompt)
        self.assertNotIn("synthetic-password", str(caught.exception))
        with self.assertRaises(AuthInputError):
            await authenticate_qr(
                client, Mock(), allow_password=True, prompt=lambda _: ""
            )

    async def test_password_retry_uses_same_qr_and_never_logs_password(self):
        client, qr = self.client()
        qr.wait.side_effect = errors.SessionPasswordNeededError(None)
        client.sign_in.side_effect = [errors.PasswordHashInvalidError(None), None]
        prompt = Mock(side_effect=["synthetic-wrong", "synthetic-correct"])
        output = io.StringIO()
        with redirect_stdout(output):
            await authenticate_qr(client, Mock(), allow_password=True, prompt=prompt)
        self.assertEqual(prompt.call_count, 2)
        qr.recreate.assert_not_awaited()
        client.qr_login.assert_awaited_once()
        self.assertNotIn("synthetic-wrong", output.getvalue())
        self.assertNotIn("synthetic-correct", output.getvalue())

    async def test_password_wait_keeps_event_loop_responsive(self):
        client, qr = self.client()
        qr.wait.side_effect = errors.SessionPasswordNeededError(None)
        heartbeat = threading.Event()
        main_thread = threading.current_thread()

        async def tick():
            await asyncio.sleep(0.02)
            heartbeat.set()

        def prompt(_label):
            self.assertIsNot(threading.current_thread(), main_thread)
            self.assertTrue(heartbeat.wait(1))
            return "synthetic-password"

        task = asyncio.create_task(tick())
        try:
            await authenticate_qr(client, Mock(), allow_password=True, prompt=prompt)
        finally:
            await task


class HiddenPasswordTests(unittest.TestCase):
    def test_unsafe_echo_fallback_is_refused(self):
        def unsafe(_label):
            warnings.warn("echo unavailable", getpass.GetPassWarning)
            self.fail("visible input must never be reached")

        with patch("reader.qr.getpass.getpass", side_effect=unsafe):
            with self.assertRaises(AuthInputError):
                hidden_password("Password: ")

    def test_terminal_prompt_value_is_not_printed(self):
        output = io.StringIO()
        with patch("reader.qr.getpass.getpass", return_value="synthetic-password"):
            with redirect_stdout(output):
                self.assertEqual(hidden_password("Password: "), "synthetic-password")
        self.assertEqual(output.getvalue(), "")


class QRDisplayTests(unittest.TestCase):
    def test_png_headers_no_tokens_on_page_host_guard_and_cleanup(self):
        with QRDisplay(0) as display:
            display.publish("tg://login?token=synthetic-test-only")
            base = "http://127.0.0.1:" + str(display.server.server_port)
            with urlopen(base + "/qr.png") as result:
                self.assertTrue(result.read().startswith(b"\x89PNG"))
                self.assertEqual(result.headers["Cache-Control"], "no-store")
            with urlopen(base) as result:
                self.assertNotIn(b"synthetic-test-only", result.read())
                self.assertEqual(result.headers["X-Frame-Options"], "DENY")
            with self.assertRaises(HTTPError) as caught:
                urlopen(Request(base, headers={"Host": "attacker.example"}))
            self.assertEqual(caught.exception.code, 403)
            display.clear()
            with self.assertRaises(HTTPError):
                urlopen(base + "/qr.png")
        self.assertIsNone(display.image)
