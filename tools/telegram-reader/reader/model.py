"""Normalize Telegram messages without mutating their content."""

import hashlib
import json
import re
from datetime import timezone
from urllib.parse import urlsplit


def channel_username(value):
    value = value.strip()
    if value.startswith("@"):
        value = value[1:]
    elif "://" in value:
        url = urlsplit(value)
        if (
            url.scheme != "https"
            or url.hostname != "t.me"
            or url.port not in (None, 443)
            or url.username
            or url.password
            or url.query
            or url.fragment
        ):
            raise ValueError("Use https://t.me/public_username")
        value = url.path.strip("/")
    if not re.fullmatch(r"[A-Za-z][A-Za-z0-9_]{3,31}", value):
        raise ValueError("A public channel username is required")
    return value


def serializable(value):
    if hasattr(value, "to_dict"):
        return serializable(value.to_dict())
    if hasattr(value, "isoformat"):
        return value.astimezone(timezone.utc).isoformat()
    if isinstance(value, bytes):
        # Never export access hashes, file references or authorization bytes.
        return None
    if isinstance(value, dict):
        return {
            k: serializable(v)
            for k, v in value.items()
            if k not in ("access_hash", "file_reference")
        }
    if isinstance(value, (list, tuple)):
        return [serializable(v) for v in value]
    return value


def photo_candidates(photo):
    candidates = []
    for size in photo.sizes:
        kind = type(size).__name__
        if kind not in ("PhotoSize", "PhotoSizeProgressive", "PhotoCachedSize"):
            continue
        count = getattr(size, "size", None)
        if count is None:
            count = max(
                getattr(size, "sizes", []) or [len(getattr(size, "bytes", b""))]
            )
        candidates.append(
            {"type": size.type, "width": size.w, "height": size.h, "bytes": count}
        )
    return sorted(candidates, key=lambda s: (s["width"] * s["height"], s["bytes"]))


def normalize(channel_id, message):
    photo = getattr(message, "photo", None)
    document = getattr(message, "document", None)
    media = None
    if photo:
        sizes = photo_candidates(photo)
        media = {
            "kind": "photo",
            "telegram_id": str(photo.id),
            "sizes": sizes,
            "selected": sizes[-1] if sizes else None,
        }
    elif document:
        media = {
            "kind": "document",
            "telegram_id": str(document.id),
            "mime": document.mime_type,
            "bytes": document.size,
        }
    elif getattr(message, "media", None):
        media = {"kind": type(message.media).__name__}
    payload = {
        "channel_id": str(channel_id),
        "message_id": message.id,
        "date": serializable(message.date),
        "edit_date": serializable(message.edit_date),
        "text": message.message or "",
        "entities": serializable(message.entities or []),
        "grouped_id": str(message.grouped_id)
        if message.grouped_id is not None
        else None,
        "forward": serializable(message.fwd_from),
        "media": media,
    }
    payload["content_hash"] = hashlib.sha256(
        json.dumps(payload, sort_keys=True, ensure_ascii=False).encode()
    ).hexdigest()
    return payload
