# syntax=docker/dockerfile:1
FROM php:8.3-fpm-alpine AS php-base
RUN --mount=type=secret,id=proxy_ca,target=/run/secrets/proxy_ca \
    if [ -f /run/secrets/proxy_ca ]; then cp /run/secrets/proxy_ca /usr/local/share/ca-certificates/session.crt; fi; \
    update-ca-certificates && apk add --no-cache libpq oniguruma libxml2 fcgi unzip git \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev oniguruma-dev libxml2-dev \
    && docker-php-ext-install pdo_pgsql mbstring dom xml xmlwriter pcntl opcache \
    && apk del .build-deps && rm -f /usr/local/share/ca-certificates/session.crt && update-ca-certificates --fresh
WORKDIR /app
FROM php-base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN --mount=type=secret,id=proxy_ca     if [ -f /run/secrets/proxy_ca ]; then export COMPOSER_CAFILE=/run/secrets/proxy_ca; fi;     composer install --no-dev --no-scripts --prefer-dist --no-interaction --optimize-autoloader
COPY . .
RUN mkdir -p storage/framework/views storage/framework/cache storage/logs bootstrap/cache && composer dump-autoload --no-dev --optimize --no-scripts && php artisan package:discover
FROM node:22-alpine AS frontend
WORKDIR /app
COPY package*.json ./
RUN --mount=type=secret,id=proxy_ca     if [ -f /run/secrets/proxy_ca ]; then export NODE_EXTRA_CA_CERTS=/run/secrets/proxy_ca; fi; npm ci
COPY . .
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ENV VITE_REVERB_PORT=443 VITE_REVERB_SCHEME=https
RUN npm run build
FROM php-base AS runtime
COPY --from=vendor --chown=10001:10001 /app /app
COPY --from=frontend --chown=10001:10001 /app/public /app/public
COPY --chmod=644 deploy/php.ini /usr/local/etc/php/conf.d/production.ini
COPY --chmod=644 deploy/fpm.conf /usr/local/etc/php-fpm.d/zz-production.conf
ENV LARAVEL_STORAGE_PATH=/tmp/storage APP_CONFIG_CACHE=/tmp/config.php APP_ROUTES_CACHE=/tmp/routes.php APP_EVENTS_CACHE=/tmp/events.php VIEW_COMPILED_PATH=/tmp/storage/framework/views
USER 10001:10001
ENTRYPOINT ["/app/deploy/entrypoint.sh"]
CMD ["php-fpm", "-F"]
FROM caddy:2-alpine AS web
COPY --from=runtime /app/public /app/public
COPY deploy/Caddyfile /etc/caddy/Caddyfile
