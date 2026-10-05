"""Five read-only checks, one photo, no album/edit/recovery workflow."""

import hashlib
import json

from .model import normalize


async def smoke_test(reader, transport):
    messages = await transport.latest()
    for message in messages:
        await reader.ingest(message)
    persisted = all(
        (
            row := reader.store.db.execute(
                "SELECT payload FROM messages WHERE channel=? AND id=?",
                (reader.channel, message.id),
            ).fetchone()
        )
        and json.loads(row["payload"])["text"] == (message.message or "")
        for message in messages
    )
    report = {
        "channel_id": str(reader.channel),
        "message_count": len(messages),
        "message_ids": [message.id for message in messages],
        "messages_with_text": sum(bool(message.message) for message in messages),
        "entity_count": sum(len(message.entities or []) for message in messages),
        "text_and_ids_persisted": persisted,
        "photo": None,
        "passed": False,
    }
    photo_message = next((message for message in messages if message.photo), None)
    if photo_message:
        available = normalize(reader.channel, photo_message)["media"]
        task = reader.store.db.execute(
            "SELECT * FROM downloads WHERE channel=? AND message_id=? AND photo_id=?",
            (reader.channel, photo_message.id, str(photo_message.photo.id)),
        ).fetchone()
        if task:
            if task["status"] != "ready":
                await reader.download_once(task=task)
            saved = reader.store.db.execute(
                "SELECT status,asset,error FROM downloads WHERE channel=? AND message_id=? AND photo_id=?",
                (reader.channel, photo_message.id, str(photo_message.photo.id)),
            ).fetchone()
            if saved["status"] == "ready":
                asset = json.loads(saved["asset"])
                with (reader.state / asset["path"]).open("rb") as source:
                    verified = hashlib.file_digest(source, "sha256").hexdigest()
                report["photo"] = {
                    **asset,
                    "hash_verified": verified == asset["sha256"],
                    "available_sizes": available["sizes"],
                    "largest_available_selected": asset["selected_size"]
                    == available["selected"],
                }
            else:
                report["photo_error"] = saved["error"]
    report["passed"] = bool(
        len(messages) == 10
        and persisted
        and report["messages_with_text"]
        and report["photo"]
        and report["photo"]["hash_verified"]
        and report["photo"]["largest_available_selected"]
        and report["photo"]["width"] > 0
        and report["photo"]["height"] > 0
    )
    # This report intentionally contains no post bodies or authentication values.
    (reader.state / "smoke_report.json").write_text(json.dumps(report, indent=2))
    print(json.dumps(report))
    return report["passed"]
