# ── Stage 1: compile front-end assets ─────────────────────────────────────────
FROM node:18-alpine AS assets

WORKDIR /build

COPY package*.json ./
RUN npm ci --prefer-offline

COPY gulpfile.js .
COPY assets assets

# `compile` = clean + vendor + scripts + styles (no archive step).
# `build` additionally zips the full source tree for release — not needed here.
RUN npx gulp compile

# ── Stage 2: install PHP vendor dependencies ──────────────────────────────────
FROM php:8.4-cli AS vendor

# Use the official Composer image — avoids curl-piping an installer and pins the version.
COPY --from=composer:2.7 /usr/bin/composer /usr/local/bin/composer

# unzip: lets Composer extract dist archives without falling back to git clone.
# git: fallback for packages whose dist is unavailable (e.g. dev-master sources).
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /build

COPY composer.json composer.lock ./
# --ignore-platform-reqs: the vendor stage has no PHP extensions; extensions
# are verified at runtime in the production stage, not at package-download time.
RUN composer install --no-dev --no-scripts --optimize-autoloader --no-interaction --ignore-platform-reqs

# ── Stage 3: production image ──────────────────────────────────────────────────
FROM php:8.4-fpm AS production

# Install only the extensions required at runtime.
# fileinfo + mbstring are bundled; json is always available in PHP 8.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libpng-dev \
        libjpeg-dev \
        libwebp-dev \
        libxml2-dev \
        libcurl4-openssl-dev \
        libonig-dev \
        libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        gd \
        mbstring \
        mysqli \
        pdo_mysql \
        simplexml \
        fileinfo \
        intl \
        exif \
        bcmath \
    # Purge ONLY the -dev header packages. Do NOT use `--auto-remove`: it strips
    # the runtime libs gd/intl link against (libpng16, libicu*), which silently
    # disables those extensions and 502s EA (which hard-requires intl). Purging
    # the named -dev packages alone leaves the runtime .so libraries in place.
    && apt-get purge -y \
        libfreetype6-dev \
        libpng-dev \
        libjpeg-dev \
        libwebp-dev \
        libxml2-dev \
        libcurl4-openssl-dev \
        libonig-dev \
        libicu-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Fail the build if any required PHP extension can't actually LOAD (not just is
# compiled). A stripped runtime lib makes the extension silently absent from
# `php -m`; asserting here turns that into a red build instead of a 502 in prod.
RUN set -eux; \
    for ext in intl gd mysqli pdo_mysql mbstring bcmath; do \
        php -m | grep -qix "$ext" \
            || { echo "FATAL: PHP extension '$ext' not loaded — missing runtime lib?"; exit 1; }; \
    done

WORKDIR /var/www/html

# Copy application source (excluding dev artefacts via .dockerignore).
COPY . .

# Overlay compiled assets and vendor from earlier stages.
COPY --from=assets  /build/assets  ./assets
COPY --from=vendor  /build/vendor  ./vendor

# Production PHP settings (no xdebug).
COPY docker/php-fpm/php-ini-production.ini /usr/local/etc/php/conf.d/polaris-production.ini

# Writable directories: storage/ (uploads/cache/logs) plus CI3 runtime dirs.
RUN mkdir -p storage/uploads storage/cache storage/logs \
        application/cache application/logs \
    && chown -R www-data:www-data storage application/cache application/logs \
    && chmod -R 775 storage application/cache application/logs

# Self-configuring entrypoint: generates config.php from env on boot (the image
# ships none — it holds DB creds). Installed outside /var/www/html so the prod
# named-volume mount on that path cannot hide it. config.php is pre-created
# www-data-owned so the www-data entrypoint can overwrite it.
COPY docker/php-fpm/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && touch /var/www/html/config.php \
    && chown www-data:www-data /var/www/html/config.php \
    && chmod 640 /var/www/html/config.php

USER www-data

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD pgrep -x php-fpm > /dev/null || exit 1

# Entrypoint self-configures (config.php from env) then execs the CMD.
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
