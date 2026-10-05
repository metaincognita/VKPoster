# Sources — отдельный план и прогресс функции

Обновлено: 2026-10-05. Основа: актуальный main stage 20 (`6219ebcc`); feature-ветка `stage-13-sources-skeleton`.

Этот документ относится к новой функции Sources. Он не меняет статусы исходных stages VKPoster в [PROGRESS.md](PROGRESS.md). Source — источник контента; Channel — место публикации. Архитектура и контракт: [Sources](../architecture/modules/sources.md), [MTProto spike ADR](../adr/0006-isolated-telegram-reader-spike.md), [reader README](../../tools/telegram-reader/README.md).

## Этап 1 из 3 — Telegram technical foundation

**Техническая основа реализована; минимальный live smoke подтверждён.**

- Изолированный read-only `tools/telegram-reader`: Python 3.13, Telethon 1.45.0, отдельный Docker Compose и persistent session/SQLite в volume.
- MTProto за транспортным адаптером; resolve публичного канала, история, updates, edits, альбомы, загрузка лучшей доступной фотографии с dimensions/SHA-256 в standalone spike; дедупликация и restart/recovery проверяются автономно.
- Автономные тесты и Ruff пройдены; тесты не используют реальный Telegram.
- Авторизация тестового аккаунта подтверждена через QR с локальным скрытым вводом Telegram 2FA. Минимальный live smoke 2.3.1 прошёл все 6 пунктов; расширенные live-сценарии отдельно не проверялись.

## Этап 2 из 3 — Sources и обработка контента

| Подэтап | Статус | Что входит |
|---|---|---|
| 2.1 Sources | **Завершён** | Отдельная сущность, нормализация Telegram username, уникальность внутри workspace, системный status, enabled, список/create/edit/show, права, navigation, audit |
| 2.2 reader ↔ VKPoster integration | **Завершён; минимальный live smoke подтверждён** | Внутренний HTTP API с отдельным секретом, enabled Sources, durable outbox/ACK после commit, source_events/items/messages, дедупликация, albums/edits, один Telegram account/session для нескольких Sources |
| 2.3 Content Selection | **Завершён** | Детерминированные правила, отдельные решения и snapshot правил, approved/rejected/needs_review, ручной приоритет, UI причин и фильтра |
| 2.3.1 Telegram QR authorization + live smoke | **Completed / Завершён** | QR-login с существующей persistent session, Telegram 2FA через скрытый ввод только в Terminal. Все 6 пунктов live smoke подтверждены: @sansillusions импортирован, 9 source_items отображаются в UI Void, повторный запуск подтвердил дедупликацию |
| 2.4A Text Processing | **Completed / Завершён** | Current approved/revision guards, отдельные ContentProcessor/TextProcessor, настройки и история source_text_processings, страница материала, автономные тесты. Сейчас используется Fake TextProvider; реальный AI provider намеренно не подключён |
| 2.4B Image Processing | **Planned / Запланирован** | Обработка изображений принятых материалов и перенос медиа в медиатеку; реализация не начата |
| 2.4C Video Generation | **Planned / Запланирован** | Генерация видео; реализация не начата, в текущие изменения не входит |
| 2.5 Content Discovery / Trend Radar | **Запланирован** | Поиск актуального и быстрорастущего контента в Telegram, СМИ, web и других доступных источниках |
| 2.6 Semantic AI selection/ranking | **Запланирован** | Семантический отбор и ранжирование по пользовательским критериям, структурированные решения, независимость от AI-провайдера |

Входящие данные и отбор существуют отдельно от publishing. Реальный AI, enhancement/reverse image search, создание PostDraft и автоматическая публикация из Source не подключены. Текстовая обработка 2.4A проверяется через Fake provider. Решение отбора не меняет технический статус `stored`. Exclude имеет приоритет, категории работают через AND, значения списка — OR, альбом оценивается целиком. До сохранения правил — needs_review; явно сохранённые пустые правила разрешают всё. Ручное решение сохраняется после edits и смены правил.

## Этап 3 из 3 — полная автоматизация

**Запланирован; реализация не начата.**

- Полная end-to-end автоматизация от Source и отбора до обработанного материала и публикации.
- Интеграция с существующими PostDraft / scheduler / publishing без переписывания текущего pipeline.
- Production hardening: эксплуатация reader, credentials/session, доступность, лимиты Telegram, retention, масштабирование, наблюдаемость и восстановление.

## Проверенный технический статус

Последний полный `make check`: **1758 PHP tests / 37136 assertions**, PHPStan level 8 + strict rules, стиль, CSS, composer audit и phpDocumentor — успешно. Sources отдельно: **107 tests / 540 assertions**, включая unit/integration/HTTP feature, CSRF, permissions, consent, IDOR и rollback/replay миграций. Новые доменные классы Selection: **100% строк по PCOV**. Миграции 13/21/22 применены локально; `/healthz` — HTTP 200, DB/Redis ok.

