# Telegram reader: изолированный read-only spike

Прототип для одного тестового аккаунта и одного контролируемого публичного
канала. Не подключается к PHP, MySQL, PostService и publishing pipeline VKPoster.
Нет публикации, join, AI, Content Selection, enhancement или image search.

## Запуск только через Docker

```bash
cd /Users/A/Projects/VKPoster/tools/telegram-reader
docker compose build
cp .env.example .env
chmod 600 .env
```

Впишите локально `TELEGRAM_API_ID`, `TELEGRAM_API_HASH` (собственное приложение
на https://my.telegram.org), `TELEGRAM_PHONE` (международный номер с `+` и кодом
страны) и `TELEGRAM_TEST_CHANNEL=https://t.me/ВАШ_КАНАЛ`.
Не присылайте секреты в чат. Credentials задаются только локально в .env.
Используйте только тестовый аккаунт и разрешённый тестовый контент.

## Минимальный live smoke

После заполнения .env достаточно одного запуска login:

```bash
docker compose run --rm reader login
```

Номер берётся из `TELEGRAM_PHONE`; пробелы, дефисы и скобки удаляются, код страны
не угадывается. Неверный локальный формат отклоняется до запроса OTP. Если Telegram
всё равно отвергает номер, выводится безопасная инструкция проверить номер, без
его значения. OTP и 2FA вводятся вручную в Terminal через getpass; SDK sign-in
banner и diagnostics отключены. Телефон не запрашивается интерактивно.

После `Persistent user session ready` запускается автоматическая проверка:

```bash
docker compose run --rm -T reader smoke
```

Она resolve-ит канал, получает ровно последние 10 messages, сохраняет text/entities
и Telegram IDs, скачивает только одну фотографию среди этих сообщений в максимальной
доступной photo size и проверяет dimensions/SHA-256. Нет live loop, album assembly,
edit/recovery testing. Полный текст не выводится; безопасный отчёт сохраняется в
`/state/smoke_report.json`. Успех — `passed: true`. Если фотографий среди последних
10 сообщений нет, добавьте одну тестовую фотографию с caption в контролируемый
канал. Без авторизованной session команда smoke ничего не запрашивает интерактивно
и не отправляет код входа. При успехе дальнейшее тестирование останавливается.

## Расширенные команды (не нужны для минимального smoke)

```bash
docker compose run --rm reader login
docker compose run --rm reader run
# Либо явная ссылка:
docker compose run --rm reader run --channel https://t.me/ВАШ_КАНАЛ
# Одна загрузка истории и фото без постоянного мониторинга:
docker compose run --rm reader run --once
docker compose run --rm reader status
```

Код и 2FA вводятся скрыто в локальном терминале. Session сохраняется
в именованном Docker volume. Ctrl+C завершает reader; следующий запуск использует
session и SQLite. Одновременно допускается один процесс на volume.

Для смены канала создайте отдельный Compose project/volume (`docker compose -p
reader-second ...`). Существующее состояние не переиспользуется для другого ID.

## Хранение и гарантии

- `/state/telegram.session`: Telethon SQLiteSession, секрет авторизации.
- `/state/reader.sqlite`: сообщения, revisions, albums, downloads, recovery state.
- `/state/photos/<sha256>`: исходные байты выбранного Telegram-файла.
- Ключ сообщения `(channel_id, message_id)`; grouped IDs и Telegram long IDs — строки.
- Entities сохраняют исходные UTF-16 offsets. Текст не преобразуется.
- Повторное содержимое не создаёт новую revision; edit сохраняет прежний ID.
- Фото скачиваются отдельно от приёма сообщений. Сохраняются SHA-256, dimensions,
  выбранный Telegram size type, photo ID и message ID. Максимум 50 MiB на фото.
- Выбирается максимальная полноценная photo size, включая progressive sizes;
  stripped/path thumbnails исключены. Файл до загрузки автором в Telegram
  не гарантированно доступен. Фото, видео и documents не смешиваются.
