# Источники — минимальный каркас

`Source` — источник контента пространства. `Channel` — место публикации. Это разные сущности, между ними пока нет связей. Source хранит настройки и входящие материалы; отдельный Telegram reader подключается через внутренний HTTP API. Publishing pipeline не задействован.

## Домен и хранение

`src/Domain/Source/`: `Source`, `SourceType`, `SourceStatus`, `SourceRepository`, `SourceService`, `TelegramSourceLocator`, `SourceException`.

- `SourceRepository` наследует `WorkspaceScopedRepository`: все операции принимают `WorkspaceContext`, чтение и обновление ограничены текущим workspace. В URL используется только `public_id` (ULID).
- `SourceService::create()` и `update()` валидируют название, тип и Telegram-ссылку. Сохранение и запись аудита выполняются в одной транзакции. Ошибка уникальности БД возвращается как ошибка поля канала, без SQL и технических подробностей.
- `TelegramSourceLocator::normalize()` принимает `@username`, username, `https://t.me/username`, `t.me/username`; приводит username к нижнему регистру и убирает завершающий слеш URL. Приглашения, ссылки на отдельные сообщения и сторонние URL не принимаются. Локальная проверка принимает username из 5–32 символов: латинская буква в начале, затем буквы, цифры и подчёркивания. Разбор полностью локальный: это не проверка существования канала и не resolve стабильного peer ID. При переименовании канала username может измениться; устойчивую идентичность добавит будущий reader.
- Таблица `sources`: `id`, `public_id`, `workspace_id`, `name`, `type`, `telegram_username`, `status`, `enabled`, `created_by`, `created_at`, `updated_at`. Миграция `2026_10_05_000013_create_sources.php`, обратимая. `UNIQUE(workspace_id, type, telegram_username)` защищает от дубликатов, включая одновременные запросы. Один канал допустим в разных пространствах.
- `type` пока только `telegram`; транспорт Bot API / MTProto здесь не выбирается.
- `status` — системное состояние, значения `not_connected`, `connected`, `error`. Поле не принимается из формы; при изменении username система сбрасывает подключение в `not_connected`.
- `enabled` — отдельный boolean, по умолчанию `false`. Включённые источники появляются в списке для reader; выключенные не читаются и не получают новые события.
- Время сохраняется в UTC через `Clock`; удаление workspace удаляет Sources каскадом; удаление создателя сохраняет источник и обнуляет `created_by`.

Правила отбора будут отдельным модулем и таблицами. В `sources` нет `selection_rules_json`, а также статусов отбора или обработки материалов. На карточке только заглушка «Правила отбора появятся позже».

## Совместимость с актуальной архитектурой

Каркас проверен на базе main `6219ebcc` (stage 20). Маршруты используют общую цепочку `Authenticate` → `RequireVerifiedEmail` → `RequireConsent` → `ResolveWorkspace` → `Authorize`; новый модуль не обходит согласия или права. При устаревшем согласии страницы и сохранение перенаправляются на `/consent`, без изменения источника. Согласия локальных пользователей подтверждаются только вручную. Автоматические проверки используют изолированную `app_test`.

В журнале workspace сохраняются `source.created` и `source.updated`; существующее событие `billing.trial_started` при создании workspace учитывается в тестах. Источники не добавляют биллинг, фоновые задачи или изменения publishing pipeline.

## HTTP, права и UI

`src/Http/Controllers/Sources/SourceController.php`; шаблоны `templates/workspace/sources/{index,form,show}.twig` используют существующий layout и компоненты дизайн-системы.

`public/assets/js/sources.js` возвращает клавиатурный фокус на первое поле с серверной ошибкой. Сохранение работает без JS; существующий `app.js` блокирует повторную отправку формы и показывает индикатор загрузки кнопки.

| Метод | Путь | Действие |
|---|---|---|
| GET | `/w/{workspaceId}/sources` | список и пустое состояние |
| GET | `/w/{workspaceId}/sources/new` | форма создания |
| POST | `/w/{workspaceId}/sources` | создание |
| GET | `/w/{workspaceId}/sources/{sourceId}` | карточка |
| GET | `/w/{workspaceId}/sources/{sourceId}/edit` | форма редактирования |
| POST | `/w/{workspaceId}/sources/{sourceId}` | сохранение |

