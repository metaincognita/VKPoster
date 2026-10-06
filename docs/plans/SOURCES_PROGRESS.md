# Sources — отдельный план и прогресс функции

Обновлено: 2026-10-06. Основа: актуальный main stage 20 (`6219ebcc`); feature-ветка `stage-13-sources-skeleton`.

Этот документ относится к новой функции Sources. Он не меняет статусы исходных stages VKPoster в [PROGRESS.md](PROGRESS.md). Source — источник контента; Channel — место публикации. Архитектура и контракт: [Sources](../architecture/modules/sources.md), [MTProto spike ADR](../adr/0006-isolated-telegram-reader-spike.md), [reader README](../../tools/telegram-reader/README.md).

## Этап 1 из 3 — Telegram technical foundation

**Техническая основа реализована; минимальный live smoke подтверждён.**

- Изолированный read-only `tools/telegram-reader`: Python 3.13, Telethon 1.45.0, отдельный Docker Compose и persistent session/SQLite в volume.
- MTProto за транспортным адаптером; resolve публичного канала, история, updates, edits, альбомы, загрузка лучшей доступной фотографии с dimensions/SHA-256 в standalone spike; дедупликация и restart/recovery проверяются автономно.
- Автономные тесты и Ruff пройдены; тесты не используют реальный Telegram.
- Авторизация тестового аккаунта подтверждена через QR с локальным скрытым вводом Telegram 2FA. Минимальный live smoke 2.3.1 прошёл все 6 пунктов; расширенные live-сценарии отдельно не проверялись.

## Этап 2 из 3 — Sources и обработка контента

**Этап 2 Core завершён. Все подэтапы 2.1–2.6 completed; внешние providers остаются в согласованных Fake/Core границах.**

| Подэтап | Статус | Что входит |
|---|---|---|
| 2.1 Sources | **Completed / Завершён** | Отдельная сущность, нормализация Telegram username, уникальность внутри workspace, системный status, enabled, список/create/edit/show, права, navigation, audit |
| 2.2 Reader integration | **Completed / Завершён; минимальный live smoke подтверждён** | Внутренний HTTP API с отдельным секретом, enabled Sources, durable outbox/ACK после commit, source_events/items/messages, дедупликация, albums/edits, один Telegram account/session для нескольких Sources |
| 2.3 Content Selection | **Completed / Завершён** | Детерминированные правила, отдельные решения и snapshot правил, approved/rejected/needs_review, ручной приоритет, UI причин и фильтра |
| 2.3.1 Telegram QR authorization + live smoke | **Completed / Завершён** | QR-login с существующей persistent session, Telegram 2FA через скрытый ввод только в Terminal. Все 6 пунктов live smoke подтверждены: @sansillusions импортирован, 9 source_items отображаются в UI Void, повторный запуск подтвердил дедупликацию |
| 2.4A Text Processing Core | **Completed / Завершён** | Current approved/revision guards, отдельные ContentProcessor/TextProcessor, настройки и история source_text_processings, страница материала, автономные тесты. Сейчас используется Fake TextProvider; реальный AI provider намеренно не подключён |
| 2.4B Image Processing Core | **Completed / Завершён (внешние providers — Fake)** | Approved/revision guards, per-photo jobs и HTTP transfer из reader, private originals/variants, technical quality, консервативная проверка соответствия и ручной выбор. Search и enhancement пока Fake; MediaService/PostDraft/publishing не подключены |
| 2.4C Video Generation Core | **Completed / Завершён (Fake provider)** | Отдельные video jobs, snapshots настроек и основ, история, ручной выбор и UI; настоящее видео не создаётся |
| 2.5 Content Discovery / Trend Radar Core | **Completed / Завершён (Core, Fake providers)** | Независимый от Sources Радар, дедупликация, эвристические кластеры, объяснимый score и импорт в существующую текстовую обработку |
| 2.6 Semantic AI Selection / Ranking Core | **Completed / Завершён (Core, Fake provider)** | Семантический отбор и ранжирование по пользовательским критериям, структурированные решения, независимость от AI-провайдера |

