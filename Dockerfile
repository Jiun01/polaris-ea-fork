# ── Stage 1: compile front-end assets ─────────────────────────────────────────
FROM node:18-alpine AS assets

WORKDIR /build

COPY package*.json ./
RUN npm ci --prefer-offline

COPY gulpfile.js .
COPY assets assets

RUN npx gulp build

# ── Stage 2: install PHP vendor dependencies ──────────────────────────────────
FROM php:8.4-cli AS vendor

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /build

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --optimize-autoloader --no-interaction

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
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

WORKDIR /var/www/html

# Copy application source (excluding dev artefacts via .dockerignore).
COPY . .

# Overlay compiled assets and vendor from earlier stages.
COPY --from=assets  /build/assets  ./assets
COPY --from=vendor  /build/vendor  ./vendor

# Production PHP settings (no xdebug).
COPY docker/php-fpm/php-ini-production.ini /usr/local/etc/php/conf.d/polaris-production.ini

# Storage must be writable by www-data.
RUN mkdir -p storage/uploads storage/cache storage/logs \
    && chown -R www-data:www-data storage \
    && chmod -R 775 storage

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