- Видео/document metadata сохраняются, но их файлы этот spike не скачивает.
- Альбомы собираются по grouped ID в SQLite; quiet window 5 s, статус `quiescent`
  означает отсутствие новых частей, а не доказанную полноту. Поздние части и edits
  обновляют существующую группу. На границе backfill читаются соседние ±10 IDs.
- Первый запуск получает последние 10 **messages**, а не десять альбомов.
- После restart все новые IDs после сохранённого high-water дочитываются страницами.
  Последние 100 сохранённых сообщений сверяются для восстановления текущих edits.
- Дополнительно используется persistent channel pts и GetChannelDifference,
  включая short polling публичного канала без вступления. Это один канал;
  ограничения массового short polling здесь не исследуются.
- Pts сохраняется после применения difference. Повтор после crash безопасен.
  `ChannelDifferenceTooLong` оставляет durable `recovery_gap`; сверка не обещает
  восстановить каждую промежуточную правку/удалённое до чтения сообщение.
- FLOOD_WAIT сохраняется в SQLite; reader ждёт указанный срок, не обходит лимиты.
- Недоступный/заменённый media имеет terminal status; остальные ошибки загрузки
  повторяются с ограничением попыток. Полное исчерпание попыток требует разбора.

SQLiteSession не зашифрована самим прототипом. Используйте защищённый Docker host,
шифрование диска, не экспортируйте volume и не используйте личный основной аккаунт.
Прототип не имеет публичных портов; контейнер non-root и root filesystem read-only.
Логи содержат только IDs, статусы и классы ошибок, не текст постов/credentials.
Сохранённые raw posts и media содержат тестовый контент и имеют отдельный lifecycle.

## Автономные проверки

```bash
docker compose build
docker compose run --rm --no-deps --entrypoint python reader -m unittest discover -s tests -v
# Полностью без сети:
docker run --rm --network none --read-only --tmpfs /tmp --entrypoint python \
  vkposter-telegram-reader-spike-reader -m unittest discover -s tests -v
```

Тесты используют настоящие Telethon TL objects и Fake transport. Они не подключаются
к Telegram. Simulated offline/restart не заменяет живую проверку MTProto.

Отдельный cross-container probe запускается только на disposable test volume:

```bash
docker volume create vkposter-reader-offline-verification
for stage in seed recover replay; do
  docker run --rm --network none --read-only --tmpfs /tmp \
    --mount type=volume,source=vkposter-reader-offline-verification,target=/state \
    --entrypoint python vkposter-telegram-reader-spike-reader \
    -m tests.restart_probe "$stage" || break
done
# Удаляет только синтетические данные этого probe:
docker volume rm vkposter-reader-offline-verification
```

## Фактически проверено 2026-10-04

- Docker build и CLI help/status, uid 1000, Telethon 1.45.0, Pillow 12.3.0.
- 23 offline tests прошли с `--network none`.
- Три отдельных контейнера: seed = 1 message/1 revision; recover = 41/42;
  replay = 41/42. Повторный запуск не добавил дублей.
- pip-audit: No known vulnerabilities found для закреплённых runtime dependencies.
- Ruff check/format выполнены в Docker.
- Live login, чтение настоящего канала, Telegram edits/photos/album и реальный
  offline catch-up **не проверены**: тестовый аккаунт и канал не предоставлены.
- Основные PHP checks не запускались: PHP, migrations и publishing не изменены.

## Живая проверка владельцем

1. Подготовьте в контролируемом канале текст с entities/emoji, фото и альбом.
2. Запустите reader; убедитесь в resolved ID, message_saved и photo_saved.
3. Измените тестовый пост вручную в официальном клиенте. Проверьте `edited=True`.
4. Остановите reader, вручную добавьте сообщения и измените старый пост.
5. Запустите снова: новые IDs сохраняются, edit добавляет revision, повторные
   неизменённые сообщения не увеличивают revisions.
