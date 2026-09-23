# Build context is the repository root (see docker-compose.yml):
#   docker build -f examples/simple_app/Dockerfile .
FROM composer:2 AS build

WORKDIR /app

COPY examples/simple_app/composer.json ./
RUN composer install --no-interaction --no-progress --no-dev

FROM php:8.3-cli-alpine

WORKDIR /app

COPY --from=build /app/vendor ./vendor
COPY examples/simple_app/public ./public

EXPOSE 8080

# Served by the PHP built-in webserver (single process, demo only). The
# router script receives every request, so the guard screens all of them,
# including 404s. Production deployments should use FrankenPHP, FPM, or a
# long-running worker runtime instead.
CMD ["php", "-S", "0.0.0.0:8080", "public/index.php"]