`sources.view` и `sources.manage` доступны владельцу и администратору. На этом этапе отдельных списков доступа к Sources нет; права на `Channel` не используются. Гость направляется на вход; чужой workspace или источник — 404; роль без права — 403. Все изменения защищены CSRF. Валидационные ошибки сохраняют ввод через `FormFlash`; успешное сохранение открывает карточку с уведомлением. Статус показывается отдельно от включения.

Пункт «Источники» добавляет `WorkspaceNav::items()`. События журнала: `source.created`, `source.updated`, отдельная группа «Источники». Метаданные содержат только название; Telegram credentials отсутствуют.

## Проверки и границы

`tests/Unit/Source/TelegramSourceLocatorTest.php`, `tests/Integration/Source/{SourceDomainTest,SourceMigrationTest}.php`, `tests/Feature/Source/{SourcePagesTest,SourceActionsTest}.php`. Проверяются нормализация, ошибки, уникальность, workspace isolation, права, CSRF, системный статус, отсутствие побочных jobs/posts, миграция → rollback → миграция. Матрица прав и подписи аудита покрыты существующими unit-тестами Workspace.

UI: `make ui-snap STAGE=sources` (включает axe на 360/1440 px и снимки 375/768/1440 px в обеих темах). Список URL: `tools/ui-snap/urls/stage-sources.json`; использует локальные тестовые профили `sources-demo@ezposter.local` и `sources-empty@ezposter.local` с личными пространствами (создаются отдельно для UI-проверки). Для заполненного состояния в первом workspace нужен источник `@sources_ui_example`; создать через UI, без обращения в Telegram. Новые env и зависимости не нужны.

Не реализованы: Bot API импорт, привязка получателей, processing jobs, правила отбора, AI, PostDraft. Изменений `Channel`, `PostService`, `Publisher`, `Queue`, существующих миграций нет. Подэтап 2.2 расширяет только изолированный reader.

## Проверка подэтапа 2.1 на main stage 20

База `6219ebcc`: `make migrate` — нет ожидающих миграций; тесты Sources — 44 / 269 assertions; полный `make check` — 1708 / 36864 assertions, PHPStan, стиль, audit, CSS и phpDocumentor прошли. Реальная схема содержит ровно 11 требуемых полей и уникальный индекс `(workspace_id, type, telegram_username)`. `/healthz` — 200. HTTP feature-тесты покрывают страницы и сохранение.

Свежая ручная браузерная проверка и визуальные снимки на stage 20 ожидают подтверждения согласия `/consent` пользователем тестового профиля `sources-demo@ezposter.local`. Согласия автоматически не принимаются. Код каркаса готов к следующему подэтапу; визуальная проверка остаётся незавершённым пунктом. Reader и publishing не изменены; резервный stash сохранён, коммитов нет.

## Подэтап 2.2 — входящие материалы и внутренний HTTP API

Миграция `2026_10_05_000021_create_source_incoming.php` создаёт `source_events` (входящий конверт, hash, stored), `source_items` (логический пост/альбом) и `source_messages` (сообщения с entities и media metadata). AI/selection-полей нет. Схема `sources` остаётся прежней: 11 полей.

`SourceReaderController` проверяет отдельный `SOURCES_READER_SECRET` (минимум 32 символа, constant-time comparison) до обработки; без настройки API выключен. `GET /internal/sources` возвращает `{version:1,sources:[{id,username}]}` для включённых Telegram Sources. `POST /internal/source-events` принимает JSON `{version:1,event_id,source_id,kind,payload}`; `kind=item` содержит peer_id, grouped_id и массив нормализованных сообщений. `kind=status` содержит connected/error и безопасный error_code. Размер — до 512 KiB, до 100 сообщений; приватные credential-поля отклоняются. Эти маршруты не используют браузерную авторизацию/consent и CSRF, поскольку секрет принадлежит только сервису. Действуют отдельные rate limits. Секрет передаётся заголовком, никогда URL или HTML.

`SourceIngress` — явно привилегированная межпространственная граница. Workspace выводится из Source на сервере. Source блокируется `FOR UPDATE`, событие, item, сообщения и системный статус сохраняются в одной транзакции. ACK `{ack:true,event_id,duplicate}` выдаётся только после commit. Сбой БД — 503 без ACK; лог содержит только класс ошибки. Ранее сохранённый event безопасно ACKается повторно даже после выключения Source; новый event выключенного Source отклоняется 409.

