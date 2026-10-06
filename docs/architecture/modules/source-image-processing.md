# Sources: обработка изображений (2.4B)

Модуль `Domain/Content/ImageProcessing` независим от `Source`, `TextProcessor`, `MediaService::upload`, PostDraft и publishing. `ImageWorkflow` отвечает за задания, snapshots, историю, ACK и ручной выбор. `ImageAnalysis` выполняет технические проверки. `ImageFiles` архивирует точные исходные байты и создаёт отдельные безопасные превью, переиспользуя существующие `Media/ImageProcessor` и `Integrations/Storage/MediaStorage`.

## Последовательность и ограничения

1. Пользователь принимает актуальную ревизию материала и открывает «Обработка изображений». POST создаёт отдельное задание для каждого photo-message, в том числе каждого участника альбома. Активное queued-задание того же message/revision/selection не дублируется. Повтор после завершения — новая попытка, старые результаты сохраняются.
2. Сохраняются revision SHA-256 всего item со всеми messages и hash решения отбора. Сервер запрещает запуск для rejected/needs_review, прошлой ревизии и выключенного Source. До сетевой работы и перед commit повторно проверяются ревизия, решение и enabled.
3. Reader получает задания через `GET /internal/source-image-jobs`. Он использует существующий `Reader.download_once` и Telethon transport: свежие messages по ID, проверка photo_id, максимальный PhotoSize по площади/числу байт, а не stripped thumbnail. File reference/access hash не передаются в PHP. Оригинал кэшируется по SHA-256.
4. `ImageDelivery` сохраняет задание и подготовленный результат в собственной SQLite `image_outbox`. Результат хранит приватный путь, а не base64 в БД; bytes/hash повторно проверяются перед передачей. Только предложенные PHP актуальные задания могут начинать download. Выключенные Sources игнорируются, потерянный cache перекачивается, ACK/error повторяются с backoff после restart. Обработка одного аккаунта/session сохранена.
5. `POST /internal/source-image-results` передаёт job_id, peer_id/message_id/photo_id, SHA-256 и base64 файла. Оба endpoints защищены существующим **отдельным SOURCES_READER_SECRET**, без браузера и CSRF; имеют отдельные rate limits. ACK только после записи файлов и transaction commit; повтор job возвращает сохранённый статус без новых variants. Терминальные ошибки/stale также записываются до ACK. Storage/DB failures → безопасный 503 и повтор доставки.
6. PHP проверяет identity, digest, MIME по содержимому, максимальный размер и advertised selected dimensions: случайный thumbnail меньше Telegram selected PhotoSize отклоняется. Исходные bytes архивируются неизменно, превью WEBP отдельно пересжимаются/очищаются существующим ImageProcessor. Raw archives находятся вне public и через браузер не отдаются.
7. Для insufficient quality вызываются заменяемые ImageSearchProvider и ImageEnhancementProvider. Кандидаты проходят decode/limits, сохраняются только с большей площадью, provenance и диагностикой соответствия. Оригинал всегда первоначально выбран; **кандидаты и enhanced никогда не выбираются автоматически**. Для candidate без verified требуется явное ручное подтверждение визуального совпадения. Ручной выбор проверяет workspace/source/item/run и актуальный approved snapshot.

Pixel/provider работа находится вне Source/DB lock. Финальные проверки и сохранение коротко блокируют Source; workspace lock защищает суммарный лимит хранения от конкурентных заданий разных Sources. Дубли и отброшенные результаты удаляют свои незакоммиченные архивы; предыдущие результаты не уничтожаются.

## Техническое качество и проверка совпадения

Версия метрик 1: размеры с учётом EXIF orientation, MIME, bytes, JPEG quality estimate из квантования Imagick, bits/pixel, контраст и variance дискретного Laplacian на нормализованном 128×128 grayscale. PNG/JPEG/WEBP; фото до 16 MiB и 40 MP, существующий maxSide 10000. Значения good/acceptable/poor — **технические эвристики**, а не эстетическая/смысловая оценка.

- poor: меньшая сторона <400 px, JPEG estimate >0 и <45, либо textured фото с blur variance <8.
- good: меньшая сторона ≥1000 px, contrast >0.04 и blur variance ≥20, без poor условий.
- остальные acceptable; низкая фактурность отдельно отмечается, чтобы не объявлять однотонную графику размытой.

64-bit difference hash (dHash, perceptual hash) — первичный критерий; дополнительно normalized 32×32 RGB RMSE и aspect-ratio delta. Для прозрачных изображений анализируется видимый результат на белом фоне, чтобы hidden RGB пустого прозрачного PNG не совпал с непрозрачным оригиналом. verified: Hamming ≤4, RMSE <0.045, log aspect delta <0.02, оба изображения не low-texture. Мismatch: Hamming >16 либо RMSE >0.2 либо aspect delta >0.15; остальные needs_review. Confidence — технический составной score, **не вероятность и не доказательство идентичности/прав на изображение**. Сохраняются score и метрики. Коллизии hash недостаточны: unit test проверяет, что независимая проверка цвета отклоняет кандидата даже при совпавшем hash. Crops, логотипы, текст на картинке и сложные редактирования могут требовать ручной проверки; поэтому автозамены нет.

