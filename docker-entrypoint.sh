#!/bin/bash
set -e

DB_TYPE="${DB_TYPE:-sqlite}"
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-torrentmonitor}"
DB_USER="${DB_USER:-torrentmonitor}"
DB_PASS="${DB_PASS:-torrentmonitor}"

CONFIG="/var/www/html/config.php"

# Генерируем config.php, если его ещё нет
if [ ! -f "$CONFIG" ]; then
    cat > "$CONFIG" <<PHPEOF
<?php
include_once dirname(__FILE__).'/class/Config.class.php';
include_once dirname(__FILE__).'/autoload.php';
Config::extended();

PHPEOF

    if [ "$DB_TYPE" = "sqlite" ]; then
        cat >> "$CONFIG" <<PHPEOF
Config::write('db.type', 'sqlite');
Config::write('db.basename', '/var/www/html/data/torrentmonitor.sqlite');
PHPEOF
    elif [ "$DB_TYPE" = "mysql" ]; then
        cat >> "$CONFIG" <<PHPEOF
Config::write('db.host', '$DB_HOST');
Config::write('db.type', 'mysql');
Config::write('db.charset', 'utf8mb4');
Config::write('db.port', '$DB_PORT');
Config::write('db.basename', '$DB_NAME');
Config::write('db.user', '$DB_USER');
Config::write('db.password', '$DB_PASS');
PHPEOF
    elif [ "$DB_TYPE" = "pgsql" ]; then
        cat >> "$CONFIG" <<PHPEOF
Config::write('db.host', '$DB_HOST');
Config::write('db.type', 'pgsql');
Config::write('db.port', '$DB_PORT');
Config::write('db.basename', '$DB_NAME');
Config::write('db.user', '$DB_USER');
Config::write('db.password', '$DB_PASS');
PHPEOF
    fi

    echo "?>" >> "$CONFIG"
    chown www-data:www-data "$CONFIG"
fi

# Генерируем config.xml, если его нет
if [ ! -f /var/www/html/config.xml ]; then
    echo '<config></config>' > /var/www/html/config.xml
    chown www-data:www-data /var/www/html/config.xml
fi

# Создаём каталоги на случай, если volume пустой
mkdir -p /var/www/html/torrents /var/www/html/data
chown -R www-data:www-data /var/www/html/torrents /var/www/html/data

# Запускаем cron в фоне
cron

exec "$@"