На контрольной точке Этапа 2 входящие данные и отбор были отдельны от publishing. Реальный AI, enhancement/reverse image search, создание PostDraft и автоматическая публикация из Source тогда не были подключены. Ручная связь с PostDraft и существующим publishing добавлена в 3.1 (см. ниже). Текстовая обработка 2.4A проверяется через Fake provider. Решение отбора не меняет технический статус `stored`. Exclude имеет приоритет, категории работают через AND, значения списка — OR, альбом оценивается целиком. До сохранения правил — needs_review; явно сохранённые пустые правила разрешают всё. Ручное решение сохраняется после edits и смены правил.

## Этап 3 из 3 — полная автоматизация

**Подэтапы 3.1 и 3.2 completed / завершены. Live smoke OpenAI/TinEye/Replicate перенесён в 3.5. Следующий подэтап — 3.3 Automation; он пока не начат.**

- Полная end-to-end автоматизация от Source и отбора до обработанного материала и публикации.
- Интеграция с существующими PostDraft / scheduler / publishing без переписывания текущего pipeline.
- Production hardening: эксплуатация reader, credentials/session, доступность, лимиты Telegram, retention, масштабирование, наблюдаемость и восстановление.

## История проверок: контрольная точка 2.3

Последний полный `make check`: **1758 PHP tests / 37136 assertions**, PHPStan level 8 + strict rules, стиль, CSS, composer audit и phpDocumentor — успешно. Sources отдельно: **107 tests / 540 assertions**, включая unit/integration/HTTP feature, CSRF, permissions, consent, IDOR и rollback/replay миграций. Новые доменные классы Selection: **100% строк по PCOV**. Миграции 13/21/22 применены локально; `/healthz` — HTTP 200, DB/Redis ok.

Reader: **35 автономных тестов**, отдельный успешный синтетический HTTP contract test reader ↔ PHP (итого 36), Ruff 0.14.1 — успешно. Contract test проверяет несколько Sources, альбомы, edits, retries/ACK и restart без реальной Telegram-авторизации. Команды: `make check`; `docker compose exec -T app vendor/bin/phpunit --filter Source`; `sh tools/telegram-reader/tests/run_php_contract.sh`. Автономные команды reader описаны в его README. PHP тесты работают с `app_test`; контрактный тест запускается после PHP suite, без параллельного доступа к тестовой БД.

## Известные ограничения и незавершённые live-проверки

- Реальные resolve, чтение последних 10 сообщений, доставка и сохранение без дублей, UI подтверждены в 2.3.1. Real-time новые сообщения, ручные edits/альбомы, скачивание binary photo и Telegram offline catch-up отдельно в этом smoke не проверялись; расширенные сценарии покрыты автоматическими тестами.
- Один Telegram account/session, последовательные RPC и polling 10 секунд. Production-готовность MTProto не заявляется.
- Reader сохраняет runtime/session/outbox локально; retention/pruning и безопасное production-хранение session ещё не разработаны. Данные не входят в Git. Permanent malformed event может блокировать доставку одного Source до исправления.
- Бинарные изображения в интеграционном режиме не переносятся в MediaService; в 2.4B они доставляются в отдельное private-хранилище обработки изображений. Удаления Telegram не синхронизируются; смена группировки сообщения отклоняется.
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