Уникальность: `(source_id,event_id)` для событий; `(source_id,peer_id,item_key)` для items; `(source_id,peer_id,message_id)` для сообщений. item_key — `message:ID` или `album:grouped_id`. Повторные и неполные album snapshots объединяются; edit обновляет сообщение и карточку, старый edit_date не заменяет более новый. Смена grouped_id существующего сообщения отклоняется транзакционно. Другие workspace могут независимо импортировать тот же Telegram peer. Удаления Telegram здесь не синхронизируются.

`SourceItemRepository::recent()` отдаёт последние 30 items только текущего workspace и Source. На карточке: «Полученные материалы», дата UTC, фрагмент текста (300 символов), тип, message IDs, peer ID, отметка edit и технический статус «Сохранён». Вывод экранируется. Отбор добавлен в подэтапе 2.3 (ниже); AI, создание постов и MediaService отсутствуют.

Reader: `reader/integration.py`, одна persistent session, shared client/RPC lock, отдельные channel checkpoints по Source, HTTP polling, durable SQLite outbox. Стартовый backfill — 10 сообщений с расширением границ альбома; далее ChannelDifference + история/сверка edits. Outbox отправляет полные логические snapshots после quiet-window альбомов; повторы используют стабильный event ID, ACK проверяется строго. При выключении Source обработчики отключаются; на ошибке конфигурационного API чтение приостанавливается до успешной синхронизации. `.env` и volume session не удаляются.

Ограничения текущего каркаса: один аккаунт; polling 10 секунд; последовательные Telegram RPC; локальный snapshot scan и отсутствие retention для raw events/outbox; неподдерживаемая смена группировки сообщения требует ручного разбора. Это внутренний сервисный контракт, не публичный пользовательский API. Бинарные файлы не уходят в PHP.

### Фактическая проверка 2.2 (2026-10-05)

`make check`: 1714 tests / 36920 assertions, PHPStan, стиль, audit и phpDocumentor — успешно. `phpunit --filter Source`: 63 / 342 assertions. Reader: 35 автономных тестов и отдельный реальный HTTP PHP-contract test — успешно (обычный discover пропускает только этот внешний тест, он выполнен отдельно). Ruff 0.14.1 — успешно. Сквозной тест: два Sources, 2 album items, 4 messages, 4 events после edit; повтор и restart не дублируют записи. Миграции применены в app/app_test, `/healthz` — 200. Новая rollback/replay проверена в app_test. Для повторения: `sh tools/telegram-reader/tests/run_php_contract.sh` после PHP suite.

Локальный internal secret подготовлен в обоих `.env`, значения Telegram credentials сохранены. Контролируемый Source создан из TELEGRAM_TEST_CHANNEL и включён; виден в internal API. Live smoke ограничен его public ID, но остановился до чтения: session не авторизована, TELEGRAM_PHONE отсутствует. Реальный импорт и появление реальных сообщений в UI пока не подтверждены. Нужна локальная авторизация тестового аккаунта; никаких OTP/2FA в чат или логи. Session volume и резервный stash сохранены, коммитов нет.

## Подэтап 2.3 — детерминированный отбор и ручные решения

Отбор работает после транзакционного сохранения цельного `source_item`, до будущей обработки контента. `SourceIngress::item()` вызывает `SelectionService::evaluateLocked()` в той же транзакции до ACK; читатель и его HTTP-контракт не изменены. Ошибка сохранения решения откатывает событие, item и сообщения, допускает безопасный retry. Технический `source_items.status=stored` не зависит от решения отбора.

Миграция `2026_10_05_000022_create_source_selection.php` создаёт две отдельные таблицы:

- `source_selection_rules`: workspace_id, source_id (unique), version, rules_json, updated_by, timestamps. Одно текущее правило на Source; версия увеличивается при сохранении.
- `source_selection_decisions`: workspace_id, source_id, item_id (unique), selection_status, decision_mode, reason, matched_rule, rules_version, rules_snapshot_json, decided_by, timestamps. Одна актуальная оценка материала, с snapshot применённых правил. Это не append-only история всех автоматических оценок; ручные действия и изменения правил дополнительно сохраняются в audit log без текста материалов или правил.

