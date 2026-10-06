# Конфигурация (переменные окружения)

Источник правды — `.env.example`. `make init` копирует его в `.env` и генерирует `APP_KEY`. Реальные значения секретов в git не попадают.

| Переменная | По умолчанию | Описание |
|---|---|---|
| `APP_ENV` | `local` | `local` · `testing` · `production`. Dev-фичи (DEV_LOGIN, Fake-адаптеры) только при `local` |
| `APP_URL` | `http://localhost:8080` | Базовый URL (ссылки в письмах, OAuth-коллбеки) |
| `APP_NAME` | `ezposter` | Название продукта |
| `APP_DEBUG` | `0` | Показывать детали ошибок (только local/testing). В production приложение не запустится при `1` |
| `APP_KEY` | — | **Обязательна.** Ключ libsodium `secretbox` в base64 (32 байта). Шифрует токены соцсетей и TOTP, из него выводится ключ `Signer` |
| `APP_KEY_ID` | `k1` | Идентификатор текущего ключа в шифртексте (`v1:<id>:…`) |
| `APP_KEYS` | — | Старые ключи для расшифровки после ротации: `id:base64,id:base64` (см. `security.md`) |
| `TRUSTED_PROXIES` | — | IP и CIDR обратных прокси через запятую; только от них принимаются `X-Forwarded-For/Proto`. `*` не поддерживается намеренно |
| `LOG_LEVEL` | `info` | `debug` · `info` · `notice` · `warning` · `error` · `critical` |
| `LOG_DISABLE_STDERR` | — | `1` отключает дублирование логов в stderr (нужно только тестам) |
| `SESSION_IDLE_TTL` | `7200` | Секунд бездействия до конца сессии |
| `SESSION_ABSOLUTE_TTL` | `2592000` | Максимальная жизнь сессии (30 дней) |
| `ARGON_MEMORY_KIB` `ARGON_TIME_COST` | `65536` `4` | Параметры Argon2id (в тестах занижены) |
| `DEV_*` | — | Любой флаг `DEV_*` со значением `1/true/yes/on` запрещён в production (приложение не запустится) |
| `DB_HOST` `DB_PORT` | — `3306` | MySQL. `DB_HOST` обязательна |
| `DB_DATABASE` | — | **Обязательна.** Имя БД (в тестах `app_test`, задаётся `phpunit.xml`) |
| `DB_USERNAME` `DB_PASSWORD` | — | Доступ приложения к БД. `DB_USERNAME` обязательна |
| `DB_ROOT_PASSWORD` | `root` | Только для инициализации контейнера MySQL (dev) |
| `REDIS_HOST` `REDIS_PORT` | — `6379` | Redis. `REDIS_HOST` обязательна |
| `REDIS_DB` | `0` | Номер базы Redis (тесты используют 15) |
| `MAIL_HOST` `MAIL_PORT` | `mailpit` `1025` | SMTP (в dev — Mailpit) |
| `MAIL_FROM` `MAIL_FROM_NAME` | | Адрес и имя отправителя |
| `SUPPORT_EMAIL` | `MAIL_FROM` | Ящик, куда приходят сообщения «Сообщить о проблеме» |
| `HTTP_PORT` | `8080` | Только `compose.prod.yaml`: порт nginx на `127.0.0.1` за обратным прокси |
| `MAIL_DSN` | — | Полный DSN почтового транспорта (`smtp://user:pass@host:587`); если задан, `MAIL_HOST`/`MAIL_PORT` игнорируются |
| `DEV_LOGIN` | `1` | `/dev/login-as/{id или email}`: вход без пароля. Работает только при `APP_ENV=local` (и в тестах); при `DEV_LOGIN=1` и `APP_ENV=production` приложение не запустится |
| `PASSWORD_HIBP` | `0` | `1` включает проверку новых паролей в Have I Been Pwned (отправляются только 5 символов SHA-1; при недоступности сервиса проверка пропускается) |
| `REMEMBER_DAYS` | `30` | Срок жизни cookie «Запомнить меня» |
| `VKID_CLIENT_ID` `VKID_CLIENT_SECRET` | — | VK ID (id.vk.com). Без `VKID_CLIENT_ID` кнопка скрыта; секрет необязателен для потока с PKCE |
| `YANDEX_CLIENT_ID` `YANDEX_CLIENT_SECRET` | — | Яндекс ID; нужны оба значения |
| `GOOGLE_CLIENT_ID` `GOOGLE_CLIENT_SECRET` | — | Google (OIDC); нужны оба значения |
| `TELEGRAM_LOGIN_BOT_TOKEN` `TELEGRAM_LOGIN_BOT_NAME` | — | Бот для Telegram Login Widget (имя без `@`); нужны оба значения. В BotFather выполните `/setdomain` с публичным доменом |
| `OAUTH_ORDER` | `vkid,yandex,telegram,google` | Порядок кнопок входа; не перечисленные в списке провайдеры идут в конце |
| `DEV_OAUTH_FAKE` | `0` | `1` включает встроенного тестового провайдера `/dev/oauth/fake`. Префикс `DEV_`: при `APP_ENV=production` приложение не запустится |
| `PLATFORMS_ENABLED` | `telegram` | Флаги платформ через запятую (`telegram`, `vk`, `max`, `instagram`); выключенная платформа не видна в интерфейсе. `fake` (тестовая сеть) работает только вне production |
| `VK_CLIENT_ID` `VK_CLIENT_SECRET` | `VKID_*` | Приложение VK ID для публикации в сообщества (права на стену); адрес возврата `<APP_URL>/channels/connect/vk/callback`. Включается флагом `vk` в `PLATFORMS_ENABLED`. См. [modules/vk.md](modules/vk.md) |
| `VK_SCOPE` | `wall photos video docs groups` | Права, которые просит подключение VK |
| `VK_POSTS_PER_DAY` | `50` | Суточный лимит постов одного сообщества для предупреждения при планировании |
| `MAX_BOT_TOKEN` | — | Общий бот MAX (бот верифицированного юрлица, business.max.ru). Без него работает только режим «свой бот». Включается флагом `max` в `PLATFORMS_ENABLED` |
| `MAX_BOT_USERNAME` | — | Имя бота MAX без `@` для страницы подключения (иначе спрашивается у MAX и кэшируется) |
| `MAX_WEBHOOK_SECRET` | — | 16–128 символов `A-Za-z0-9_-`: часть адреса вебхука `/webhooks/max/{secret}`; из него выводится секретный заголовок. Задать, затем `max:webhook set https://публичный-хост` |
| `MAX_API_BASE` | `https://platform-api2.max.ru` | Адрес MAX API (менять не нужно) |
| `TELEGRAM_BOT_TOKEN` | — | Общий бот сервиса (создаётся в @BotFather); без него подключение «через бота сервиса» недоступно, «свой бот» работает |
| `TELEGRAM_BOT_USERNAME` | — | Имя бота без `@` для подсказки на странице подключения; если пусто, спрашивается у Telegram и кэшируется на час |
| `TELEGRAM_WEBHOOK_SECRET` | — | 16–128 символов `A-Za-z0-9_-`: секретная часть адреса вебхука; из него же выводится значение заголовка `X-Telegram-Bot-Api-Secret-Token`. Без него вебхук отвечает 404 |
| `CHANNEL_PREFLIGHT_MINUTES` | `15` | Канал проверяется прямо перед публикацией, если последняя проверка старше этого числа минут |
| `CHANNELS_MAX` | `1000` | Глобальный потолок числа каналов в пространстве (с паузой включительно); лимит тарифа считает только активные |
| `S3_KEY` `S3_SECRET` | `minioadmin` | Доступ к MinIO (профиль `s3`) и ключи S3 для `MEDIA_DISK=s3` |
| `MEDIA_DISK` | `local` | Где хранить файлы медиатеки: `local` (`MEDIA_LOCAL_ROOT`) или `s3` |
| `MEDIA_LOCAL_ROOT` | `storage/media` | Каталог локального хранилища (относительно проекта или абсолютный); вне docroot nginx |
| `S3_ENDPOINT` `S3_BUCKET` `S3_REGION` `S3_PATH_STYLE` | — / `ezposter` / `us-east-1` / `1` | Адрес (для MinIO `http://minio:9000`), бакет, регион и стиль адресов S3; нужны при `MEDIA_DISK=s3` |
| `MEDIA_MAX_FILE_MB` | `50` | Максимальный размер одного файла (должен быть не больше `upload_max_filesize` в `docker/php/php.ini` и `client_max_body_size` в nginx) |
| `MEDIA_QUOTA_MB` | `0` | Необязательное глобальное ограничение размера медиатеки сверху (0 = нет); размер даёт тариф |
| `MEDIA_MAX_VIDEO_SECONDS` | `900` | Максимальная длина видео |
| `MEDIA_URL_TIMEOUT` | `30` | Лимит времени (секунды) на загрузку файла по ссылке |
| `MEDIA_SIGNED_URL_TTL` | `3600` | Срок жизни подписанных ссылок на файлы по умолчанию (секунды) |

