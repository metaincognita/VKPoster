"""Keep original Telegram bytes; inspect without recompressing."""

import hashlib
import os
import warnings
from pathlib import Path

from PIL import Image


def inspect_asset(path, max_bytes):
    path = Path(path)
    size = path.stat().st_size
    if not 0 < size <= max_bytes:
        raise ValueError("photo_size_limit")
    with warnings.catch_warnings():
        warnings.simplefilter("error", Image.DecompressionBombWarning)
        with Image.open(path) as image:
            width, height = image.size
            fmt = image.format
            image.verify()
    with path.open("rb") as source:
        digest = hashlib.file_digest(source, "sha256").hexdigest()
    return {
        "sha256": digest,
        "bytes": size,
        "width": width,
        "height": height,
        "format": fmt,
    }


def keep_asset(path, directory, max_bytes):
    info = inspect_asset(path, max_bytes)
    directory = Path(directory)
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    target = directory / info["sha256"]
    os.replace(path, target)
    os.chmod(target, 0o600)
    info["path"] = "photos/" + target.name
    return info
