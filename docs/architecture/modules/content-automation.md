# Автоматизация Content Core — 3.3

Source и Discovery остаются разными источниками материала. Автоматизация использует существующие `Schedule`, `Queue`, `Worker`, Selection, processing workflows и `ContentDraftService`. Нового publisher или job framework нет. Финальное действие — обычный редактируемый PostDraft; публикации и задания publish не создаются.

## Политика

`content_automation_settings`: workspace + scope (`source:<id>` / `radar`), version, JSON settings, actor, timestamps, время следующего Discovery run и keyset scan cursor. Все автоматические/дорогие шаги по умолчанию выключены. Manual review fallback включён. Параметры: enabled, auto_selection, semantic_selection, auto_text_processing, auto_image_processing, auto_video_generation, auto_draft, manual_review_fallback. Радар дополнительно: discovery_enabled, interval_minutes (1–1440), provider_types (telegram/web/social), candidate_limit (1–100). Текст: unchanged/edit/rewrite/shorten; для тонкой настройки остаётся ручной Processing UI.

Semantic switch автоматизации разрешает существующую semantic policy, а не создаёт вторые критерии. Сначала сохраните критерии в существующем блоке смыслового отбора. Без критериев задача требует проверки. Для настроенной automation policy ingress не делает semantic HTTP: вызов откладывается до worker. Ручное решение имеет приоритет. Без automation policy прежний Selection flow сохранён. При auto_selection=false учитывается уже существующее решение; rejected/needs_review не проходят обработку.

## Планировщик и jobs

`content-automation` — раз в минуту в `config/schedule.php`. `Automation::tick()` просматривает по 100 материалов за policy через циклический keyset cursor, чтобы старые материалы не голодали. Enabled Telegram Source доступен reader независимо от enabled automation. Reader уже имеет постоянный цикл (10 секунд), durable outbox, update-state/cursors, restart recovery и идемпотентный internal API; его алгоритмы и session не меняются.

`AutomationJob` в существующей default queue содержит только run ID. `content_automation_runs` хранит revision/selection hashes, policy snapshot/version, status, текущий checkpoint, последний успешный шаг, attempts, выбранные processing IDs, draft ID, queued/available/finished timestamps и безопасную ошибку. Unique key на материал/revision/policy version; повторные scheduler/worker вызовы не создают второй run или draft. Discovery runs используют slot key и атомарно обновляют next_discovery_at. Fake Discovery запрещён в production; типы providers и лимит новых кандидатов передаются существующему ContentDiscovery. Импорт выполняется через DiscoveryMaterialGateway, а не через PostDraft.

После Selection выполняется text, затем запрос фото через существующий ImageWorkflow/internal API/reader. Coordinator ожидает завершения всех фотографий; текущая выбранная версия используется при создании draft. Незавершённый image job не создаётся повторно. Video только с явным auto_video_generation=true: один сохранённый generation ID, remote job ID и повторный poll того же задания через существующий VideoWorkflow. Fake video остаётся демонстрацией и не прикрепляется как файл. Discovery Core пока text-only: запрос video для него требует ручной проверки.

Без auto_draft материал получает технический automation status needs_review и остаётся для ручного создания черновика; selection status approved при этом сохраняется. Без выбранного актуального текста/изображений draft требует проверки, даже если auto_draft=true. Источник, selection decision и processing histories не перезаписываются.

## Recovery и cost guards

MySQL advisory lock на workspace/material охватывает работу всех версий policy, без SQL-транзакции на весь pipeline; при разрыве worker connection освобождается. Каждый внешний шаг и draft проверяют актуальность revision, selection, policy version и Source enabled. Новая revision — новый run; старая блокируется. Выключенный Source приостанавливает queued run; повторное включение возобновляет checkpoints. Отдельные истории processing проверяют revision/selection ещё раз на commit.

Queue даёт 5 попыток и backoff 60/300/900/3600 секунд; safe errors без содержимого/credentials. Scheduler восстанавливает потерянную постановку спустя 16 минут; Queue visibility timeout — 15 минут. Ожидание reader/video повторно проверяется через минуту, максимум 24 часа. Последующие waits не запускают новый provider job. Successful results переиспользуются. Изменённое selection decision возобновляет review/rejected/completed/stale run для той же revision; старые processing результаты и draft origins сохраняются отдельно, новая selection hash требует свежих результатов. Неизменённое решение не запускает работу повторно. Неопределённый text/semantic start отправляется на проверку без автоматического платного replay. Failed runs не запускаются бесконечно; новая policy version или ручная обработка — осознанная граница повторной попытки.

`AutomationGuard` проверяет выбор provider, credentials, отсутствие Fake в production. Missing credentials теперь не ломают старт всего приложения: adapter отказывает до HTTP, automation завершает материал в needs_review (или failed при выключенном fallback), не подменяя ответ Fake. Credentials сами не включают реальные providers. Никаких ключей не добавлено, live проверки отложены до 3.5.

`content_automation_calls` — durable pre-call fence для image search/enhancement (workspace, operation key, operation, timestamp). Image ACK может повторяться; advisory lock исключает одновременный provider вызов, fence исключает новый платный вызов после потерянного ответа/DB commit. Неопределённый результат требует ручной reconciliation; исходник остаётся сохранён отдельно. Внешняя система не предоставляет общей транзакции с MySQL: exactly-once платного вызова гарантировать нельзя. Архивные orphan-файлы, бюджеты/retention, semantic transaction lock duration и deployment coordination остаются для 3.4.

## UI и запуск

Блок «Автоматизация» на Source и в Радаре: opt-ins, discovery frequency/types/limit, последнее выполнение, последний успешный шаг и безопасная причина остановки. Workspace permissions sources.manage / discovery.manage; POST + CSRF; аудит content.automation_settings_updated. Доступ чужого workspace — 404, недостаточная роль — 403. Ручные selection/processing/draft действия остаются.

Стек уже запускает worker (`publish,default`) и scheduler. После обновления кода:

```bash
make migrate
docker compose up -d --force-recreate worker scheduler
# Reader использует существующую session и собственный volume; новую авторизацию не запускать.
cd tools/telegram-reader
docker compose up -d reader
```

Не применяйте rollback при активных automation/provider jobs в production. Migration 30 обратима; rollback удаляет automation policies/runs/call fences, поэтому требует остановки workers и координации восстановления перед повторным запуском. Проверки rollback/replay выполняются на app_test.

Hardening 3.4 adds shared concurrency/request budgets, recovery maintenance, opt-in orphan retention and operational metrics: [operations guide](content-hardening.md).