Reader: **35 автономных тестов**, отдельный успешный синтетический HTTP contract test reader ↔ PHP (итого 36), Ruff 0.14.1 — успешно. Contract test проверяет несколько Sources, альбомы, edits, retries/ACK и restart без реальной Telegram-авторизации. Команды: `make check`; `docker compose exec -T app vendor/bin/phpunit --filter Source`; `sh tools/telegram-reader/tests/run_php_contract.sh`. Автономные команды reader описаны в его README. PHP тесты работают с `app_test`; контрактный тест запускается после PHP suite, без параллельного доступа к тестовой БД.

## Известные ограничения и незавершённые live-проверки

- Реальные resolve, чтение последних 10 сообщений, доставка и сохранение без дублей, UI подтверждены в 2.3.1. Real-time новые сообщения, ручные edits/альбомы, скачивание binary photo и Telegram offline catch-up отдельно в этом smoke не проверялись; расширенные сценарии покрыты автоматическими тестами.
- Один Telegram account/session, последовательные RPC и polling 10 секунд. Production-готовность MTProto не заявляется.
- Reader сохраняет runtime/session/outbox локально; retention/pruning и безопасное production-хранение session ещё не разработаны. Данные не входят в Git. Permanent malformed event может блокировать доставку одного Source до исправления.
- Бинарные изображения в интеграционном режиме не переносятся в MediaService. Удаления Telegram не синхронизируются; смена группировки сообщения отклоняется.
- Пересчёт отбора большой истории пока синхронный: Source блокируется на время транзакции. Хранится актуальное решение со snapshot, а не полная история всех автоматических оценок; ручные действия и изменения правил аудируются.
- Для старых материалов без достоверного признака пересылки активный forwarded-фильтр даёт needs_review. Сброс ручного решения в automatic пока отсутствует.
- UI проверен HTTP feature-тестами и живым просмотром Source в workspace Void: видны @sansillusions и 9 материалов. Consent принят вручную владельцем; автоматического принятия не было.
- Credentials, `.env`, Telegram phone/API ID/API hash, OTP/2FA, session и SQLite/runtime остаются локальными; для второго разработчика доступны только пустые `.env.example`. Настройка его окружения выполняется отдельно.
- Резервный `stash@{0}` сохранён локально, в GitHub не передаётся. Main и существующий publishing pipeline не изменялись этим направлением работы.

После технической фиксации ветки разработка остановлена. Этот документ не является разрешением автоматически запускать Telegram login или следующие подэтапы.

Проверка QR/2FA: 46 автономных reader-тестов успешно (47 total, один внешний PHP contract test пропущен в discover; ранее выполнен отдельно), Ruff успешно, Docker image пересобран. Тесты проверяют запрет echo-fallback, отсутствие пароля в выводе, повтор ввода без нового QR и активность event loop во время скрытого ожидания. Реальная QR-авторизация с Telegram 2FA успешно завершена владельцем в Terminal; session сохранена, пароль не хранится.

### Live smoke 2.3.1 — 2026-10-05

Владелец завершил QR + локальную 2FA; reader использовал persistent session без phone/OTP или повторной авторизации. Source `@sansillusions` (`01M46HE9PZGQDWV3JFQW4Y4E3B`) enabled и доступен internal API. Resolve: стабильный peer/channel ID `3941678860`. Прочитаны последние 10 реальных сообщений (IDs 13–22), доставлены события с ACK, сохранены 9 source_items: 7 photo, 1 text, 1 album из двух messages. Все items имеют технический stored; Source connected. После повторного one-shot импорта осталось 9 items / 10 messages, дубли items/messages/events = 0. Число source_events изменилось с 10 до 11 за счёт нового status/connected события, не повторного материала. Полный текст постов и credentials не выводились в operational logs/отчёт.

Все 6 пунктов live smoke подтверждены. Исправлено размещение локальных тестовых данных: существующий Source id=4 и связанные события, 9 items, 10 messages и решения отбора перенесены одной транзакцией из demo-workspace id=2 в workspace Void id=1. Source ID, public ID и содержимое сохранены. В UI Void видны @sansillusions и все 9 материалов. Изоляция workspace работала корректно, код приложения не менялся. Скачивание реального binary photo, ручные edits/albums/recovery отдельно не повторялись. Подэтап 2.3.1 завершён; следующая разработка не начата.

### 2.4A — текстовая обработка (completed)

Архитектура и ограничения: [Source text processing](../architecture/modules/source-text-processing.md). Новая миграция 23 применена локально, rollback/replay проверен в app_test. Полный PHP suite: 1792 tests / 37307 assertions; новые unit/integration/feature: 34 tests / 155 assertions (отдельный запуск после добавления проверки метрики). PHPStan level 8 + strict rules, стиль, /healthz (DB/Redis ok), UI/axe — успешно. PCOV: ContentProcessor 97.78%, MaterialRepository 100%, TextProcessor 95.83%, TextSettings 96%, FakeTextProvider 100%. Сейчас используется Fake TextProvider; реальный AI provider намеренно не подключён. Реальная AI-редактура/перевод не заявляются. Reader и publishing не менялись, основной Source не обработан и его selection сохранён; stash сохранён. Подэтап завершён и проверен; Image Processing и Video Generation остаются roadmap без реализации.
