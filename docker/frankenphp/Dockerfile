# Production app image: FrankenPHP (Caddy + embedded ZTS PHP) replaces `artisan serve`. Queue workers, scheduler and
# device:listen run from the same image through supervisor (docker/8.4/supervisord.prod.conf). The dev image stays
# docker/8.4 (Sail). Build context is ./docker. Classic mode (no Octane): every request boots the app like php-fpm.
FROM dunglas/frankenphp:1-php8.4

ARG WWWGROUP=1000
ARG NODE_VERSION=22
# The oldest PHP the image may ship (CVE-2026-109618 / CVE-2026-109619 are fixed in 8.4.27); rebuild with --no-cache/--pull.
ARG PHP_MIN_VERSION=8.4.27

WORKDIR /var/www/html

ENV TZ=UTC
ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        supervisor gosu curl ca-certificates gnupg git zip unzip sqlite3 libcap2-bin procps nano \
        ffmpeg libchromaprint-tools librsvg2-bin \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /usr/share/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/nodesource.gpg] https://deb.nodesource.com/node_$NODE_VERSION.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# pcntl: device:listen forks one child per device. sockets: MQTT/SSDP. The rest is what composer.json and the app use.
RUN install-php-extensions pcntl sockets gd imagick redis igbinary msgpack bcmath intl zip mbstring soap exif opcache pdo_mysql pdo_sqlite

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN php -r "exit(version_compare(PHP_VERSION, '$PHP_MIN_VERSION', '>=') ? 0 : 1);" \
    || (echo "PHP $(php -r 'echo PHP_VERSION;') is older than $PHP_MIN_VERSION: rebuild with --pull --no-cache" >&2; exit 1)

# supervisord.prod.conf and the deploy playbook call /usr/bin/php
RUN ln -s /usr/local/bin/php /usr/bin/php

# Caddy runs as `sail` and binds the app port (80 by default)
RUN setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp

RUN groupadd --force -g $WWWGROUP sail \
    && useradd -ms /bin/bash --no-user-group -g $WWWGROUP -u 1337 sail \
    && mkdir -p /data/caddy /config/caddy /.composer \
    && chown -R sail:sail /data /config \
    && git config --global --add safe.directory /var/www/html

COPY frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY frankenphp/php.ini /usr/local/etc/php/conf.d/99-remoment.ini
COPY 8.4/start-container /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container

EXPOSE 80/tcp

ENTRYPOINT ["start-container"]