Ранее импортированные items получают отдельное `needs_review` при миграции. `sources` и `source_items` не получают selection/AI-полей. Миграция обратима; удаление таблиц отбора не удаляет входящие материалы.

`SelectionRules` проверяет ввод: до 100 уникальных строк на список, до 100 символов на значение, до 10000 символов на поле; ключевые слова — подстроки без учёта регистра, хэштеги — точные Unicode-совпадения без #. Внутри включающего списка — OR; категории — AND; исключающие слова/хэштеги всегда проверяются первыми. Пустые include и список типов не ограничивают выбор. Допустимые типы — text/photo/album; Telegram document/video не выдаётся за photo. Условия links/forwarded — any/yes/no.

`SelectionEngine` — чистый детерминированный evaluator без I/O, AI и провайдеров. Альбом проверяется по объединённым captions и entities всех сохранённых сообщений. Ссылки определяются по http(s)/www и Telegram MessageEntityUrl/MessageEntityTextUrl (включая скрытые и ссылки без схемы). Если хоть одно сообщение переслано, переслан весь item. Reader уже передаёт `forward`; ingress дополнительно фиксирует в metadata, было ли это поле явно передано. В старых snapshots без достоверных сведений null не считается доказательством отсутствия пересылки.

Состояния:

- `needs_review`: правила ещё ни разу не сохранены либо для активного фильтра пересылок нет достоверных данных (если другие известные условия уже не отвергли материал).
- `rejected`: найдено исключение либо не выполнено хотя бы одно заданное условие.
- `approved`: выполнены все условия; явно сохранённые пустые правила разрешают всё.

`SelectionService::saveRules()` блокирует Source, сохраняет версию и пересчитывает существующие автоматические решения батчами по 200 в одной транзакции. Для больших историй это может удерживать lock долго; асинхронный пересчёт следует проектировать отдельно, без publication Queue. Новый импорт и edits повторно оценивают автоматические решения; ручные `decide()` имеют безусловный приоритет и сохраняются после edits/смены правил. UI явно сообщает об этом. Сброс ручного решения в automatic пока не предусмотрен.

Маршруты POST `/w/{workspace}/sources/{source}/selection-rules` и `/w/{workspace}/sources/{source}/items/{item}/selection` требуют sources.manage, workspace membership, действующий consent и CSRF. Внешний item адресуется ULID; проверяется одновременно workspace/source/item. Аудит: source.rules_updated, source.item_approved, source.item_rejected. Публикации, admin, billing и reader не изменены.

Карточка Source содержит форму «Правила отбора», фильтр по трём состояниям, причину и код сработавшего правила, метку ручного решения, кнопки «Принять»/«Отклонить». Вывод экранируется; последние 30 материалов выбираются после фильтрации. Авто- и ручные решения не запускают никакой обработки.

### Проверка 2.3 (2026-10-05)

`make check` — успешно: полный PHP suite 1758 tests / 37136 assertions, PHPStan level 8 + strict rules, стиль, CSS, composer audit, phpDocumentor. Отдельные Sources-тесты: 107 / 540 assertions; среди них 44 новых unit/integration/feature сценария отбора. PCOV: новые четыре доменных класса Selection — 100% строк каждый. Проверены rollback/replay и backfill миграции 22 в app_test; миграция также применена в локальной app. `/healthz` — 200, DB/Redis ok; `css-check` — успешно. UI проверен HTTP feature-тестами, включая состояния, ввод/ошибки, фильтр, ручные действия, escaping, CSRF, consent, permissions и IDOR. Реальное согласие пользователей не принималось автоматически. Telegram auth/live smoke не запускались; reader (включая локальные файлы) совпадает по SHA-256 со снимком перед подэтапом. `stash@{0}` сохранён, коммитов нет. Подэтап 2.3 готов к дальнейшей обработке контента; live Telegram по-прежнему отложен.

### Live smoke 2.3.1 (завершён)

QR + локальная 2FA завершены владельцем. На реальном контролируемом `@sansillusions` internal API, MTProto resolve (peer 3941678860), backfill 10 messages и HTTP ACK→commit подтверждены: 9 логических items; повторный запуск не увеличил число items/messages. Operational logs содержат IDs/статусы, без текста/credentials. После ручного consent владельца UI проверен в workspace Void: @sansillusions и все 9 материалов видны. Исправлено размещение тестовых данных: существующий Source и связанные записи перенесены из demo-workspace id=2 в Void id=1 одной транзакцией с сохранением IDs и содержимого. Изоляция workspace корректна; все 6 пунктов минимального live smoke закрыты. См. [SOURCES_PROGRESS](../../plans/SOURCES_PROGRESS.md).

