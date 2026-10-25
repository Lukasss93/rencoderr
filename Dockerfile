# syntax=docker/dockerfile:1
FROM debian:trixie-slim AS ffmpeg

ARG FFMPEG_VERSION=9.0.2
ARG FFMPEG_SHA256=8c3850283eb25fa026482078a04051e0be17347b09ef81a0849bec15a96e002e
ARG VMAF_VERSION=3.2.1
ARG VMAF_SHA256=5df7386911bc15fd1ca783132528748d219768ae4fc5f8e0b61184f041648092
ARG NV_CODEC_HEADERS_VERSION=12.2.72.0
ARG NV_CODEC_HEADERS_SHA256=dbeaec433d93b850714760282f1d0992b1254fc3b5a6cb7d76fc1340a1e47563
ARG FFMPEG_BUILD_JOBS=2

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        build-essential ca-certificates curl xz-utils pkg-config meson ninja-build nasm xxd \
        libx264-dev libx265-dev libsvtav1enc-dev libdav1d-dev libopus-dev libzimg-dev \
        libva-dev libvpl-dev libdrm-dev ocl-icd-opencl-dev \
        libssl-dev zlib1g-dev libbz2-dev liblzma-dev; \
    rm -rf /var/lib/apt/lists/*
WORKDIR /build
RUN set -eux; \
    curl -fL --retry 3 -o ffmpeg.tar.xz "https://ffmpeg.org/releases/ffmpeg-${FFMPEG_VERSION}.tar.xz"; \
    curl -fL --retry 3 -o vmaf.tar.gz "https://github.com/Netflix/vmaf/archive/refs/tags/v${VMAF_VERSION}.tar.gz"; \
    curl -fL --retry 3 -o nv-codec-headers.tar.gz "https://github.com/FFmpeg/nv-codec-headers/archive/refs/tags/n${NV_CODEC_HEADERS_VERSION}.tar.gz"; \
    printf '%s  %s\n' "$FFMPEG_SHA256" ffmpeg.tar.xz "$VMAF_SHA256" vmaf.tar.gz "$NV_CODEC_HEADERS_SHA256" nv-codec-headers.tar.gz | sha256sum -c -; \
    tar -xJf ffmpeg.tar.xz; \
    tar -xzf vmaf.tar.gz; \
    tar -xzf nv-codec-headers.tar.gz; \
    make -C "nv-codec-headers-n${NV_CODEC_HEADERS_VERSION}" PREFIX=/opt/ffmpeg install; \
    meson setup vmaf-build "vmaf-${VMAF_VERSION}/libvmaf" \
        --prefix=/opt/ffmpeg --libdir=lib --buildtype=release --default-library=shared \
        -Denable_tests=false -Denable_docs=false -Denable_tools=false; \
    meson compile -C vmaf-build -j "$FFMPEG_BUILD_JOBS"; \
    meson install -C vmaf-build; \
    mkdir -p /opt/ffmpeg/share/vmaf /opt/ffmpeg/share/licenses; \
    cp "vmaf-${VMAF_VERSION}/model/vmaf_v0.6.1.json" /opt/ffmpeg/share/vmaf/; \
    cp "vmaf-${VMAF_VERSION}/LICENSE" /opt/ffmpeg/share/licenses/vmaf; \
    printf '/opt/ffmpeg/lib\n' > /etc/ld.so.conf.d/ffmpeg.conf; \
    ldconfig
WORKDIR /build/ffmpeg-${FFMPEG_VERSION}
RUN set -eux; \
    PKG_CONFIG_PATH=/opt/ffmpeg/lib/pkgconfig ./configure \
        --prefix=/opt/ffmpeg --libdir=/opt/ffmpeg/lib \
        --enable-shared --disable-static --disable-debug --disable-doc --disable-ffplay \
        --disable-autodetect --enable-gpl --enable-version3 \
        --enable-pthreads --enable-zlib --enable-bzlib --enable-lzma --enable-iconv --enable-openssl \
        --enable-libx264 --enable-libx265 --enable-libsvtav1 --enable-libdav1d \
        --enable-libopus --enable-libzimg --enable-libvmaf \
        --enable-vaapi --enable-libvpl --enable-libdrm --enable-opencl \
        --enable-ffnvcodec --enable-nvenc --enable-nvdec --enable-cuvid; \
    make -j "$FFMPEG_BUILD_JOBS"; \
    make install; \
    cp COPYING.GPLv3 /opt/ffmpeg/share/licenses/ffmpeg; \
    cp ffbuild/config.log /opt/ffmpeg/share/config.log; \
    printf 'ffmpeg=%s\nffmpeg_sha256=%s\nlibvmaf_source=%s\nlibvmaf_sha256=%s\nnv_codec_headers=%s\nnv_codec_headers_sha256=%s\n' \
        "$FFMPEG_VERSION" "$FFMPEG_SHA256" "$VMAF_VERSION" "$VMAF_SHA256" "$NV_CODEC_HEADERS_VERSION" "$NV_CODEC_HEADERS_SHA256" \
        > /opt/ffmpeg/share/build-versions; \
    sha256sum /opt/ffmpeg/share/vmaf/vmaf_v0.6.1.json > /opt/ffmpeg/share/vmaf/SHA256SUMS; \
    dpkg-query -W > /opt/ffmpeg/share/build-packages; \
    rm -rf /opt/ffmpeg/include /opt/ffmpeg/lib/pkgconfig

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
        supervisor valkey-server valkey-tools intel-media-va-driver-non-free i965-va-driver mesa-va-drivers vainfo \
        libx264-164 libx265-215 libsvtav1enc2 libdav1d7 libopus0 libzimg2 \
        libva2 libva-drm2 libvpl2 libmfx-gen1.2 libdrm2 ocl-icd-libopencl1 \
        zlib1g libbz2-1.0 liblzma5 \
        libonig5 libxml2 libicu76 libzip5 libsqlite3-0 libssl3t64 libstdc++6 \
        util-linux passwd curl ca-certificates tzdata; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    rm -rf /usr/local/include/php /usr/local/lib/php/build; \
    rm -f /usr/local/bin/phpize /usr/local/bin/php-config; \
    rm -rf /var/lib/apt/lists/*; \
    groupadd --gid 1000 rencoderr; \
    useradd --uid 1000 --gid rencoderr --home-dir /config --no-create-home --shell /usr/sbin/nologin rencoderr
COPY --from=ffmpeg /opt/ffmpeg /opt/ffmpeg
ENV PATH="/opt/ffmpeg/bin:${PATH}"
RUN printf '/opt/ffmpeg/lib\n' > /etc/ld.so.conf.d/ffmpeg.conf && ldconfig
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
    php -r 'foreach (["swoole", "redis", "pcntl", "posix", "pdo_sqlite", "mbstring", "dom", "xmlreader", "bcmath", "intl", "zip"] as $extension) { if (! extension_loaded($extension)) { fwrite(STDERR, "Missing extension: $extension\n"); exit(1); } }'; \
    runuser -u rencoderr -- /opt/rencoderr/verify-ffmpeg.sh
ENV APP_NAME=Rencoderr APP_ENV=production APP_DEBUG=false APP_URL=http://localhost:8000 \
    XDG_CACHE_HOME=/config/storage/cache \
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