6. Повторите restart без изменений; сравните `status` и SQLite IDs/hashes.
7. Для проверки SQLite без изменения данных:

```bash
docker compose run --rm --entrypoint python reader -c 'import sqlite3; d=sqlite3.connect("file:/state/reader.sqlite?mode=ro", uri=True); print(d.execute("select channel,id,grouped_id,content_hash from messages order by id").fetchall()); print(d.execute("select grouped_id,status,members from albums").fetchall()); print(d.execute("select message_id,status,asset,error from downloads").fetchall())'
```

Не создавайте намеренный flooding. Для fresh session требуется интерактивный login;
CLI auth работает только с user account. Неверные credentials не выводятся в лог.

## Удаление

Каталог tools/telegram-reader и ADR можно удалить без изменений приложения.
`docker compose down` сохраняет volume. `docker compose down -v` **удаляет session,
SQLite и media** — выполняйте только если эти данные больше не нужны.

## Основания

- Telethon stable: https://docs.telethon.dev/en/stable/ (1.45.0, проверено 2026-10-04).
- Sessions: https://docs.telethon.dev/en/stable/concepts/sessions.html.
- Updates: https://core.telegram.org/api/updates.
- Files: https://core.telegram.org/api/files.
- Условия: https://core.telegram.org/api/terms.

Зависимости фиксированы и имеют PyPI hashes; base image фиксирован digest.
Результаты проверок и фактические ограничения текущего запуска см. в отчёте чата.

## Sources integration (substage 2.2)

The standalone `login`, `smoke`, `run` and `status` commands remain available. Compose now defaults to `sources`, which polls VKPoster every 10 seconds, resolves enabled Sources, backfills the last 10 messages (and neighboring album members), and reads new messages/edits with a shared Telegram client and RPC lock. One account/session is shared; channel update checkpoints are namespaced by Source. It never opens a connection to the VKPoster database.

Set `SOURCES_READER_SECRET` to the same random secret (at least 32 characters) in VKPoster's `.env` and this directory's `.env`. Set `VKPOSTER_INTERNAL_URL` (Docker Desktop default: `http://host.docker.internal:8080`). Recreate the PHP app after changing its environment. Use HTTPS outside a trusted local/private network. The client refuses HTTP redirects and embedded credentials.

```bash
# Run from tools/telegram-reader. Persistent session and SQLite live in the existing reader-state volume.
docker compose run --rm reader login
# A one-shot smoke import restricted to a controlled enabled Source:
docker compose run --rm reader sources --once --source-id SOURCE_PUBLIC_ID
# Continuous multi-source reader after authorization:
docker compose up -d reader
```

`GET /internal/sources` returns only enabled Telegram Source IDs and normalized usernames. `POST /internal/source-events` accepts v1 `item` or `status` events under `Authorization: Bearer ...`. No Telegram credentials, session, access hashes, file references or binary images are exported. Integration mode does not download photos; media metadata includes Telegram IDs and the largest available photo-size metadata.

SQLite `outbox` stores each complete event before sending. The event ID is a SHA-256 of the canonical envelope. Messages remain durable even if the process dies before export; startup rebuilds missing outbox snapshots from durable messages/albums. Albums wait for the existing five-second quiet window. HTTP/network errors and missing/mismatched ACK retry with bounded exponential backoff (up to 300 seconds), without deleting events. ACKed rows remain to deduplicate exports. Pending rows for disabled Sources remain on disk and are not sent; they can resume when enabled again. One failed Source does not block others. No full content or exception messages are logged.

Current bounds: one account, sequential Telegram RPCs, 10-second polling, complete local snapshot scan for export, no retention/pruning yet. Deletion synchronization and downloading/processing images are outside this substage. Renaming a Source locator resets its connection status/checkpoint; already imported historical materials keep their original peer IDs. A permanent malformed event blocks that Source's delivery queue until repaired; outbox error classes and retry timestamps are retained locally.

