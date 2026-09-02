# Установка в Docker

Рекомендуемый способ установки — образ форка
[**slashp/torrentmonitor**](https://hub.docker.com/r/slashp/torrentmonitor).
Внутри контейнера уже есть всё необходимое: Apache, PHP 8.2, база данных и cron,
который раз в 30 минут запускает проверку раздач — на хосте настраивать нечего.

## Быстрый старт

```bash
docker pull slashp/torrentmonitor
docker compose up -d
```

Или из исходников, если хотите собрать образ сами:

```bash
git clone https://github.com/PSlava/TorrentMonitor.git
cd TorrentMonitor
docker compose up -d --build
```

Откройте `http://IP_СЕРВЕРА:8080` — дальше вся работа идёт в веб-интерфейсе:
[Первоначальная настройка](Getting-Started).

## Тома

`docker-compose.yml` создаёт два тома, оба обязательно сохраняйте:

| Том | Что внутри |
|---|---|
| `tm-data` → `/var/www/html/data` | база данных SQLite, служебные файлы |
| `tm-torrents` → `/var/www/html/torrents` | скачанные `.torrent`-файлы |

## Выбор базы данных

По умолчанию используется SQLite — она не требует ничего дополнительного.
Для MySQL или PostgreSQL задайте переменные окружения; `config.php` соберётся
из них при старте контейнера:

```yaml
environment:
  - DB_TYPE=mysql        # mysql | pgsql | sqlite
  - DB_HOST=db
  - DB_PORT=3306
  - DB_NAME=torrentmonitor
  - DB_USER=torrentmonitor
  - DB_PASS=torrentmonitor
```

## Обход Cloudflare

Трекеры с «проверкой браузера» (сейчас это `kinozal.me`) без обхода не работают —
темы падают с ошибкой «Не удалось получить страницу трекера». В compose уже описан
контейнер [Byparr](https://github.com/ThePhaseless/Byparr) под профилем `cloudflare`:

```bash
docker compose --profile cloudflare up -d
```

Затем в **Настройки → Расширенные → FlareSolverr / Byparr URL** укажите
`http://byparr:8191`. Подробнее — [Прокси и зеркала](Proxy-and-Mirrors#flaresolverr).

## Обновление

```bash
docker compose pull && docker compose up -d
```

Данные лежат в томах, схема базы обновляется сама при первом запуске новой версии.
Подробнее — [Обновление](Updating).

## Docker-образ апстрима

У оригинального проекта есть свой контейнер —
[torrentmonitor-dockerized](https://github.com/alfonder/torrentmonitor-dockerized)
от [Александра Фомичёва](https://github.com/alfonder), с
[собственной вики](https://github.com/alfonder/torrentmonitor-dockerized/wiki).
Он собирает апстрим, а не этот форк, и доработок форка в нём нет.
