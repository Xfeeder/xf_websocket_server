# Stage 1: composer to install dependencies
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --prefer-dist --optimize-autoloader --ignore-platform-req=ext-pdo_pgsql

# Stage 2: runtime image
FROM php:8.2-cli

# Install PostgreSQL and MySQL extensions
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
      libpq-dev libzip-dev zip unzip git \
      default-mysql-client libmariadb-dev curl && \
    docker-php-ext-install pdo pdo_pgsql pgsql pdo_mysql mysqli && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy composer-installed vendor from previous stage
COPY --from=vendor /app/vendor ./vendor
COPY . .

# Create directory for logs
RUN mkdir -p /app/logs && chmod 777 /app/logs

# Health check for Render
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD curl -f http://localhost:10000/health || exit 1

EXPOSE 10000
CMD ["php", "server.php"]