Архитектура и ограничения: [Source text processing](../architecture/modules/source-text-processing.md). Новая миграция 23 применена локально, rollback/replay проверен в app_test. Полный PHP suite: 1792 tests / 37307 assertions; новые unit/integration/feature: 34 tests / 155 assertions (отдельный запуск после добавления проверки метрики). PHPStan level 8 + strict rules, стиль, /healthz (DB/Redis ok), UI/axe — успешно. PCOV: ContentProcessor 97.78%, MaterialRepository 100%, TextProcessor 95.83%, TextSettings 96%, FakeTextProvider 100%. Сейчас используется Fake TextProvider; реальный AI provider намеренно не подключён. Реальная AI-редактура/перевод не заявляются. Reader и publishing не менялись, основной Source не обработан и его selection сохранён; stash сохранён. На момент проверки 2.4A Image Processing и Video Generation оставались roadmap; теперь их Core завершён (см. ниже).

## Подэтап 2.4B: завершённая реализация

См. [архитектуру Image Processing](../architecture/modules/source-image-processing.md). Реальны download best PhotoSize и durable доставка, immutable storage, quality/dHash + визуальная проверка, history и UI. Fake search не ищет в интернете; Fake enhancement — отдельная копия без повышения качества. Live approved-photo smoke в этом подэтапе не проводился; существующий минимальный live smoke 2.3.1 остаётся подтверждённым. Оригиналы ещё не превращаются в MediaItem/PostDraft. Production retention/quota/нагрузка остаются последующими задачами.

Проверки 2.4B: полный PHP suite — 1805 tests / 37492 assertions; 13 новых image-processing tests; покрытие новых Domain-классов выше 85%. Reader — 52 автономных теста, ещё 2 внешних PHP HTTP contract-теста успешно выполнены отдельно. Контракт передачи проверил 4 завершённых image jobs / 8 variants, повторную доставку без дублей. Миграция 24 применена локально; rollback/replay проверен в app_test. PHPStan, Ruff, стиль, composer audit, docs build, /healthz (DB/Redis ok) и UI/axe (6 screenshots) — успешно. Исправлены только timezone-зависимые ожидания в существующих тестах дат; admin/billing business logic не менялась. 9 live-материалов Void сохранены без обработки. Коммит и push не выполнялись; stash сохранён.

## Подэтап 2.4C: Video Generation Core

Отдельный VideoProvider/FakeVideoProvider, source_video_generations (migration 25), pending/processing/completed/failed, неизменяемые snapshots настроек и основ, несколько попыток, выбор итоговой версии и UI. Fake не создаёт видео. Реальная генерация, монтаж, звук, subtitles и publishing не подключены. Выполнение демонстрации синхронное по отдельной кнопке; recovery зависших processing, polling и storage реального видео требуют последующей интеграции provider. Reader, исходные материалы и text/image processing не изменяются. На момент проверки 2.4C следующим подэтапом был 2.5; теперь его Core завершён (см. ниже).

Проверки 2.4C: полный PHP suite — 1829 tests / 37628 assertions; новые unit/feature — 24 tests / 101 assertions. Покрытие новых Domain/Integrations выше 95% (VideoWorkflow около 97%). PHPStan level 8 + strict rules, стиль, composer audit, docs build и /healthz (DB/Redis ok) — успешно. Миграция 25 применена локально, rollback/replay проверен в app_test. UI/axe: 6 screenshots (375/768/1440 × light/dark), без блокирующих нарушений; первый запуск snapshot прервался из-за navigation timeout, повторный успешно завершился. Fake отключён в production. В основной БД осталось 9 материалов @sansillusions в Void и 0 video attempts; live данные не менялись. Secrets scan — PASS; коммит и push не выполнялись, stash сохранён. Подэтап завершён; результаты последующего 2.5 приведены ниже.

## Подэтап 2.5: Content Discovery / Trend Radar Core

Реализовано отдельное от Sources обнаружение через Fake Telegram/WebNews/Social providers и fixtures. DiscoveryItem/Cluster, alias dedupe, объяснимый score с неизвестными метриками, история запусков и UI Радар. Импорт через DiscoveryMaterialGateway сохраняет только title/excerpt/URL и provenance, не создаёт Source или PostDraft; shared ContentProcessor/TextProcessor требует ручного approved. Реальные providers, polling production, AI и publishing не подключены. На момент проверки 2.5 следующим подэтапом был 2.6; теперь его Core завершён (см. ниже).

