# syntax=docker/dockerfile:1
FROM php:8.5-cli-trixie AS php

ARG SWOOLE_VERSION=6.2.3
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS libssl-dev libicu-dev libzip-dev; \
    docker-php-ext-install -j"$(nproc)" bcmath pcntl sockets intl zip; \
    pecl install redis; \
    pecl install -D 'enable-sockets="yes" enable-openssl="yes" enable-swoole-curl="no" enable-cares="no" enable-brotli="no" enable-zstd="no" enable-swoole-pgsql="no" enable-swoole-sqlite="no"' swoole-${SWOOLE_VERSION}; \
    docker-php-ext-enable redis swoole

FROM php AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN mkdir -p storage/framework/views storage/framework/cache storage/framework/sessions storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-scripts \
    && php artisan package:discover --ansi

FROM node:24-trixie-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY --from=dependencies /app/vendor ./vendor
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM php:8.5-cli-trixie AS runtime
RUN set -eux; \
    sed -i 's/Components: main/Components: main contrib non-free/' /etc/apt/sources.list.d/debian.sources; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        supervisor valkey-server valkey-tools ffmpeg intel-media-va-driver-non-free i965-va-driver mesa-va-drivers vainfo \
        libonig5 libxml2 libicu76 libzip5 libsqlite3-0 libssl3t64 libstdc++6 \
        util-linux passwd curl ca-certificates tzdata; \
    rm -rf /var/lib/apt/lists/*; \
    groupadd --gid 1000 rencoderr; \
    useradd --uid 1000 --gid rencoderr --home-dir /config --no-create-home --shell /usr/sbin/nologin rencoderr
COPY --from=php /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=php /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-production.ini
WORKDIR /app
COPY --from=dependencies /app /app
COPY --from=frontend /app/public/build /app/public/build
COPY docker/ /opt/rencoderr/
RUN set -eux; \
    chmod +x /opt/rencoderr/*.sh; \
    rm -rf /app/storage /app/public/storage; \
    ln -s /config/storage /app/storage; \
    ln -s /config/storage/app/public /app/public/storage; \
    php -r 'foreach (["swoole", "redis", "pcntl", "posix", "pdo_sqlite", "mbstring", "dom", "bcmath", "intl", "zip"] as $extension) { if (! extension_loaded($extension)) { fwrite(STDERR, "Missing extension: $extension\n"); exit(1); } }'; \
    ffmpeg -hide_banner -encoders > /tmp/encoders; \
    for encoder in h264_vaapi hevc_vaapi h264_nvenc hevc_nvenc; do grep -q "$encoder" /tmp/encoders; done; \
    rm /tmp/encoders
ENV APP_NAME=Rencoderr APP_ENV=production APP_DEBUG=false APP_URL=http://localhost:8000 \
    LOG_CHANNEL=stderr LOG_LEVEL=info LOG_STDERR_STREAM=/run/rencoderr/application.log \
    DB_CONNECTION=sqlite DB_DATABASE=/config/database/database.sqlite DB_JOURNAL_MODE=WAL DB_BUSY_TIMEOUT=5000 \
    QUEUE_CONNECTION=redis REDIS_QUEUE_RETRY_AFTER=1860 REDIS_HOST=127.0.0.1 REDIS_CLIENT=phpredis \
    CACHE_STORE=redis SESSION_DRIVER=database \
    OCTANE_SERVER=swoole OCTANE_STATE_FILE=/run/rencoderr/octane.json OCTANE_WORKERS=2 HORIZON_WORKERS=1 \
    PUID=1000 PGID=1000 TZ=UTC
EXPOSE 8000
STOPSIGNAL SIGTERM
HEALTHCHECK --interval=30s --timeout=10s --start-period=120s --retries=3 CMD ["/opt/rencoderr/healthcheck.sh"]
ENTRYPOINT ["/opt/rencoderr/entrypoint.sh"]
