# FloatBook, as one container: nginx + PHP-FPM + the queue worker + the scheduler,
# all kept alive by supervisord. That fits the free hosting tier, which gives one
# always-on service rather than several.

# ---------- 1. Build the frontend ----------
FROM node:24-alpine AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources resources
COPY public public
COPY vite.config.ts tsconfig.json components.json ./
# Wayfinder writes typed route helpers into resources/js; they are committed, so the
# build has everything it needs without PHP here.
RUN npm run build

# ---------- 2. Install PHP packages ----------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev --no-scripts --no-interaction --prefer-dist --no-progress

# ---------- 3. The image that runs ----------
FROM php:8.5-fpm-alpine

# pdo_pgsql for Neon, intl/bcmath for money and dates, zip for Excel exports.
RUN apk add --no-cache nginx supervisor postgresql-dev icu-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl bcmath zip opcache \
    && apk del postgresql-dev icu-dev libzip-dev \
    && rm -rf /var/cache/apk/*

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build

COPY docker/production/nginx.conf /etc/nginx/nginx.conf
COPY docker/production/php.ini /usr/local/etc/php/conf.d/floatbook.ini
COPY docker/production/supervisord.conf /etc/supervisord.conf
COPY docker/production/start.sh /usr/local/bin/start
RUN chmod +x /usr/local/bin/start

# The web server writes only to these.
RUN mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --classmap-authoritative

EXPOSE 8080

# A sleeping free-tier service is woken by a health check on /up.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
    CMD wget -qO- http://127.0.0.1:8080/up > /dev/null || exit 1

CMD ["start"]