Архитектура: [Content Discovery](../architecture/modules/content-discovery.md), [граница импорта](../adr/0010-discovery-content-origin.md). Миграция 26 добавляет discovery_clusters/items/item_keys/runs/imports; source_id допускает NULL только в shared source_items/selection_decisions/text_processings для зарегистрированного Discovery-origin. Канонический URL, external ID, нормализованный title и fingerprint предотвращают повторы; независимые публикации объединяются по токенам и временному окну. Score складывает свежесть (25), независимые источники (20), скорость обнаружения (15), engagement (15), заданную авторитетность (15) и подтверждённый относительный рост (10). Отсутствующие метрики сохраняются как неизвестные, без выдуманных значений или перераспределения весов.

Проверки 2.5: полный PHP suite — **1872 tests / 37843 assertions**; новые unit/feature — **31 tests / 142 assertions**. PHPStan level 8 + strict rules, стиль, composer audit, CSS, docs build, /healthz (DB/Redis ok) — успешно. Миграция 26 применена локально; rollback/replay проверен в app_test с сохранением существующих Source-материалов. UI/axe: два экрана, 12 screenshots (375/768/1440 × light/dark), без блокирующих нарушений. Таймауты первого UI-прогона устранены подключением внутри Docker-сети; основной пользователь и consent не изменялись. В основной БД сохранены 9 материалов @sansillusions, синтетические Discovery fixtures использовались только в app_test. Secrets scan — PASS; коммит и push не выполнялись, stash сохранён.

Ограничения Core: сбор запускается кнопкой обновления Радара; постоянный production polling и реальные providers ещё не подключены. Все три Fake provider отключены в production. Кластеризация детерминированная, не понимает межъязыковые перефразировки и неоднозначные сюжеты; score не является доказательством достоверности новости. Импортированные материалы используют текстовый flow; изображения и видео из Discovery пока не подключены. Подэтап 2.5 завершён в согласованном объёме Core; результаты последующего 2.6 приведены ниже.

## Подэтап 2.6: Semantic AI Selection / Ranking Core

Смысловой слой расширяет существующий SelectionService/SelectionEngine. Настройки Source/Radar и отдельная история ревизий сохраняют structured decision, relevance score и confidence отдельно; детерминированный запрет исключает вызов provider, ручное решение имеет приоритет. Radar relevance не меняет Trend Score. Provider сейчас Fake, реальные AI API намеренно не подключены. Архитектура/ограничения: [Semantic Selection](../architecture/modules/semantic-selection.md). Подэтап завершён в согласованном объёме Core. Реальный AI не подключён; произвольные естественно-языковые критерии не интерпретируются Fake как настоящим AI.


Проверки 2.6: **39 новых unit/feature tests / 166 assertions**, полный PHP suite — **1911 tests / 38027 assertions**. PHPStan level 8 + strict rules, стиль, CSS, composer audit, docs build — успешно. PCOV новых Domain/Integrations: 95.0–100.0%. Миграция 27 применена локально; rollback/replay проверен в app_test с сохранением исходных материалов, автоматические semantic approvals при rollback переводятся в needs_review. /healthz — DB/Redis ok. UI/axe: 4 экрана (Source settings/material, Radar settings/material), 24 screenshots (375/768/1440 × light/dark), без блокирующих нарушений, скриншоты просмотрены. Новые оценки/fixtures использовались только в app_test; в основной БД по-прежнему 9 материалов @sansillusions и 0 semantic settings/evaluations. Secrets scan — PASS.

