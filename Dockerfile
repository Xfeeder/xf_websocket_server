# Stage 1: composer to install dependencies
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --prefer-dist --optimize-autoloader --ignore-platform-req=ext-pdo_pgsql

# Stage 2: runtime image
FROM php:8.2-cli

# Install PostgreSQL extensions
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
      libpq-dev libzip-dev zip unzip git && \
    docker-php-ext-install pdo pdo_pgsql pgsql && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy composer-installed vendor from previous stage
COPY --from=vendor /app/vendor ./vendor
COPY . .

EXPOSE 8080
CMD ["php", "websocket_server.php"]
