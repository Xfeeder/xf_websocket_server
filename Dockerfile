# Stage 1: composer to install dependencies
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --prefer-dist --optimize-autoloader

# Stage 2: runtime image
FROM php:8.2-cli

# Install system deps and enable pdo_mysql
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
      libzip-dev zip unzip git && \
    docker-php-ext-install pdo_mysql && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy composer-installed vendor from previous stage
COPY --from=vendor /app/vendor ./vendor
COPY . .

# Expose no-op — Render expects you to bind the port from PORT env var
EXPOSE 8080

CMD ["php", "websocket_server.php"]
