# 0006. Изолированный read-only MTProto spike

- Статус: принято для прототипа; production-выбор не зафиксирован
- Дата: 2026-10-04

## Контекст

Проверить чтение контролируемого публичного Telegram-канала, session persistence,
историю, live updates, edits, albums, фотографии и recovery, не реализуя Sources.
Пользователь прямо выбрал Python + стабильную Telethon и запретил интеграцию с PHP,
публикацию, AI, selection и коммиты. Spike не продолжает текущий этап проекта.

## Решение

Самостоятельный Docker project в tools/telegram-reader; основной Compose и PHP
не изменяются. Telethon 1.45.0 подтверждена PyPI/stable docs 2026-10-04. Python 3.13
base image закреплён digest; Telethon, pyaes, rsa, pyasn1, Pillow — версии и hashes.
Pillow нужен только для проверки исходного скачанного изображения и dimensions,
не enhancement. SQLite стандартной библиотеки хранит бизнес-состояние прототипа.
MTProto за адаптером; тесты — через Fake transport и TL objects, только Docker/offline.

Telethon upstream переехал с GitHub на Codeberg; stable v1 находится в maintenance
mode с обновлением layers. Источники: https://pypi.org/project/Telethon/,
https://docs.telethon.dev/en/stable/, https://github.com/LonamiWebs/Telethon.
Проверка известных уязвимостей зависимостей выполняется отдельно через pip-audit;
результат фиксируется в отчёте текущего запуска, не считается постоянной гарантией.

## Рассмотренные варианты

PHP/MadelineProto, Node/Teleproto и TDLib остаются production-кандидатами из аудита.
Python/Telethon выбран по прямому заданию и для минимального проверяемого spike.
Свой MTProto transport не реализуется: неоправданный объём криптографии/update logic.

## Последствия

Нет доступа к приложению, publishing credentials или его БД. Reader не отправляет,
не изменяет, не удаляет сообщения и не вступает в каналы. Получение difference
может включать public channel short polling. Telegram authorization/session —
секрет; сохраняется в непубличном volume, шифрование диска обеспечивает host.
Нужна живая ручная проверка с тестовым аккаунтом; offline tests её не заменяют.
AI/content licensing и production-масштабирование не входят в этот spike.
Удаление прототипа не требует рефакторинга VKPoster.
