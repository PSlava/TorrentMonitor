FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        cron \
        libcurl4-openssl-dev \
        libxml2-dev \
    && docker-php-ext-install \
        curl \
        pdo_mysql \
        simplexml \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

COPY . /var/www/html/

RUN mkdir -p /var/www/html/torrents /var/www/html/data \
    && chown -R www-data:www-data /var/www/html

# Cron: проверка трекеров каждые 30 минут
RUN echo "*/30 * * * * www-data php /var/www/html/engine.php >> /var/log/torrentmonitor.log 2>&1" \
    > /etc/cron.d/torrentmonitor \
    && chmod 0644 /etc/cron.d/torrentmonitor

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