Ограничения: Fake выключен в production; оценка синхронная под Source/workspace lock только для локального Core. Для реального AI потребуется отдельное асинхронное выполнение с проверкой revision/policy/manual override при финализации, timeouts/retries/quotas и тестированием prompt-injection защиты конкретного adapter. Relevance ranking ограничен текущим списком до 100 тем; retention/pruning истории остаются production hardening. Коммит/push не выполнялись, main/reader/publishing не изменялись в 2.6, stash сохранён. Этап 2 завершён в согласованных Core/Fake границах; финальная Git-фиксация отдельно разрешена владельцем. Этап 3 не начат.

## Ограничения, переходящие в Этап 3

- Реальный Text AI provider пока не подключён; TextProvider сейчас Fake.
- Настоящий интернет image search пока не подключён; ImageSearchProvider сейчас Fake.
- Настоящий image enhancement пока не подключён; Fake сохраняет отдельную копию без улучшения качества.
- Реальный video generation provider пока не подключён; Fake демонстрирует жизненный цикл без создания видео.
- Discovery providers пока Fake; постоянный polling не подключён.
- Semantic AI provider пока Fake; естественно-языковое понимание и production AI не заявляются.
- Реальное скачивание Telegram-фото в Image Processing отдельно live не проверялось; передача и обработка проверены автономно и через HTTP contract.
- Ограничение Stage 2: PostDraft/publishing не были связаны. В 3.1 добавлены ручной экспорт и preflight в существующем pipeline; полностью автоматическая production-цепочка ещё не реализована.

Следующий этап требует отдельной команды владельца. Новые внешние live-тесты при финальном regression не проводились.

## Финальный regression Этапа 2 Core — 2026-10-06

- Полный PHP suite: **1911 tests / 38027 assertions**, успешно.
- Reader: **52 автономных теста**, успешно; два HTTP contract-теста успешно выполнены отдельно (в discover они пропускаются). Контракт подтвердил 2 items / 4 messages и 4 завершённых image jobs / 8 variants, включая повторную доставку без дублей.
- PHPStan level 8 + strict rules: 760 файлов, без ошибок. Ruff 0.14.1 и code style: успешно, 0 исправлений в 755 PHP-файлах. Composer audit, CSS и phpDocumentor build: успешно.
- Миграции 24–27 актуальны; `migrate` — Nothing to migrate. Rollback/replay выполнен в feature/integration tests на app_test; основная БД не откатывалась.
- `/healthz`: HTTP 200, DB/Redis ok.
- UI/axe: Image Processing, Video Generation, Radar и Semantic — 8 страниц, 48 screenshots (375/768/1440 × light/dark); axe дополнительно на 360px, без serious/critical нарушений и горизонтального переполнения. Ключевые скриншоты просмотрены. Fixtures и consent использовались только для тестовых пользователей app_test.
- Проверен весь diff относительно `6d70d683806112a5dd3fd318264087a1e5d2b5da`: 2.4B–2.6, их интеграция, документация и тесты. Отдельно проверено отсутствие локальных credentials в подготавливаемом commit; `.env`, session, runtime SQLite, временные файлы и `.DS_Store` исключены.
- Финальная фиксация выполняется одним commit в `stage-13-sources-skeleton`; main и резервный `stash@{0}` сохранены. Новая функциональность и Этап 3 не начаты.

## Подэтап 3.1 — связь content с существующим publishing

ContentDraftService, content_post_origins (migration 28), ContentOriginGuard: [архитектура](../architecture/modules/content-drafts.md). Актуальный approved Source/Discovery материал, выбранный text attempt и image/video variants превращаются в обычный PostDraft через PostService. Пользователь продолжает работу в существующем редакторе; scheduler/Queue/adapters не переписаны, SourcePublisher отсутствует. Повторный экспорт идемпотентен; ordinary duplication сохраняет origin; stale/rejected/needs_review не проходят экспорт/отправку. Fake video не прикрепляется как настоящий файл. Новые внешние providers, Telegram login и live-проверки не запускались.

