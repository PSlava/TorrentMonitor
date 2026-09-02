# TorrentMonitor

Форк [ElizarovEugene/TorrentMonitor](https://github.com/ElizarovEugene/TorrentMonitor). Версия 3.3-fork.1 (основана на v3.3 апстрима).

Приложение следит за обновлениями на торрент-трекерах рунета и автоматически скачивает новые серии и перезалитые раздачи через торрент-клиент.

### Поддерживаемые трекеры

Слежение за темами:
anidub.com, animelayer.ru, baibako.tv (+ форум), booktracker.org, casstudio.tk, hamsterstudio.org, kinozal.me, kinozal.tv, kinozal.guru, lostfilm.tv (+ собственное зеркало), newstudio.tv, nnmclub.to, pornolab.net, riperam.org, rustorka.com, rutor.is, rutracker.org, tfile.cc

Слежение за релизерами:
booktracker.org, nnmclub.to, pornolab.net, rutracker.org, tfile.cc

Поиск новых серий (SD/HD 720/HD 1080):
baibako.tv, hamsterstudio.org, lostfilm.tv (+ собственное зеркало), newstudio.tv

`kinozal.me` закрыт Cloudflare — для него нужен FlareSolverr/Byparr, см. ниже.

### Возможности

- Работа через прокси (SOCKS5/HTTP), глобально и отдельно для каждого трекера
- Обход Cloudflare через FlareSolverr/Byparr
- Управление торрент-клиентами (Transmission, qBittorrent, Deluge, rTorrent, aria2, Synology DownloadStation, TorrServer)
- Уведомления: E-mail, Telegram, Pushbullet, Pushover, Pushall, Prowl
- REST API для интеграции со сторонними приложениями
- RSS-лента
- Запуск собственных скриптов после обновления раздачи

### Отличия форка

**Безопасность**
- Пароли надёжно шифруются (bcrypt) вместо хранения в открытом виде
- Закрыты основные уязвимости: XSS, SQL-инъекции, подделка запросов (CSRF)
- Исправлены проблемы безопасности в интеграциях с торрент-клиентами
- jQuery обновлён до актуальной версии 3.7.1

**API для внешних приложений**
- Встроенный REST API для управления раздачами из сторонних приложений и скриптов
- Вебхуки с подписью HMAC-SHA256: автоматические уведомления внешних сервисов о событиях
- Отдельные именованные API-ключи с возможностью отзыва по одному
- Поток событий Server-Sent Events (`sse.php`) для внешних подписчиков

**Быстродействие**
- Значительно сокращено количество запросов к базе данных за счёт кеширования

**Интерфейс**
- Экран «Тестирование»: проверка трекеров, торрент-клиента, прокси и уведомлений в один клик
- Запуск проверки раздач прямо из интерфейса
- При добавлении сериала можно выбрать название из списка трекера, а путь сохранения — из истории
- Подсказки с последними 10 использованными каталогами
- Кнопка «Сбросить и перезакачать» для отдельной раздачи
- Новости отмечаются прочитанными автоматически, есть кнопка «Отметить все»
- Можно складывать сериал в подкаталог с его названием
- Автоопределение системной тёмной темы (через `prefers-color-scheme`)
- Статусы торрентов загружаются одним пакетным запросом вместо N отдельных

**Стабильность и внутренние улучшения**
- Сбой на одном трекере не ломает проверку остальных
- Автоматические миграции базы данных при обновлении
- Очередь повторных попыток при временных ошибках
- MySQL: переход на современный движок (InnoDB) и кодировку (utf8mb4)
- Совместимость с PHP 8.0–8.2 (устранены Notice/Warning/Deprecation)
- Кроссбраузерная совместимость (Safari, Chrome, Firefox)
- Проверка торрент-клиента реальным API-запросом вместо простого `fsockopen`
- Предотвращение блокировки параллельных запросов через `session_write_close()`

### Docker Hub

https://hub.docker.com/r/slashp/torrentmonitor

### Установка через Docker (рекомендуется)

Самый простой способ запустить TorrentMonitor:

```bash
docker pull slashp/torrentmonitor
docker compose up -d
```

Или из исходников:

```bash
git clone https://github.com/PSlava/TorrentMonitor.git
cd TorrentMonitor
docker compose up -d --build
```

Интерфейс будет доступен по адресу http://localhost:8080

По умолчанию используется SQLite, данные хранятся в Docker-томах. Для MySQL или PostgreSQL укажите переменные окружения:

```yaml
environment:
  - DB_TYPE=mysql
  - DB_HOST=db
  - DB_PORT=3306
  - DB_NAME=torrentmonitor
  - DB_USER=torrentmonitor
  - DB_PASS=torrentmonitor
```

Проверка трекеров запускается автоматически каждые 30 минут.

Если трекер закрыт Cloudflare («проверка браузера»), поднимите профиль `cloudflare` —
он добавит контейнер Byparr:

```bash
docker compose --profile cloudflare up -d
```

Затем в **Настройки → Расширенные → FlareSolverr / Byparr URL** укажите `http://byparr:8191`.

### Установка вручную

Что нужно:
* Веб-сервер (Apache, nginx, lighttpd)
* PHP 7.4–8.2 с расширениями cURL и PDO
* Одна из баз данных: MySQL, PostgreSQL или SQLite

Порядок действий:
1. Скопируйте файлы проекта в каталог веб-сервера
2. Скопируйте `config.php.example` в `config.php` и укажите настройки подключения к БД
3. Создайте базу данных по схеме из `db_schema/` (для SQLite база создастся автоматически)
4. Откройте приложение в браузере
5. Добавьте в cron периодический запуск `php engine.php` (раз в 30 минут)

Для работы REST API по адресу `/api/...` нужен `mod_rewrite` и разрешённый `.htaccess`
(`AllowOverride All`). Без него API доступен по прямому пути `/api.php/...`.

### Скриншоты
 ![Screenshot0](https://habrastorage.org/webt/yy/xq/2g/yyxq2gn8o5-b68zr-m_acdv78w8.png "Screenshot0")
 ![Screenshot1](https://habrastorage.org/webt/do/fl/cd/doflcdnaxhg4elpis4jyg30tzik.png "Screenshot1")
 ![Screenshot2](https://habrastorage.org/webt/ad/m5/tk/adm5tktyrelde8fur565aprrpia.png "Screenshot2")
 ![Screenshot3](https://habrastorage.org/webt/5v/9n/ww/5v9nww4n2ahujooewnichz3emoa.png "Screenshot3")
 ![Screenshot4](https://habrastorage.org/webt/qs/i7/y5/qsi7y53vb8qnl0y0ifcrxbcvv78.png "Screenshot4")
 ![Screenshot5](https://habrastorage.org/webt/nz/n9/zd/nzn9zdlnhje6blm7dsbk7nnzxnk.png "Screenshot5")
 ![Screenshot6](https://habrastorage.org/webt/ta/wl/pz/tawlpzlptcv1frusl8lv_tyyc-u.png "Screenshot6")
