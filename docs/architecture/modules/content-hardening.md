# Content pipeline: эксплуатация и hardening (3.4)

Изменения усиливают существующие Queue/Schedule, workflows, internal API и reader. Нового publishing pipeline нет; автоматизация создаёт только обычный Draft. Новые внешние providers и live-вызовы не добавляются.

## Recovery

- Существующая Queue возвращает abandoned reservation через 15 минут; `content-automation` восстанавливает потерянную постановку через 16 минут. Material/revision/policy keys и MySQL advisory locks исключают параллельную работу и повторный Draft. Worker и scheduler завершают текущий шаг при штатном SIGTERM; обрыв connection освобождает locks.
- Общие content-worker slots ограничивают синхронные automation runs (`CONTENT_CONCURRENCY`, default 2); capacity contention откладывает run, не создаёт новую попытку provider. У каждой revision сохраняются checkpoints и IDs результатов; successful не запускается повторно, stale требует новой revision.
- Hourly `content-maintenance` переводит старые `source_text_processings.processing` и video без remote job ID в failed через `CONTENT_STUCK_SECONDS` (default 3600, минимум 1800). Ошибка безопасная; поздний text result не заменяет terminal recovery status. Не начатые pending video attempts завершаются failed через 24 часа без отправки provider. Незавершённый image transfer завершается failed через 24 часа. Зависшие работы не удаляются и не превращаются автоматически в новый платный вызов.
- Video с сохранённым remote ID остаётся pollable: существующий VideoWorkflow освобождает poll claim после 120 секунд и опрашивает тот же ID. Его общий deadline 15 минут сохранён, после превышения требуется проверка провайдера, а не автоматический повтор start. Незавершённый start без ID считается неоднозначным.
- Reader сохраняет SQLite WAL, message/revision identities, update cursors, durable outbox, image outbox/cache и session. ACK PHP отправляет после сохранения; повтор после потерянного ACK возвращает duplicate. Только HTTP 422 для неисправимого event переводит его в failed quarantine с сохранением payload/identity. Transport/5xx/auth errors остаются pending с ограниченным exponential backoff до 300 секунд. Это намеренно отличается от конечных provider retries: длительная недоступность app не должна терять Telegram events.

## Providers и нагрузка

`ProviderLimits` использует имеющийся Redis: atomic leases на vendor, общий запросный budget (`CONTENT_PROVIDER_PER_MINUTE`, 20), в том числе каждый retry; concurrency 2, lease TTL 120 секунд. После трёх ошибок за короткий период circuit открывается на 60 секунд; успешный вызов сбрасывает failure streak. Redis недоступен → costly HTTP запрещён до восстановления. Один long-lived worker не обходит лимит другого worker/app instance. OpenAI Text/Semantic делят vendor budget, как и Replicate enhancement/video. Fixed UTC minute/hour windows могут давать burst на границе окна; concurrency ограничивает burst. Redis должен быть persistent (AOF в production) и не очищаться для «сброса ошибки».

Video имеет отдельный start budget `CONTENT_VIDEO_PER_HOUR=3`, а workspace — максимум `CONTENT_VIDEO_PENDING=3` pending/processing попытки. GET polling не расходует start budget. Auto video дополнительно требует явного policy opt-in. Очередь фото ограничена 50 queued jobs на Source; повторный запрос того же job не добавляет запись. Дорогие операции выключены по умолчанию.

ProviderHttp: connect 5 секунд / request 20 секунд, bounded JSON 2 MiB, максимум 3 попытки; bounded exponential delay. GET допускает timeout/5xx retry; платный POST не повторяется после timeout/5xx/невалидного ответа, только после явного 429. Long Retry-After возвращает retryable безопасную ошибку вместо сна worker на минуты. Failed costly Text/Semantic/image start уходит в review/failure: автоматически повторять неоднозначный платный результат нельзя. История и pre-call fences сохраняются. Конфигурация real adapter без credentials не ломает app/worker; execution запрещено до HTTP без подмены Fake.

## Retention и storage

`CONTENT_RETENTION_ENABLED=false` по умолчанию. `CONTENT_RETENTION_DAYS=30` (минимум 7). Миграция 31 добавляет `content_storage_objects`: registry immutable keys, время регистрации, deletion tombstone. ImageFiles и real video adapter регистрируют key **до** записи объекта, чтобы crash между storage write и DB workflow commit оставлял обнаруживаемый orphan. Повторный write обновляет registration age.

Очистка:

- максимум 100 зарегистрированных объектов и 100 временных файлов за проход;
- удаляются только старые registry objects **без любых ссылок** из image variants, video result или video basis; проверка повторяется под registry row lock;
- сохраняются все referenced originals, selected/final variants, также старые промежуточные варианты, если они нужны сохранённой processing history. Их возраст сам по себе не основание удалить файл. Files, импортированные в обычную MediaService, обслуживаются её собственной политикой;
- не сканируется весь S3/диск, не удаляются неизвестные legacy objects и другие workspace/media namespaces;
- временные файлы только с известными content prefixes в app `/tmp`, старше retention, regular files без symlinks;
- DB histories, audit, source_events, message identities, processing IDs, remote job IDs и cost fences не удаляются. Непривязанный объект после удаления имеет tombstone; storage failure оставляет registry для повторного прохода.

Reader имеет независимый opt-in `READER_ACK_RETENTION_DAYS=0`; >=7 очищает только payload ACKed events старше срока (до 100 за цикл), оставляя event ID/tombstone для дедупликации. Pending/failed, legacy ACK без timestamp, message revisions, downloads/cache, session не удаляются. Это безопасная минимальная политика, а не TTL всех материалов. Автоматическое удаление referenced промежуточных вариантов и длительных pending events намеренно запрещено; оператор сначала разбирает причину и provenance.

Rollback 31 удаляет только cleanup registry, не сами файлы. Остановите maintenance/worker/reader при deploy/rollback, сохраните backup MySQL + media + Redis AOF + reader state; миграция 30 rollback стирает cost fences, поэтому jobs нельзя запускать до согласованного восстановления. Каталог sessions должен быть private и не публиковаться web-сервером. Ротация internal secret должна быть согласованной с reader.

## Security и monitoring

Internal API использует отдельный bearer secret, constant-time check, ограниченный request body/rate limits; service endpoints исключены из CSRF только с обязательной bearer-проверкой. Workspace UI/processing продолжает действующие permissions/tenant/revision guards. Secret/env/session не передаются в HTML/audit/logs. Source reader heartbeat: POST `/internal/reader-heartbeat`, без content/account/phone/credentials; единственное состояние — последний успешный цикл. Reader молчит при Telegram/auth/HTTP failure; `unknown` не выдумывает подтверждение работоспособности.

SafeDownloads сохраняет HTTPS-only/DNS pinning/SSRF guard, запрет redirect, MIME/magic checks, размер фото 16 MiB / video 50 MiB и pixel limit; response stream закрывается также при ошибке headers. Storage immutable и private. Operational status содержит только counts/timestamps, не тексты постов, storage keys, exceptions или secrets; public `/healthz` остаётся DB/Redis liveness без диагностических подробностей.

Команды в существующей console:

```bash
make console CMD="content:status"       # pending/failed runs, Discovery, last successful run/processing, reader age, provider errors/circuit, queue
make console CMD="content:maintain"     # recovery + dry-run retention
make console CMD="content:maintain --apply" # удаления только при CONTENT_RETENTION_ENABLED=true
```

Reader diagnostics и explicit retry (без Telegram login; reader сначала штатно остановить, SQLite lock не обходить):

```bash
cd tools/telegram-reader
docker compose stop reader
docker compose run --rm reader status
docker compose run --rm reader outbox-retry --event-id EVENT_SHA256
docker compose up -d reader
```

`status` показывает counts и до 10 failed event IDs, без payload. Исправьте причину 422 перед requeue. Другие pending events не удаляйте. Alert triggers: reader age >180 seconds при ожидаемом enabled Source; failed runs/jobs; очередь abandoned >0 длительное время; долго нет успешного шага при наличии approved материалов; открытый circuit/provider errors. Нулевые success при выключенной automation не означают аварию.

Лимиты считаются в запросах/попытках, не в деньгах; price/token metadata реальных adapters сохраняется отдельно. Локальные mocked tests не подтверждают real provider availability/биллинг или production deployment. Exactly-once внешнего платного side effect без provider reconciliation не гарантируется; неопределённые результаты требуют ручной проверки. Final live E2E и deployment readiness остаются 3.5.


## Independent review: durable image operation outcomes

Migration 33 adds status, error_category, attempts, available_at and result_json
to content_automation_calls. A pre-dispatch fence starts as uncertain. Confirmed
retryable failures (429, known safe transient failures/connect timeout) allow at
most five attempts with a 60-second minimum backoff. Successful variants are
archived in result_json, reused if the next step fails and pinned against orphan
cleanup. Existing legacy fences remain uncertain; they cannot silently replay.

An ambiguous paid POST/transport outcome never automatically redispatches. Once
Replicate has created a prediction, even a retryable polling error cannot restart
the complete enhancement operation. It requires reconciliation. Polling uses a
30-second elapsed deadline and checks every received response before applying the
deadline, including a successful final poll. Terminal predictions are not cancelled.
Migration 32 updates only the original material export key; copies retain their
separate idempotency identities while their proven-current selection hash changes.