Production debt: реальные providers, постоянный polling, live photo transfer, retention/quotas/recovery и координация deployment rollback. MySQL не может атомарно отменить уже начатый внешний send; используется последний preflight. Экспорт пока ручной; новая ревизия/решение требует свежей обработки и отдельного черновика. 3.1 опубликован отдельным checkpoint 792979920a9c6792f0a2f2e0f571d4d55df8c911; main и резервный stash сохранены.

Проверки 3.1: **17 новых E2E/feature tests / 150 assertions**; полный PHP suite — **1928 tests / 38195 assertions**, успешно. ContentDraftService PCOV 94.12%, ContentOriginGuard 90.24%. Reader — 52 автономных tests и 2 отдельных HTTP contracts, успешно. PHPStan level 8 + strict rules, Ruff, стиль, CSS build, composer audit (0 advisories), phpDocumentor и /healthz (DB/Redis ok) — успешно. Миграция 28 применена локально, rollback/replay на app_test проверен, включая отмену queued и запрет rollback во время sending. UI/axe: Source material, Discovery material, ordinary editor — 18 screenshots (375/768/1440 × light/dark), дополнительный axe на 360px, без serious/critical нарушений и horizontal overflow; скриншоты просмотрены. Тестовые fixtures изолированы в app_test; основной consent и live-материалы не менялись. Secrets scan PASS.

### Stage 3.1 checkpoint

**3.1 completed.** Dedicated Git checkpoint contains only content-to-draft integration, guards, UI, tests and documentation. Local secrets, runtime files and .DS_Store are excluded. Stage 3.2 starts only after this checkpoint is pushed successfully.

### 3.2 — Real providers

Adapters implemented through existing interfaces: OpenAI Responses (Text + Semantic), TinEye v2 image search, Replicate Real-ESRGAN enhancement, async Replicate Seedance video. Fake retained; no Discovery polling or publishing rewrite. Provider metadata migration 29, durable remote video IDs and guarded status checks. Credentials absent locally: mocked contracts/automated regression only, live smoke OpenAI/TinEye/Replicate deferred to the final Stage 3.5 by owner decision. **3.2 completed**, accepted on adapters, synthetic contracts and mocked HTTP. See [operations and limitations](../architecture/modules/content-real-providers.md). Do not treat mock tests as confirmation of account access or paid provider availability.

Проверки 3.2: полный PHP suite — **1985 tests / 38363 assertions**, успешно; **39 mocked provider contract/unit tests / 108 assertions**, шесть новых workflow/feature tests и 12 config activation tests; PCOV новых adapter classes 85–100%. Reader: 52 автономных tests и 2 отдельных PHP HTTP contracts (включая фото/ACK/restart), успешно. PHPStan level 8 + strict rules, Ruff 0.14.1, code style, composer audit (0 advisories), миграция 29 и rollback/replay на app_test, /healthz (DB/Redis ok) — успешно. Video UI/axe: 375/768/1440 × light/dark плюс 360px axe, без blocking issues; скриншоты просмотрены. Первый временный UI server дал navigation timeout, повторный прогон успешен. Private env-value scan PASS; placeholders в .env.example не являются credentials. 3.2 завершён и фиксируется отдельным checkpoint в feature-ветке; stash@{0} сохранён. Main, publishing, billing и telegram-reader не изменены. Live платные вызовы не выполнялись; credentials намеренно не подключены. Проверки реальных OpenAI/TinEye/Replicate перенесены в 3.5. Следующий подэтап — 3.3 Automation, в этой фиксации не начинается.

Owner acceptance: Stage 3.2 is complete on automated/mocked verification. Real adapters require both explicit provider selection and corresponding non-empty env credentials; selecting a real provider without credentials fails configuration safely. Merely adding credentials does not switch Fake providers on its own. Fake remains the dev/test default. No keys requested or connected. Live smoke is a tracked Stage 3.5 task, not a blocker for this checkpoint.
