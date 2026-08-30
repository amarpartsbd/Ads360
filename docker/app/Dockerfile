# syntax=docker/dockerfile:1
# Ads360 application image — PHP-FPM 8.4 on Ubuntu LTS base (spec §3 Infrastructure).
FROM php:8.4-fpm-bookworm AS base

ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev \
        libfreetype6-dev libwebp-dev ca-certificates curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip gd bcmath opcache pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/app/php.ini /usr/local/etc/php/conf.d/99-ads360.ini

RUN groupadd -g "${GID}" app && useradd -u "${UID}" -g app -m -s /bin/bash app

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
FROM base AS development

RUN pecl install xdebug && docker-php-ext-enable xdebug
USER app

# ---------------------------------------------------------------------------
FROM base AS production

COPY --chown=app:app composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY --chown=app:app . .
RUN composer dump-autoload --optimize --classmap-authoritative \
    && php artisan storage:link || true

USER app
CMD ["php-fpm"]
