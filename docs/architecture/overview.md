# Архитектура: обзор

Чистый PHP 8.3 без фреймворков (ADR 0001). Свой небольшой каркас лежит в `src/Kernel`; бизнес-логика будет жить в `src/Domain`, внешние API в `src/Integrations` (появляются со следующих этапов). Всё запускается в Docker.

## Слои

| Слой | Каталог | Что внутри |
|---|---|---|
| Точки входа | `public/index.php`, `bin/console` | собирают `Application` и передают управление |
| Каркас | `src/Kernel` | контейнер, конфиг, HTTP, роутер, middleware, сессии, БД, очередь, консоль, безопасность, валидация, шаблоны, логи |
| HTTP | `src/Http` | тонкие контроллеры и прикладные middleware (`SecurityHeaders`, `StartSession`, `VerifyCsrf`, `RateLimit`, `Authenticate`) |
| Бизнес-логика | `src/Domain` | `User`, `Auth` (вход, токены, сессии, 2FA), `Workspace` (пространства, команда, роли), `Audit`, `Notification` (очередь писем), `Media` (медиатека); подробности: [modules/auth.md](modules/auth.md), [modules/workspaces.md](modules/workspaces.md), [modules/media.md](modules/media.md) |
| Общее | `src/Support` | `Clock` (время только через него), `Fs`, `DbTime`, `UserAgent` |
| Конфигурация | `config/` | `app`, `database`, `security`, `session` читают env; `routes`, `services`, `schedule` пишутся кодом |
| Шаблоны | `templates/` | Twig: `layouts/` (макеты), `components/` (дизайн-система, [design-system.md](../design/design-system.md)), `dev/` (витрина и прототипы, только `APP_ENV=local`), `errors/` |
| Фронтенд | `resources/css/app.css`, `public/assets/` | Tailwind-сборка `build/app.<hash>.css` (`make css`), `js/` (`theme.js`, `components.js`, `app.js`), `vendor/` (htmx, Alpine), `fonts/` (Inter), `icons/sprite.svg` (Lucide) |

Правила слоёв: контроллер валидирует вход, вызывает сервис и отвечает; к `$_GET/$_POST/$_SESSION` обращается только `Kernel\Http\Request` и `Kernel\Session`; время берётся из `Clock`; зависимости приходят через конструктор.

## Модули каркаса

Минимальный модуль [Sources](modules/sources.md) хранит источники контента отдельно от каналов назначения: workspace-изолированная сущность, список, создание, редактирование и карточка. Изолированный Telegram reader доставляет входящие материалы через внутренний HTTP API с durable outbox; публикации пока отсутствуют.

| Модуль | Классы | Назначение |
|---|---|---|
| Конфигурация | `Env`, `Config` | типизированные геттеры, падение при отсутствии обязательных переменных, запрет `APP_DEBUG` и `DEV_*` в production |
| DI | `Container` | autowiring по типам конструктора, синглтоны, фабрики из `config/services.php`, `make()` с именованными параметрами, `call()` |
| HTTP | `Request`, `Response`, `Router`, `Route`, `UploadedFile`, `RequestContext` | неизменяемые Request/Response, доверенные прокси для IP и схемы, защита от open redirect |
| Middleware | `MiddlewareInterface`, `Pipeline`, `ErrorHandler` | конвейер с собственным интерфейсом |
| Сессии | `Session`, `SessionStore`, `RedisSessionStore` | Redis, ключ — SHA-256 от id, flash, `regenerate()`, idle и absolute таймауты |
| БД | `Connection`, `QueryBuilder`, `Migrator` | PDO с настоящими prepared statements, UTC, вложенные транзакции через savepoint, обратимые миграции |
| Безопасность | `Csrf`, `Csp`, `Crypto`, `Signer`, `RateLimiter`, `PasswordHasher` | см. [security.md](security.md) |
| Сеть | `HttpClientInterface`, `GuzzleHttpClient`, `SsrfGuard` | таймауты 5/20 с, ручные редиректы, SSRF-проверка пользовательских URL |
| Валидация | `Validator`, `Validation`, `Translator` | правила строкой, сообщения на русском, `t()` |
| Шаблоны | `View` | Twig, автоэкранирование, функции `csrf_field`, `csrf_meta`, `csp_nonce`, `asset` (для `app.css` берёт хэшированное имя из `build/manifest.json`), `icon`, `url`, `t`, `old`, `errors` |
| Логи | `LoggerFactory`, `SecretRedactor` | JSON в `storage/logs/app.log` и stderr, секреты маскируются |
| Почта | `Mailer`, `SymfonyMailer`, `MailMessage` | отправка писем через интерфейс; в тестах подменяется `ArrayMailer` |
| Очередь | `Queue`, `Worker`, `Job`, `Schedule` | см. [queue.md](queue.md) |
| Консоль | `Console`, `Command` | `bin/console list` |

Как запрос проходит через эти модули, описано в [request-lifecycle.md](request-lifecycle.md).

## Сервисы Docker (dev)
| Сервис | Назначение |
|---|---|
| `nginx` | Отдаёт `public/`, всё остальное → `index.php`; порт `127.0.0.1:8080` |
| `app` | PHP-FPM 8.3 (образ `dev` с pcov и composer), не от root (uid 1000) |
| `worker` | `bin/console queue:work` |
| `scheduler` | `bin/console schedule:run` |
| `mysql` | основная БД `app` и тестовая `app_test` |
| `redis` | сессии, rate limit, кэш |
| `mailpit` | ловушка писем, `127.0.0.1:8025` |
| `minio` (профиль `s3`), `tunnel` (профиль `tunnel`) | S3 и публичный HTTPS-туннель для ручных проверок |

Целевая структура каталогов, схема данных и конвейер публикации — в `docs/plans/00-master-plan.md` §4.

## Образы
`docker/php/Dockerfile`: `base` → `dev` (pcov, composer) и `vendor` → `prod` (без dev-зависимостей, `opcache.validate_timestamps=0`). Расширения: `pdo_mysql`, `redis`, `sodium`, `intl`, `gd`, `imagick`, `exif`, `zip`, `opcache`, `pcntl` (graceful shutdown воркера). Решение про Debian вместо Alpine: `docs/adr/0002-debian-php-image.md`.

## Тесты
- `tests/Unit` — чистая логика без внешних сервисов (роутер, контейнер, валидатор, Crypto, SsrfGuard…).
- `tests/Integration` — настоящие MySQL (`app_test`) и Redis: миграции, очередь, rate limit, транзакции. `tests/bootstrap.php` один раз за прогон накатывает миграции на `app_test`.
- `tests/Feature` — HTTP через `Application` в одном процессе (`HttpTestCase`: cookie-jar, тестовые маршруты).
- `tests/Support` — `FakeClock`, `MockHttpClient` (падает на незапланированный запрос), `ArraySessionStore`, `TestEnv`.
- Правило PHPStan `RequireClassDocblockRule` (`tools/phpstan/`) требует docblock у каждого класса в `src/`.

Sources: детерминированный отбор и ручные решения изолированы в `Domain/Source/Selection`, хранятся отдельно от входящих item и технического статуса. Алгоритм: сохранение логического item → deterministic selection → approved/rejected/needs_review → будущая Content Processing. AI и publishing не вызываются. Подробнее: [Sources](modules/sources.md).