### Обработка текста 2.4A

Добавлен отдельный [модуль обработки текста](source-text-processing.md), durable история source_text_processings, страница материала и Fake provider через существующий каталог Integrations/Ai. Обработка допускается только для current approved/revision; отбор, reader и publishing не переписаны. Настройки, исходник и результаты хранятся по попыткам отдельно от SourceItem.

### Обработка изображений 2.4B

Отдельный [модуль обработки изображений](source-image-processing.md): approved/revision guards, задания для отдельных фото, перенос лучшего файла через HTTP из reader с durable image_outbox, immutable private originals/variants, техническое качество и консервативная проверка совпадения, UI ручного выбора. Реальный internet search/enhancement пока заменены явными Fake providers. Медиатека/publishing и обработка текста не переписаны.

## Video Generation Core

Approved current materials have independent generation attempts, optional processed-text and selected-image bases, saved settings and manual result selection. The provider is Fake and creates no video. See [Video Generation Core](source-video-processing.md).

## Separate Discovery origin

Radar discovers metadata independently of user Sources. An explicit gateway imports selected excerpts into shared content processing with a separate origin link and needs_review. Source model and Telegram ingestion stay unchanged. See [Content Discovery](content-discovery.md).

## Optional semantic layer (2.6)

[Semantic Selection](semantic-selection.md) runs after deterministic approved in the existing SelectionService. Settings and revision history are separate tables; final decisions remain in source_selection_decisions, manual override wins and semantic off retains prior behavior. Fake provider only; no reader/publishing changes.

## Stage 3.1 — PostDraft boundary

Current approved materials export through ContentDraftService into ordinary PostService drafts, with pinned provenance/revisions and idempotency. Source and input material remain immutable; PostService/Publisher preflight blocks stale or rejected origins. Images enter MediaService at export; Fake video has no publishable file. See [Content drafts](content-drafts.md).

## Stage 3.2 provider adapters

Existing processing/selection interfaces now have opt-in real HTTP adapters, preserved Fake implementations and durable video polling. See [Content real providers](content-real-providers.md) for env, contracts, safety and limitations. No changes to reader or publishing algorithms.

## Automation 3.3

Optional per-Source/Radar policies enqueue the existing Queue through Schedule once a minute; checkpoints and stale/cost guards coordinate existing processing and ContentDraftService. No automatic publishing. [Details and recovery](content-automation.md).


## Review fixes: Telegram connection identity

Migration 32 adds a monotonically increasing `connection_version` to Source and records it on imported items. Changing normalized username increments the generation under a row lock. Reader snapshots carry that generation; stale durable envelopes are quarantined without changing their original payload. Ingress rejects obsolete bindings before writing an event. Current material listings, processing and Draft publication guards require the current connection generation; prior items remain stored for history. Reader photo delivery filters available readers before a bounded per-Source round-robin query, preventing an unavailable Source from occupying the whole batch.


## Independent review fixes — round 2

Migration 33 scopes item/message uniqueness by Source connection generation:
(source_id, connection_version, peer_id, item_key/message_id). Returning A → B → A
creates a current-generation item while retaining prior items and their guards.
Rollback rejects conflicting generations before any DDL; it never merges history.

The reader emits explicit `kind=delete` envelopes with peer_id, message_ids and
UTC deleted_at. The authenticated ingress retains the envelope and message data,
records metadata.deleted_at, rebuilds the active album members and recalculates
revision-bound selection in the same transaction before ACK. Fully deleted items
have status deleted and cannot receive a new manual approval. Partial albums
retain surviving members; the old approval, processing and Draft become stale.
Sticky tombstones also protect against delayed snapshots and ACK-loss reordering.

Semantic completion updates the final Selection inside its commit transaction,
even when a new semantic dispatch would not be allowed outside Automation.
Pending workers recheck the live policy, semantic enablement, revision and settings
version before dispatch. Revoked permission cancels the attempt without provider
execution or a failed job.
