[![License: MIT](https://img.shields.io/badge/license-MIT-c8ff00.svg)](LICENSE)
![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)
![SQLite](https://img.shields.io/badge/SQLite-zero%20config-003b57.svg)
![Dependencies: none](https://img.shields.io/badge/dependencies-none-success.svg)

[English](README.md) · **Русский**

# lnks

Минималистичный self-hosted сервис сокращения ссылок. Одно небольшое PHP-приложение, SQLite, ноль зависимостей.

Без Composer, фреймворков и Docker, без трекинг-пикселей и сторонних скриптов. Загрузите на любой PHP-хостинг, откройте в браузере — и всё работает.

<p align="center">
  <img src="docs/screenshots/home.png" alt="Публичная страница" width="49%">
  <img src="docs/screenshots/admin.png" alt="Админка" width="49%">
</p>
<p align="center">
  <img src="docs/screenshots/stats.png" alt="Статистика по ссылке" width="49%">
  <img src="docs/screenshots/mobile-ru.png" alt="Мобильная версия, русский интерфейс" width="22%">
</p>

## Возможности

**Сокращение**
- Создание ссылок через публичную форму, админку или REST API
- Необязательное название для каждой ссылки
- Публичный режим (сокращать может любой, лимит по IP) или закрытый (только админ и API)
- Ссылки на сам сервис отклоняются (нет петель редиректа)

**Админка**
- Вход по логину и паролю (оба задаются в установщике), защита от подбора
- Сводка: ссылки, клики всего, сегодня и за 7 дней
- Поиск по коду, названию и URL; фильтр по статусу; сортировка по дате, кликам и последнему клику
- Мини-график за 7 дней у каждой ссылки и страница статистики (график за 30 дней, источники переходов)
- Включение, отключение, удаление, копирование в один клик, пагинация

**Клики**
- Счётчики по ссылкам и журнал кликов (время и referrer, без IP и cookie)
- Боты, краулеры, превью ссылок, `HEAD`-запросы и предзагрузка браузера **не** считаются

**Интерфейс**
- **Переключатель языка RU / EN** (запоминается в cookie, по умолчанию — язык браузера)
- Адаптивная вёрстка, автоматическая светлая и тёмная тема, никаких JS-фреймворков

**API**
- REST API с Bearer-токеном: создание, список, детали с кликами по дням, включение и отключение, удаление

**Эксплуатация**
- Веб-установщик с проверкой окружения, блокируется после установки
- Автоматические миграции базы при обновлении
- Строгая Content-Security-Policy, CSRF-защита, подготовленные запросы везде

## Требования

- PHP 8.0+ с `pdo_sqlite` (есть в любой стандартной сборке PHP)
- Apache с `mod_rewrite` или Nginx (конфиг ниже)

## Установка

**Веб-установщик (проще всего):** загрузите файлы, направьте корень домена в папку проекта и откройте сайт. Мастер запустится сам:

1. задайте **логин и пароль администратора**,
2. решите, можно ли всем сокращать ссылки на главной,
3. скопируйте сгенерированный API-токен (показывается один раз, также лежит в `config.php`).

После установки установщик блокируется, как только появляется `config.php`. Файл `install.php` потом можно удалить.

**Вручную:**

```bash
git clone https://github.com/iDreamSkies/lnks.git
cd lnks
cp config.example.php config.php
```

Отредактируйте `config.php`:

```bash
# Хеш пароля администратора (вставьте в admin_pass_hash)
php -r "echo password_hash('ваш-пароль', PASSWORD_BCRYPT), PHP_EOL;"

# API-токен (вставьте в api_token)
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

В `admin_user` укажите нужный логин (по умолчанию `admin`) и дайте веб-серверу права на запись в `storage/`:

```bash
chmod 775 storage
```

**Локальный запуск:** `php -S localhost:8080 router.php`

### Обновление

Замените файлы, сохранив `config.php` и `storage/`. База мигрирует автоматически при первом запросе. Конфиги от старых версий продолжают работать: если `admin_user` не задан, используется `admin`.

### Nginx

```nginx
server {
    listen 80;
    server_name lnks.example.com;
    root /var/www/lnks;
    index index.php;

    location /storage/ { deny all; }
    location /lang/    { deny all; }
    location ~ ^/(config|bootstrap|layout|i18n|router|schema|\.git) { deny all; }

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

## Язык

Интерфейс доступен на русском и английском. Посетитель переключает язык кнопками **EN | RU** в шапке, выбор сохраняется в cookie. Если выбора нет, берётся язык браузера (иначе английский). Чтобы задать язык по умолчанию для всех, укажите в `config.php` `'lang' => 'en'` или `'ru'`.

Нужен другой язык? Скопируйте `lang/en.php` в `lang/xx.php`, переведите значения и добавьте код в `LNKS_LANGS` в `i18n.php`.

Ответы API всегда на английском.

## API

Во всех запросах нужен заголовок `Authorization: Bearer ВАШ_API_ТОКЕН`.

```bash
# Создать (title необязателен)
curl -X POST https://lnks.example.com/api.php \
  -H "Authorization: Bearer ВАШ_API_ТОКЕН" -H "Content-Type: application/json" \
  -d '{"url": "https://example.com/very/long/url", "title": "Документация"}'

# Список (q, limit 1-100, offset)
curl "https://lnks.example.com/api.php?q=docs&limit=20" -H "Authorization: Bearer ВАШ_API_ТОКЕН"

# Детали и клики за 30 дней
curl "https://lnks.example.com/api.php?code=aB3xYz" -H "Authorization: Bearer ВАШ_API_ТОКЕН"

# Отключить (0) / включить (1)
curl -X PATCH "https://lnks.example.com/api.php?code=aB3xYz" -H "Authorization: Bearer ВАШ_API_ТОКЕН" \
  -H "Content-Type: application/json" -d '{"status": 0}'

# Удалить
curl -X DELETE "https://lnks.example.com/api.php?code=aB3xYz" -H "Authorization: Bearer ВАШ_API_ТОКЕН"
```

Ответ на создание (201):

```json
{ "ok": true, "id": 1, "code": "aB3xYz", "short_url": "https://lnks.example.com/aB3xYz", "url": "https://example.com/very/long/url", "title": "Документация" }
```

Ошибки возвращаются как `{ "ok": false, "error": "..." }` с подходящим HTTP-статусом (400, 401, 404, 405, 422, 503).

## Настройки

| Ключ | Описание |
|---|---|
| `base_url` | Публичный URL сервиса; пусто — определяется из запроса |
| `lang` | `auto` (язык браузера), `en` или `ru` — язык по умолчанию |
| `admin_user` | Логин администратора (по умолчанию `admin`) |
| `admin_pass_hash` | bcrypt-хеш пароля администратора |
| `api_token` | Bearer-токен для `/api.php`; пусто — API отключён |
| `public_form` | `true` — все могут сокращать на главной; `false` — только админ и API |
| `rate_limit` | `['max' => 20, 'window_min' => 60]` — лимит публичной формы на один IP |
| `trust_proxy` | `true` — брать IP клиента из `X-Forwarded-For` (только за собственным reverse proxy) |
| `code.length` | Длина короткого кода (по умолчанию 6, допустимо 4–12) |
| `timezone` | Часовой пояс PHP (в интерфейсе время показывается в UTC) |

## Безопасность

- CSRF-защита всех форм; выход из админки — POST
- Подготовленные запросы везде; весь вывод экранируется
- Для входа нужны логин **и** пароль; 5 неудачных попыток за 15 минут блокируют IP; ID сессии обновляется при входе; cookie `HttpOnly` и `SameSite`
- Строгая Content-Security-Policy (без inline-скриптов и стилей), `X-Frame-Options: DENY`, `nosniff`
- `storage/`, `lang/`, конфиг и служебные файлы закрыты на уровне веб-сервера
- Редиректы отдают `X-Robots-Tag: noindex`
- Посетителям, которые просто переходят по коротким ссылкам, cookie не ставятся

## Структура проекта

```
index.php      главная и редиректы         admin.php   админка
api.php        REST API                    install.php веб-установщик
bootstrap.php  конфиг, БД, хелперы         layout.php  шаблон страниц, ссылки поддержки
i18n.php       загрузка переводов          lang/       en.php, ru.php
schema.sql     схема базы                  public/     styles.css, app.js
```

## Нужно больше?

lnks — намеренно минимальное открытое ядро. Существует полная версия с дополнительными возможностями:

- Свои алиасы и редактирование ссылок
- Несколько доменов
- Срок жизни ссылок, лимиты кликов, ссылки под паролем
- UTM-конструктор и аналитика по каждому клику (гео, устройство, браузер, уникальные посетители)
- A/B-тестирование
- Интеграция с Telegram-ботом
- Несколько пользователей с ролями
- Импорт и экспорт

Интересует полная версия, хостинг или разработка под заказ?
Пишите: [dreamskies.dev](https://dreamskies.dev) · Telegram [@dreamskies](https://t.me/dreamskies)

## Поддержать проект

lnks бесплатен и распространяется по лицензии MIT. Если он сэкономил вам время, можно поддержать разработку:

- Криптовалюта: [pay.oxapay.com](https://pay.oxapay.com/14606636/)
- Из России: [ЮMoney](https://yoomoney.ru/fundraise/1KGTK8NPPQN.260925)

Баги и идеи: [создайте issue](https://github.com/iDreamSkies/lnks/issues).

## Лицензия

MIT — см. [LICENSE](LICENSE).

---

Автор — [DreamSkies](https://dreamskies.dev)
