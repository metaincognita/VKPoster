# Sources — отдельный план и прогресс функции

Обновлено: 2026-10-05. Основа: актуальный main stage 20 (`6219ebcc`); feature-ветка `stage-13-sources-skeleton`.

Этот документ относится к новой функции Sources. Он не меняет статусы исходных stages VKPoster в [PROGRESS.md](PROGRESS.md). Source — источник контента; Channel — место публикации. Архитектура и контракт: [Sources](../architecture/modules/sources.md), [MTProto spike ADR](../adr/0006-isolated-telegram-reader-spike.md), [reader README](../../tools/telegram-reader/README.md).

## Этап 1 из 3 — Telegram technical foundation

**Техническая основа реализована; live-подтверждение не завершено.**

- Изолированный read-only `tools/telegram-reader`: Python 3.13, Telethon 1.45.0, отдельный Docker Compose и persistent session/SQLite в volume.
- MTProto за транспортным адаптером; resolve публичного канала, история, updates, edits, альбомы, загрузка лучшей доступной фотографии с dimensions/SHA-256 в standalone spike; дедупликация и restart/recovery проверяются автономно.
- Автономные тесты и Ruff пройдены; тесты не используют реальный Telegram.
- Live Telegram smoke не завершён: авторизация тестового аккаунта не подтверждена, login code получить не удалось. Автоматические тесты не считаются подтверждением работы на реальном Telegram.

## Этап 2 из 3 — Sources и обработка контента

| Подэтап | Статус | Что входит |
|---|---|---|
| 2.1 Sources | **Завершён** | Отдельная сущность, нормализация Telegram username, уникальность внутри workspace, системный status, enabled, список/create/edit/show, права, navigation, audit |
| 2.2 reader ↔ VKPoster integration | **Технически завершён; live smoke отложен** | Внутренний HTTP API с отдельным секретом, enabled Sources, durable outbox/ACK после commit, source_events/items/messages, дедупликация, albums/edits, один Telegram account/session для нескольких Sources |
| 2.3 Content Selection | **Завершён** | Детерминированные правила, отдельные решения и snapshot правил, approved/rejected/needs_review, ручной приоритет, UI причин и фильтра |
| 2.3.1 Telegram QR authorization + live smoke | **Следующий технический долг** | Спроектировать/проверить QR-авторизацию контролируемого тестового аккаунта; подтвердить enabled Source → reader → реальные messages → VKPoster без дублей → «Полученные материалы». QR-авторизация пока не реализована и не проверена |
| 2.4 Text/Image Processing | **Запланирован** | Обработка только принятых материалов: текст и изображения, перенос медиа в медиатеку; отдельный следующий подэтап |
| 2.5 Content Discovery / Trend Radar | **Запланирован** | Поиск актуального и быстрорастущего контента в Telegram, СМИ, web и других доступных источниках |
| 2.6 Semantic AI selection/ranking | **Запланирован** | Семантический отбор и ранжирование по пользовательским критериям, структурированные решения, независимость от AI-провайдера |

Входящие данные и отбор существуют отдельно от publishing. Сейчас нет AI, rewrite/enhancement/reverse image search, создания PostDraft или автоматической публикации из Source. Решение отбора не меняет технический статус `stored`. Exclude имеет приоритет, категории работают через AND, значения списка — OR, альбом оценивается целиком. До сохранения правил — needs_review; явно сохранённые пустые правила разрешают всё. Ручное решение сохраняется после edits и смены правил.

## Этап 3 из 3 — полная автоматизация

**Запланирован; реализация не начата.**

- Полная end-to-end автоматизация от Source и отбора до обработанного материала и публикации.
- Интеграция с существующими PostDraft / scheduler / publishing без переписывания текущего pipeline.
- Production hardening: эксплуатация reader, credentials/session, доступность, лимиты Telegram, retention, масштабирование, наблюдаемость и восстановление.

## Проверенный технический статус

Последний полный `make check`: **1758 PHP tests / 37136 assertions**, PHPStan level 8 + strict rules, стиль, CSS, composer audit и phpDocumentor — успешно. Sources отдельно: **107 tests / 540 assertions**, включая unit/integration/HTTP feature, CSRF, permissions, consent, IDOR и rollback/replay миграций. Новые доменные классы Selection: **100% строк по PCOV**. Миграции 13/21/22 применены локально; `/healthz` — HTTP 200, DB/Redis ok.

Reader: **35 автономных тестов**, отдельный успешный синтетический HTTP contract test reader ↔ PHP (итого 36), Ruff 0.14.1 — успешно. Contract test проверяет несколько Sources, альбомы, edits, retries/ACK и restart без реальной Telegram-авторизации. Команды: `make check`; `docker compose exec -T app vendor/bin/phpunit --filter Source`; `sh tools/telegram-reader/tests/run_php_contract.sh`. Автономные команды reader описаны в его README. PHP тесты работают с `app_test`; контрактный тест запускается после PHP suite, без параллельного доступа к тестовой БД.

## Известные ограничения и незавершённые live-проверки

- Реальные resolve, последние 10 сообщений, real-time чтение, edits/альбомы, скачивание реальной фотографии и Telegram offline catch-up пока не подтверждены. Следующий минимальный smoke — 2.3.1; расширенные сценарии остаются покрыты автоматическими тестами.
- Один Telegram account/session, последовательные RPC и polling 10 секунд. Production-готовность MTProto не заявляется.
- Reader сохраняет runtime/session/outbox локально; retention/pruning и безопасное production-хранение session ещё не разработаны. Данные не входят в Git. Permanent malformed event может блокировать доставку одного Source до исправления.
- Бинарные изображения в интеграционном режиме не переносятся в MediaService. Удаления Telegram не синхронизируются; смена группировки сообщения отклоняется.
- Пересчёт отбора большой истории пока синхронный: Source блокируется на время транзакции. Хранится актуальное решение со snapshot, а не полная история всех автоматических оценок; ручные действия и изменения правил аудируются.
- Для старых материалов без достоверного признака пересылки активный forwarded-фильтр даёт needs_review. Сброс ручного решения в automatic пока отсутствует.
- UI проверен HTTP feature-тестами. Ручной просмотр текущего пользовательского аккаунта ограничен `/consent`; реальные согласия автоматически не принимались.
- Credentials, `.env`, Telegram phone/API ID/API hash, OTP/2FA, session и SQLite/runtime остаются локальными; для второго разработчика доступны только пустые `.env.example`. Настройка его окружения выполняется отдельно.
- Резервный `stash@{0}` сохранён локально, в GitHub не передаётся. Main и существующий publishing pipeline не изменялись этим направлением работы.

После технической фиксации ветки разработка остановлена. Этот документ не является разрешением автоматически запускать Telegram login или следующие подэтапы.
