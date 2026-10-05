"""Explicit user login with local phone normalization and redacted output."""

import getpass
import re

from telethon import errors


class AuthInputError(ValueError):
    """Safe static explanation; never includes credentials or SDK messages."""


def normalize_phone(value):
    # Accept familiar display separators, but never guess a country prefix.
    compact = re.sub(r"[\s().-]", "", value.strip())
    if not re.fullmatch(r"\+[1-9][0-9]{6,14}", compact):
        raise AuthInputError(
            "TELEGRAM_PHONE должен содержать международный номер с + и кодом страны."
        )
    return compact


async def authenticate(client, configured_phone, interactive, prompt=getpass.getpass):
    await client.connect()
    if await client.is_user_authorized():
        return
    if not interactive:
        raise AuthInputError(
            "Session не авторизована. Выполните команду login в Terminal."
        )
    if not configured_phone:
        raise AuthInputError("Добавьте TELEGRAM_PHONE в локальный .env.")
    phone = normalize_phone(configured_phone)
    try:
        sent = await client.send_code_request(phone)
    except errors.PhoneNumberInvalidError:
        raise AuthInputError(
            "Telegram отклонил TELEGRAM_PHONE. Проверьте номер тестового аккаунта и международный формат."
        ) from None
    for attempt in range(3):
        code = prompt("OTP (ввод скрыт): ").strip()
        if not code:
            raise AuthInputError("OTP не введён; авторизация остановлена.")
        try:
            await client.sign_in(
                phone=phone, code=code, phone_code_hash=sent.phone_code_hash
            )
            return
        except errors.SessionPasswordNeededError:
            for password_attempt in range(3):
                password = prompt("2FA password (ввод скрыт): ")
                if not password:
                    raise AuthInputError("2FA не введён; авторизация остановлена.")
                try:
                    await client.sign_in(password=password)
                    return
                except errors.PasswordHashInvalidError:
                    if password_attempt == 2:
                        raise AuthInputError(
                            "2FA не принят; авторизация остановлена."
                        ) from None
                    print("2FA не принят. Повторите ввод в Terminal.")
        except errors.PhoneCodeInvalidError:
            if attempt == 2:
                raise AuthInputError(
                    "OTP не принят; авторизация остановлена."
                ) from None
            print("OTP не принят. Повторите ввод в Terminal.")
        except errors.PhoneCodeExpiredError:
            raise AuthInputError(
                "OTP истёк. Повторите login для получения нового кода."
            ) from None