Максимум 5 кандидатов на фото, 16 MiB каждый, 512 MiB архивных variant bytes на workspace (независимый защитный лимит прототипа, без изменения тарифов/billing; превью дополнительно занимают место). История в UI — последние 100 заданий; записи не удаляются автоматически.

## Таблицы и UI

Миграция `2026_10_06_000024_create_source_image_processing.php`:

- `source_image_processings`: ULID, workspace/source/item/message FK, исходные Telegram peer/message/photo IDs, revision_hash/selection_hash, queued/completed/failed/stale, безопасный error, выбранный variant ULID, actor и UTC timestamps.
- `source_image_variants`: ULID, workspace/run FK, original/candidate/enhanced, отдельные private storage/preview keys, SHA-256, dimensions/MIME/bytes, quality/metrics snapshot с perceptual hash, public provenance URL, verification/confidence/diagnostics, provider и timestamp.

SourceItem, selection status и text attempts не перезаписываются. Все workspace queries scoped; URLs используют ULID. Sources permissions, consent, CSRF, PRG и audit сохранены. Audit/analytics/daily metrics содержат только IDs, тип варианта, count/status; без текста, binary или credentials.

GET `/w/{workspace}/sources/{source}/items/{item}/images`: оригинал, его dimensions/quality, найденные и enhanced variants, provenance, verification/confidence, выбранная версия, история и ошибки. POST этого URL — создать задания; POST `/select` — ручной выбор; GET `/{variant}/preview` — только очищенное private WEBP с проверкой принадлежности Source и material. На странице текста — ссылка «Обработка изображений». Формы блокируют повторный submit; выключенный/неодобренный Source не запускает download.

## Реальные и Fake части

**Реальны:** best PhotoSize download через существующий Telethon, immutable cache/outbox, HTTP binary transfer, persistence/dedup, Imagick inspection/quality/matching/sanitized previews, multiple photos и ручной UI. Автономные и настоящий Python↔PHP HTTP-тесты используют синтетические фотографии; live download реального approved Source в 2.4B отдельно не запускался, Telegram login/session не менялись.

**Fake:** `FakeImageSearchProvider` по умолчанию возвращает пустой список без интернет-запросов; тесты подставляют encoded кандидатов. `FakeImageEnhancementProvider` в dev/test сохраняет отдельную byte-for-byte copy без улучшения качества, в production возвращает null. UI явно сообщает это. Генерации новых изображений нет.

Для реального поиска нужен адаптер лицензированного reverse-image API, его серверные credentials, лимиты/timeout/retry, SSRF-guard на скачивание результатов (включая redirects/DNS), проверка публичного provenance URL без credential/query tokens и прав использования. Заменяется DI binding, бизнес-логика не зависит от поставщика. Реальный enhancement подключается отдельным интерфейсом, также с безопасными limits/timeout и тестовыми fixtures. Реальные провайдеры могут потребовать выноса длительной обработки в отдельный content worker; publication Queue не используется.

Production hardening ещё требует retention/pruning локального reader cache/outbox и архивов после удаления workspace/Source, общего quota accounting с превью, наблюдаемости и нагрузочных проверок. Оригиналы могут содержать исходный EXIF и private archive никогда не публикуется напрямую; будущий перенос в MediaService должен использовать его sanitation. Ни PostDraft, ни публикации, ни поиск/AI реального сервиса сейчас не подключены.

## Проверки и визуальные fixtures

- PHP Unit/SQL/Feature: `tests/Unit/Content/ImageAnalysisTest.php`, `ImageProvidersTest.php`, `tests/Feature/Source/SourceImageProcessingTest.php`; временные archives в ArrayMediaStorage. Full suite также проверяет rollback/replay Sources/incoming/новой миграции.
- Reader: `tests/test_image_delivery.py` — несколько фото, max PhotoSize, hash, ACK loss/restart, отсутствующий cache, неактуальное approved задание, заменённое Telegram photo. `tests/run_php_contract.sh` — два реальных HTTP contracts на app_test с Fake transport; generated files в `storage/testing/source-image-contract`.
- UI: `tools/ui-snap/seed-source-images.php` создаёт только synthetic app_test workspace/material; private files в `storage/testing/source-image-ui`. `tools/ui-snap/router.php` — Docker PHP built-in server adapter для dynamic URLs с точками и static assets, основной nginx/router не изменяются. Сервер запускается только на localhost отдельным disposable контейнером с DB_DATABASE=app_test, REDIS_DB=14, APP_ENV=local и MEDIA_LOCAL_ROOT=storage/testing/source-image-ui. Каталог stage-sources-images проверяет альбом 375/768/1440 × light/dark, axe дополнительно 360. Не запускать seeder/UI/HTTP contracts одновременно с PHP suite.
