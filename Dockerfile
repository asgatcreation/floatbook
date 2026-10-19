# FloatBook, as one container: nginx + PHP-FPM + the queue worker + the scheduler,
# all kept alive by supervisord. That fits the free hosting tier, which gives one
# always-on service rather than several.

# ---------- 1. Install PHP packages ----------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev --no-scripts --no-interaction --prefer-dist --no-progress

# ---------- 2. Build the frontend ----------
# This stage needs PHP as well as Node: Vite asks Wayfinder (a Laravel command) to
# write the typed route helpers, and Tailwind scans Blade files under vendor/.
# Node is lifted out of the official image; both are Alpine, so the binary just works.
FROM php:8.5-cli-alpine AS frontend

COPY --from=node:24-alpine /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-alpine /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY --from=vendor /app/vendor ./vendor
COPY . .

# Wayfinder boots the app to read the routes, so it needs a key and a config it can
# load. Nothing here touches the database.
RUN cp .env.example .env \
    && php artisan key:generate --no-interaction \
    && npm run build

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
RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache \
    storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# A fast class map: worth it on a small free instance.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative --no-interaction

EXPOSE 8080

# A sleeping free-tier service is woken by a health check on /up.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
    CMD wget -qO- http://127.0.0.1:8080/up > /dev/null || exit 1

CMD ["start"]