### Autonomous checks

```bash
docker compose run --rm --no-deps --entrypoint python reader -m unittest discover -v
docker run --rm -v "$PWD:/work" -w /work ghcr.io/astral-sh/ruff:0.14.1 check reader tests
```

`tests/test_integration.py` covers albums, edits, restart, lost ACK, disabled Sources, multi-source checkpoints and HTTP ACK validation. The PHP contract test additionally requires an isolated PHP HTTP service using **app_test**, `TEST_INTERNAL_URL`, and `TEST_READER_SECRET`. Run `tests/seed_contract.php` only in the PHP app container against the test database, after the full PHP suite. It creates two enabled synthetic Sources and one disabled Source. No test connects to Telegram. The reader runtime never runs this PHP test fixture.

Run the complete synthetic PHP HTTP contract after `make test` with `sh tools/telegram-reader/tests/run_php_contract.sh` from the repository root. It uses a disposable container, local port 18085 and app_test; it removes only its own container on exit.

## QR login (substage 2.3.1)

From this directory, with existing `.env` and reader-state volume:

```bash
docker compose build reader
docker compose run --rm -p 127.0.0.1:18186:8765 reader login-qr
```

Open `http://localhost:18186` locally and scan using the controlled test account: Telegram → Settings → Devices → Link Desktop Device. QR refreshes every 30 seconds for at most 10 minutes; neither its token URL nor its image is written to operational logs/files. Only a local loopback port is published. The listener stops and clears its RAM image on success/failure. The existing session is reused and an authorized session skips QR. Ordinary `login` remains the fallback. No phone or OTP is used by `login-qr`. If Telegram requires 2FA, enter it only in the interactive Terminal (hidden); a noninteractive run stops safely instead. Do not scan from a different account or run another reader against the same volume during login.

After successful authorization, the controlled smoke uses `sources --once --source-id SOURCE_PUBLIC_ID`; no publication or media processing occurs.

### QR with Telegram 2FA

Run `login-qr` **in the foreground in a local Terminal**, not via detached `-d`/background Docker execution. Compose keeps stdin/TTY attached. After QR scanning, Telegram's password requirement is handled by a hidden Terminal-only prompt; the MTProto event loop remains active while typing. No browser password form, environment variable or saved password is used. Up to three incorrect-password attempts are allowed without rescanning; an empty password stops safely. If terminal echo cannot be disabled, input is refused instead of falling back to visible stdin. An authorized session still skips all login prompts.

## Sources Image Processing (2.4B)

Команда `sources` теперь также получает `/internal/source-image-jobs` и доставляет best PhotoSize bytes + SHA-256 на `/internal/source-image-results`. Работа только для enabled Sources и актуальных approved jobs. Обычный импорт metadata не скачивает все фото автоматически. Один аккаунт/session сохранён; login/QR не менялись.

Reader использует существующий downloader, приватный SHA-256 cache и отдельную durable SQLite `image_outbox`. Задание и результат сохраняются до передачи, результат ACKed только после durable ответа PHP. Потерянный ACK/restart повторяют тот же job без повторного download. Ошибки cache приводят к повторной загрузке, payload в логах отсутствует. PHP лимитирует фото 16 MiB; полная image-processing architecture: `docs/architecture/modules/source-image-processing.md`.

После изменения Python требуется пересборка отдельного образа. Из корня VKPoster:

```sh
docker compose -f tools/telegram-reader/compose.yaml build reader
docker compose -f tools/telegram-reader/compose.yaml up -d reader
```

Новая команда авторизации не требуется при готовой persistent session. Тесты используют Docker и синтетические фото; `tests/run_php_contract.sh` дополнительно проверяет binary transfer, четыре photo jobs двух Sources, commit/ACK/duplicate и restart на `app_test`. Не запускайте этот contract script одновременно с PHP suite: они используют общую тестовую БД. Live Telegram photo download на этом этапе отдельно не проверялся.
