# VKPoster

Сервис отложенного постинга в VK, MAX, Telegram и Instagram. Чистый PHP 8.3 (без фреймворков), MySQL 8, Redis, всё запускается в Docker. Интерфейс на русском.

> Репозиторий переписывается с нуля по плану из [docs/plans/](docs/plans/). Старый код доступен в теге `legacy-v0`.

## Быстрый старт

Нужен только Docker. PHP на хосте не требуется.

```bash
make init   # создаёт .env и генерирует APP_KEY
make up     # собирает образы, поднимает стек, ставит composer-зависимости
make check  # cs + stan + test + audit + docs
```

- Приложение: http://localhost:8080 (проверка здоровья: http://localhost:8080/healthz → `{"db":"ok","redis":"ok"}`)
- Mailpit (письма): http://localhost:8025
- Порты опубликованы только на `127.0.0.1`.

## Команды make

| Команда | Что делает |
|---|---|
| `make init` | `.env` из `.env.example` + `APP_KEY` |
| `make up` / `make down` | поднять / остановить стек |
| `make build` | пересобрать образы |
| `make sh` / `make logs` | shell в контейнере `app` / логи |
| `make console CMD="…"` | `bin/console …` в контейнере |
| `make migrate` / `make seed` / `make seed-demo` | миграции / сиды / демо-данные для админки (500 человек, полгода, только локально) |
| `make console CMD="billing:renew"` | биллинг сейчас (продления, пробные периоды, проверка платежей); `billing:renew --force ID_ПОДПИСКИ` списывает немедленно; `billing:plans [--sync]` прайс-лист; `billing:grant ID_ПРОСТРАНСТВА ТАРИФ [month\|year]` выдаёт тариф без оплаты |
| `make console CMD="telegram:poll"` | (разработка) принимать апдейты общего бота долгим опросом, без публичного HTTPS; `telegram:webhook set\|delete\|info` для публичного адреса; `max:poll` и `max:webhook set\|delete\|info` то же для общего бота MAX; `crypto:rotate` перешифровывает секреты после смены `APP_KEY`; `channels:check` ставит в очередь проверку каналов; `bench:publish --fake [--count=200]` замеряет задержку публикации на тестовой сети (на время замера остановите `worker` и `scheduler`) |
| `docker compose -f compose.prod.yaml up -d --build` | production-стек (без mailpit и dev-инструментов), инструкция: [docs/deploy.md](docs/deploy.md) |
| `make test` | PHPUnit (Unit, Integration, Feature) |
| `make stan` | PHPStan level 8 + strict-rules |
| `make cs` / `make cs-fix` | проверка / исправление стиля (PSR-12) |
| `make css` / `css-watch` / `css-check` | сборка Tailwind CSS, пересборка на лету, проверка актуальности |
| `make ui-snap STAGE=NN` / `make a11y` / `make ui-behavior` | скриншоты и axe-core / только axe / проверка поведения компонентов |
| `make audit` | `composer audit` |
| `make docs` | справочник API кода (phpDocumentor) в `docs/reference/` |
| `make check` | всё вместе: `cs` + `stan` + `test` + `audit` + `docs` |

Профили: `docker compose --profile s3 up -d` (MinIO), `docker compose --profile tunnel up -d` (публичный HTTPS через cloudflared для OAuth/вебхуков). Сброс БД: `docker compose down -v`.

## Документация

- [docs/plans/](docs/plans/): мастер-план, этапы, прогресс, правила разработки, промт агента
- [docs/architecture/](docs/architecture/): [обзор](docs/architecture/overview.md), [путь запроса](docs/architecture/request-lifecycle.md), [безопасность](docs/architecture/security.md), [очередь](docs/architecture/queue.md), конфигурация, схема БД
- [docs/adr/](docs/adr/): архитектурные решения
- [docs/CHANGELOG.md](docs/CHANGELOG.md)

Разработка ведётся этапами: ветка `stage-NN-slug` → PR → зелёный CI → squash-merge в `main`. В `main` напрямую не коммитим.

### Real content providers

Stage 3.2 adapters are opt-in via ignored env credentials; Fake remains the default. Configuration, costs, video status checks and safety limits: [provider guide](docs/architecture/modules/content-real-providers.md). No credentials are shipped.

Stage 3.2 is accepted on mocked HTTP/contracts. Real integrations need explicit selection plus corresponding env credentials; absent credentials prevent activation. OpenAI/TinEye/Replicate live smoke is deferred to Stage 3.5; Fake remains the dev/test default.

Stage 3.3 Automation: optional Source/Radar policies, existing minute scheduler/default worker, durable checkpoints and draft-only export. All costly steps default off; missing provider credentials require review without breaking startup. [Operations and limits](docs/architecture/modules/content-automation.md).