## Биллинг (этап 10)
| Переменная | По умолчанию | Назначение |
|---|---|---|
| `BILLING_GATEWAYS` | `yookassa,tbank` | Способы оплаты на странице тарифов; шлюз без ключей не показывается; `fake` (тестовая страница оплаты) добавляется сам вне production |
| `YOOKASSA_SHOP_ID` `YOOKASSA_SECRET_KEY` | — | Магазин ЮKassa (тестовый или боевой). Адрес уведомлений в кабинете: `<APP_URL>/webhooks/billing/yookassa` |
| `YOOKASSA_VERIFY_IP` | `1` | Проверка адреса отправителя уведомлений; `0` только вне production (туннель). Платёж в любом случае перечитывается из API |
| `TBANK_TERMINAL_KEY` `TBANK_PASSWORD` | — | Терминал интернет-эквайринга Т-Банка; пароль подписывает запросы. Адрес уведомлений уходит в `Init` сам: `<APP_URL>/webhooks/billing/tbank` |
| `YOOKASSA_API_BASE` `TBANK_API_BASE` | боевые адреса | Менять не нужно |
| `BILLING_TAX_SYSTEM` | `npd` | Налогообложение: `npd` (самозанятый: чек провайдеру не передаётся), `osn`, `usn_income`, `usn_income_outcome`, `patent`, `envd`, `esn` |
| `BILLING_VAT` | `none` | Ставка НДС в чеках: `none`, `vat0`, `vat10`, `vat20`, `vat22` |
| `BILLING_SELLER_NAME` `BILLING_SELLER_INN` | — | Продавец на PDF-квитанции (пусто: строка не печатается) |
| `BILLING_RECEIPT_FONT` | DejaVu Sans из образа | TrueType-шрифт с кириллицей для квитанции |

