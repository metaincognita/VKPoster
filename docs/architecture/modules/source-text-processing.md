# Sources 2.4A — обработка текста

Реализован отдельный модуль `Domain/Content/Processing` на базе stage 20. Проверенный upstream/main — `6219ebcc`; существующей AI/text-processing реализации нет (`Integrations/Ai` был пустым каталогом, этап 17 ещё TODO). Платформенный Post TextFormatter предназначен для публикации и не заменяет редактуру. Publishing и reader не изменены.

## Границы и поток

`approved SourceItem → ContentProcessor → TextProcessor → TextProvider → отдельная попытка обработки`.

- `MaterialRepository` ограничивает каждый запрос workspace/source/item. Ревизия — SHA-256 текста, типа, peer/group и упорядоченных messages с исходными IDs, entities/media/forward metadata, датами и revision_hash. Поэтому добавление сообщения в альбом или изменение entities тоже делает прошлый результат неактуальным. Технические updated_at сообщений в hash не входят.
- `ContentProcessor::process` под блокировкой Source проверяет **текущий approved** и присланную ревизию; создаёт durable попытку `processing` со snapshot настроек и оригиналом. `rejected`, `needs_review`, чужой Source/item или устаревшая ревизия не вызывают provider и не создают попытку.
- Вызов провайдера выполняется вне транзакции и блокировки. Перед завершением снова проверяются ревизия и snapshot решения отбора. Изменение любого из них даёт `stale`; такой результат сохраняется как история, но не считается актуальным.
- Даже завершённая попытка в UI считается актуальной только при совпадении ревизии, решения и current approved. Ручное approval после Telegram edit работает по существующей семантике Selection: manual decision сохраняется, но обрабатывать можно только новое содержимое, не старую форму.
- Каждая повторная обработка — новая запись и следующий settings_version **в пределах item**, даже при тех же настройках. Старые результаты не удаляются; UI показывает последние 50. `source_items` и `source_messages` не перезаписываются.

## Настройки и провайдер

`TextSettings` валидирует режим `unchanged/edit/rewrite/shorten/custom`, язык `original/ru/en/uk/de/fr/es`, длину 1–20000 Unicode-символов, keep/remove ссылок и упоминаний текущего источника, инструкцию до 4000 символов (обязательна для custom), до 100 запрещённых фраз по 100 символов. Все настройки сохраняются отдельно в каждой попытке.

`Integrations/Ai/TextProvider` — интерфейс, `FakeTextProvider` — автономная реализация без HTTP, credentials и SDK. DI binding находится в `config/services.php`. Fake отключён в production; unchanged обрабатывается локально и не вызывает provider. Fake edit нормализует пробелы, rewrite/custom дают явно тестовый результат, shorten сокращает. **Перевод и смысловая редактура/выполнение инструкции не реализованы Fake**; UI явно предупреждает об этом. Язык и инструкция передаются через интерфейс и сохраняются для будущего адаптера.

`TextProcessor` детерминированно применяет ограничения до/после вызова провайдера. Без изменений сохраняет текст, за исключением явно заданных ограничений и представления скрытых ссылок. Результат — plain text: сохранённая скрытая `MessageEntityTextUrl` преобразуется в `подпись (URL)`; при удалении ссылок остаётся подпись. Удаление атрибуции текущего источника учитывает @username, t.me/telegram.me и скрытые ссылки с UTF-16 offsets Telegram. Упоминания других каналов не удаляются этим правилом. Запрещённые фразы удаляются без регистра как подстроки до устойчивого результата. Длина ограничивается после обработки. HTML не создаётся и в UI экранируется.

## Хранилище

Миграция `2026_10_05_000023_create_source_text_processings.php`: единственная новая таблица `source_text_processings`.

Поля: id/public_id, workspace_id/source_id/item_id, revision_hash/selection_hash, original_text/processed_text, mode, settings_version, settings_json, status, error, provider, created_by, created_at/updated_at/finished_at (UTC). created_at — начало попытки. UNIQUE(item_id, settings_version); индекс workspace/source/item/id; FK CASCADE workspace/source/item, actor SET NULL. Обратимый down удаляет только историю обработки. Исходные источники, материалы и отбор не меняются.

Статусы `processing/completed/failed/stale` независимы от selection_status и SourceItem stored. Ошибка — фиксированное безопасное сообщение, не сырой ответ/exception провайдера. Ни текст, ни инструкция, ни credentials не попадают в operational logs/audit/analytics.

## HTTP и UI

- GET `/w/{workspace}/sources/{source}/items/{item}`: исходный текст, настройки, инструкция, история результатов и ошибок. Право sources.view, workspace isolation, consent.
- POST того же пути `/process`: sources.manage, CSRF, отдельный rate limit 30 запросов/минуту, revision guard. PRG, сохранение невалидного ввода, focus на ошибке, disabled/loading submit. Для непринятых материалов кнопка отключена и сервер также отказывает.
- «Открыть материал» добавлено в список полученных материалов Source. Результаты прошлых ревизий явно помечаются.
- Audit: source.text_started/completed/failed/stale. Анонимные технические outcomes — analytics_events; дневная метрика source_text_attempts по status. В существующей админке `/admin/stats` под прежним разрешением только агрегатные счётчики, без текстов/инструкций; никаких изменений billing/publishing.

## Проверки и ограничения

Unit/integration/feature проверяют режимы, Unicode/UTF-16 links, ограничения после provider, snapshots/history, refusal до запуска, правки и rejection во время вызова, безопасные ошибки, IDOR, роли, CSRF, consent, escaping и rollback/replay. Новые доменные классы имеют 95.83–100% строк PCOV; Fake 100%.

UI smoke `make ui-snap STAGE=sources-text` использует существующий локальный test@example.com / Void / Telegram live smoke с вручную принятым consent. Нужен ранее импортированный материал; команда не создаёт Telegram данные, не принимает юридический consent и не запускает обработку. Снимки 375/768/1440 light/dark просмотрены, axe на 360/1440: без serious/critical и горизонтального overflow. Реальный Source сохраняет текущие решения отбора.

Обработка сейчас синхронна в HTTP. При аварийном прекращении процесса попытка может остаться processing; повторный запуск создаёт новую запись и не удаляет её. Автоматический recovery, retention и async jobs не входят в 2.4A. Real AI потребует отдельного адаптера TextProvider с bounded timeout/output, безопасными prompts (контент как данные), credentials через локальный env/secret manager и DI config. Выбор провайдера/модели и живой тест остаются отдельным шагом. Никакого нового PostDraft, медиа или автоматической публикации нет.
