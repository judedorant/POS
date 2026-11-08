# syntax=docker/dockerfile:1
FROM php:8.3-cli
RUN --mount=type=secret,id=proxy_ca,target=/usr/local/share/ca-certificates/session.crt update-ca-certificates && apt-get update && apt-get install -y libpq-dev libonig-dev libxml2-dev unzip git && docker-php-ext-install pdo_pgsql mbstring dom xml xmlwriter && rm -rf /var/lib/apt/lists/*
RUN update-ca-certificates --fresh
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN docker-php-ext-install pcntl
WORKDIR /app
CMD ["php", "artisan", "serve", "--host=0.0.0.0"]