Цены, лимиты, пробный период (14 дней Pro), лесенка повторов (за 3 дня до конца, через 1 и 3 дня, затем ежедневно все 3 дня отсрочки) лежат в `config/billing.php`; цены и лимиты после первой миграции живут в БД.

Обязательные переменные проверяются при старте (`Config::load`): если чего-то нет, приложение и `bin/console` не запускаются, а в журнал попадает имя переменной без значения. В `config/*.php` env читается только через `Env`; прямых `getenv()` в коде нет.


## Админка (этап 20)

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `ADMIN_IP_ALLOWLIST` | пусто | Адреса и сети через запятую; если задано, `/admin` отвечает 404 всем остальным |
| `APP_VERSION`, `APP_DEPLOYED_AT` | пусто | Что выкачено; показывается на странице «Состояние системы» (ставит деплой) |

Остальное, что владелец меняет без выкладки, лежит в таблице `app_settings` (кэш в Redis): `site.maintenance`, `site.maintenance_message`, `site.registration`, `site.support_email`, `site.support_telegram`, `site.requisites`, `limits.max_workspaces_per_user`, `platforms.off`, `status.notices`, `design.colors`, `finance.fee_percent`, `report.*`, `campaigns.per_minute`, `metrics.refreshed_at`. Секретов там нет.

## Внутренний Sources reader

`SOURCES_READER_SECRET` — отдельный общий секрет PHP/reader, минимум 32 случайных символа; пустое значение выключает API. В конфигурации reader: `VKPOSTER_INTERNAL_URL` (локально `http://host.docker.internal:8080`). Telegram API credentials, телефон и session остаются только у reader. Не передавать их браузеру или PHP. После изменения `.env` пересоздать app; session volume reader сохраняется.

### Content provider opt-in

`config/content_providers.php` reads `CONTENT_TEXT_PROVIDER`, `CONTENT_SEMANTIC_PROVIDER` (fake/openai), `CONTENT_IMAGE_SEARCH_PROVIDER` (fake/tineye), `CONTENT_IMAGE_ENHANCEMENT_PROVIDER` (fake/disabled/replicate), `CONTENT_VIDEO_PROVIDER` (fake/replicate). All default to fake. Credentials: `OPENAI_API_KEY`, `TINEYE_API_KEY`, `REPLICATE_API_TOKEN`; configurable `OPENAI_CONTENT_MODEL`. See [real provider operations](modules/content-real-providers.md).

Stage 3.2 is accepted on mocked HTTP/contracts. Real integrations need explicit selection plus corresponding env credentials; absent credentials prevent activation. OpenAI/TinEye/Replicate live smoke is deferred to Stage 3.5; Fake remains the dev/test default.

Content hardening env: `CONTENT_CONCURRENCY=2`, `CONTENT_PROVIDER_PER_MINUTE=20`, `CONTENT_VIDEO_PER_HOUR=3`, `CONTENT_VIDEO_PENDING=3`, `CONTENT_STUCK_SECONDS=3600`; cleanup disabled via `CONTENT_RETENTION_ENABLED=false`, `CONTENT_RETENTION_DAYS=30`. Reader independently defaults `READER_ACK_RETENTION_DAYS=0`. See [limits/recovery/retention](modules/content-hardening.md).
