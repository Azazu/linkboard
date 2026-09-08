# Linkboard — brief

Extracted from `Pet_Projects_Portfolio_Sopilko.md` (project 2, plus the shared recommendations). Original language: Russian.

## Проект 2 — Linkboard (Symfony + API Platform + PostgreSQL)

Сервис коротких ссылок с умной маршрутизацией и аналитикой. Тема выбрана намеренно — она перекликается с вашим реальным продакшен-опытом (device detection, маршрутизация в сторы), поэтому на собеседовании звучит достоверно.

### Что показывает работодателю
Enterprise-грань PHP: **Symfony 7**, **Doctrine** (DataMapper — контраст с Eloquent), **API Platform** (авто-REST/GraphQL + OpenAPI из коробки), **PostgreSQL** (JSONB, оконные функции для аналитики), **Redis** (счётчики, rate limiting, кеш), **Messenger** (асинхронная обработка кликов), элементы **DDD** и **CQRS-lite** (разделение записи события клика и чтения аналитики).

### Роли
- Гость — переход по короткой ссылке.
- Пользователь — создание/управление своими ссылками, просмотр аналитики.
- Админ — управление пользователями, глобальная статистика, модерация.

### Функциональные требования
- Создание короткой ссылки: целевой URL, кастомный alias, срок жизни/лимит переходов, UTM-метки.
- **Умная маршрутизация**: правила в JSONB — по устройству (iOS → App Store, Android → Google Play, desktop → сайт), по стране/языку; A/B-варианты назначения.
- Редирект `GET /{slug}` с логированием клика **асинхронно** (Messenger → воркер пишет событие, не тормозя редирект).
- Аналитика: клики по времени, странам, устройствам, рефереру; агрегаты через оконные функции PostgreSQL, кеш горячих отчётов в Redis.
- QR-код на ссылку.
- API-ключи, rate limiting, версия `/api/v1`.

### Модель данных (PostgreSQL)
- `users`
- `links` — `slug` (uniq), `target_url`, `rules` (JSONB), `owner_id`, `expires_at`, `max_clicks`, `is_active`.
- `clicks` — событие: `link_id`, `occurred_at`, `country`, `device`, `referer`, `ua` (пишется асинхронно; можно партиционировать по дате).
- `api_keys`.
- (Опц.) materialized view / агрегатная таблица для аналитики.

### API
- Ресурсы через **API Platform**: `links` (CRUD), `clicks`/`stats` (read-only, отдельная read-модель — CQRS-lite).
- Публичный редирект-эндпоинт вне API-контура.
- Автогенерируемая OpenAPI-документация.

### Технологический стек
Symfony 7, PHP 8.3, API Platform, Doctrine ORM, PostgreSQL 16, Redis, Symfony Messenger, Docker Compose, PHPUnit, PHP-CS-Fixer, PHPStan.

### Чему учит / что демонстрирует
Doctrine и DataMapper (иначе, чем Yii/Eloquent), сервисный слой и DI-контейнер Symfony, событийная обработка через Messenger, аналитика на PostgreSQL, проектирование read/write-моделей, авто-API через API Platform.

### Этапы
1. Каркас Symfony + Docker + сущности/миграции (Doctrine), CRUD ссылок, редирект + синхронное логирование.
2. Умная маршрутизация (JSONB-правила) + async-логирование через Messenger.
3. Аналитика (оконные функции + кеш), QR, API-ключи, rate limiting.
4. API Platform + OpenAPI, тесты (PHPUnit), CI, README, деплой.

---

## Общие рекомендации по всем проектам

- **README — главное.** Скриншоты/GIF, описание архитектуры, как запустить (`docker compose up`), список показанных навыков. Рекрутёр часто дальше README не идёт.
- **CI на GitHub Actions** (тесты + линтер) на каждый пуш — сильный сигнал зрелости.
- **Запинить** лучшие 2–3 репозитория в профиле GitHub, оформить profile README.
- **Связать с реальным опытом** в рассказе: Linkboard ↔ маршрутизация ссылок и device detection, SemanticShelf ↔ ресёрч embedding-поиска, Thumbforge ↔ обработка изображений (imgproxy). Это превращает пет-проекты в продолжение вашего прод-опыта, а не «учебные задания».
- **Небольшие коммиты с внятными сообщениями** — историю коммитов тоже смотрят.
