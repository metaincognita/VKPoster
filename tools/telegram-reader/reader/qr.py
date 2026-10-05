"""Local, ephemeral QR display. Login tokens never enter logs or files."""

import asyncio
import getpass
import io
import time
import warnings
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from threading import Thread
from urllib.parse import urlsplit

import qrcode
from telethon import errors

from .auth import AuthInputError


def hidden_password(prompt):
    """Fail closed if Python cannot disable terminal echo; never falls back to stdin."""
    with warnings.catch_warnings():
        warnings.simplefilter("error", getpass.GetPassWarning)
        try:
            return getpass.getpass(prompt)
        except getpass.GetPassWarning:
            raise AuthInputError(
                "Скрытый ввод недоступен. Запустите login-qr в локальном интерактивном Terminal."
            ) from None


class QRDisplay:
    """Container listener published only on host loopback by the login command."""

    def __init__(self, port=8765):
        self.image = None
        display = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_GET(self):
                host = urlsplit("http://" + self.headers.get("Host", "")).hostname
                if host not in ("localhost", "127.0.0.1"):
                    self.send_error(403)
                    return
                if self.path == "/":
                    content = (
                        '<!doctype html><html lang="ru"><meta charset="utf-8">'
                        '<meta http-equiv="refresh" content="5">'
                        "<title>Telegram QR login</title>"
                        "<h1>Telegram: вход через QR</h1>"
                        "<p>Telegram → Настройки → Устройства → Подключить устройство.</p>"
                        "<p>Код обновляется автоматически. Используйте тестовый аккаунт.</p>"
                        '<img src="/qr.png" alt="Telegram login QR" width="410" height="410">'
                        "</html>"
                    ).encode()
                    mime = "text/html; charset=utf-8"
                elif self.path == "/qr.png" and display.image is not None:
                    content = display.image
                    mime = "image/png"
                else:
                    self.send_error(404)
                    return
                self.send_response(200)
                self.send_header("Content-Type", mime)
                self.send_header("Content-Length", str(len(content)))
                self.send_header("Cache-Control", "no-store")
                self.send_header("Referrer-Policy", "no-referrer")
                self.send_header("X-Content-Type-Options", "nosniff")
                self.send_header("X-Frame-Options", "DENY")
                self.send_header(
                    "Content-Security-Policy",
                    "default-src 'none'; img-src 'self'; frame-ancestors 'none'",
                )
                self.end_headers()
                self.wfile.write(content)

        self.server = ThreadingHTTPServer(("0.0.0.0", port), Handler)
        self.thread = Thread(target=self.server.serve_forever, daemon=True)

    def publish(self, url):
        image = qrcode.make(url, box_size=10, border=4)
        output = io.BytesIO()
        image.save(output, format="PNG")
        self.image = output.getvalue()

    def clear(self):
        self.image = None

    def __enter__(self):
        self.thread.start()
        return self

    def __exit__(self, *_args):
        self.clear()
        self.server.shutdown()
        self.server.server_close()
        self.thread.join()


async def authenticate_qr(
    client, display, duration=600, allow_password=False, prompt=hidden_password
):
    """Reuses authorized sessions; expires/replaces only QR tokens, never the session."""
    await client.connect()
    if await client.is_user_authorized():
        return
    deadline = time.monotonic() + duration
    qr = await client.qr_login()
    try:
        while time.monotonic() < deadline:
            wait = asyncio.create_task(
                qr.wait(timeout=min(30, deadline - time.monotonic()))
            )
            try:
                # Telethon must register UpdateLoginToken before the user can scan.
                await asyncio.sleep(0)
                display.publish(qr.url)
                print("QR ready on local page; waiting for confirmation", flush=True)
                await wait
                return
            except asyncio.TimeoutError:
                display.clear()
                if time.monotonic() < deadline:
                    await qr.recreate()
            except errors.SessionPasswordNeededError:
                display.clear()
                if not allow_password:
                    raise AuthInputError(
                        "Telegram требует 2FA. Повторите login-qr в интерактивном Terminal; пароль не передавайте в чат."
                    ) from None
                print(
                    "QR принят. Введите Telegram 2FA password только в этом Terminal.",
                    flush=True,
                )
                for attempt in range(3):
                    # Keep the MTProto event loop responsive while the user types locally.
                    password = await asyncio.to_thread(
                        prompt, "2FA password (ввод скрыт): "
                    )
                    if not password:
                        raise AuthInputError("2FA не введён; авторизация остановлена.")
                    try:
                        await client.sign_in(password=password)
                        return
                    except errors.PasswordHashInvalidError:
                        if attempt == 2:
                            raise AuthInputError(
                                "2FA не принят; авторизация остановлена."
                            ) from None
                        print(
                            "2FA не принят. Повторите скрытый ввод в Terminal.",
                            flush=True,
                        )
                    finally:
                        del password
            finally:
                if not wait.done():
                    wait.cancel()
                await asyncio.gather(wait, return_exceptions=True)
        raise AuthInputError("QR истёк; авторизация остановлена. Повторите login-qr.")
    finally:
        display.clear()
