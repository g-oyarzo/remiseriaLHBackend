# syntax=docker/dockerfile:1.7
#
# Imagen de producción del backend (PHP-FPM + CLI para colas, scheduler y
# Reverb). Multi-arquitectura: linux/amd64 y linux/arm64 (Oracle Cloud
# Ampere A1). Todas las imágenes base son oficiales y publican variantes
# arm64 nativas; no se usa emulación.
#
#   docker buildx build --platform linux/arm64 -f deploy/docker/php/Dockerfile -t remiseria-lh/app .
#
# Versiones fijadas a propósito: actualizar en un PR y volver a probar.

ARG PHP_VERSION=8.5
ARG COMPOSER_VERSION=2.8

# --- Dependencias de Composer (sin dev) -------------------------------------
FROM composer:${COMPOSER_VERSION} AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts \
        --prefer-dist --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# --- Runtime ----------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS app

# Extensiones: pdo_mysql (MySQL), redis (cache, colas, Reverb scaling),
# pcntl (señales de queue:work y reverb:start), bcmath e intl (Laravel),
# zip (exportación Excel de reportes). Se compilan para la arquitectura de
# la imagen (también en arm64). OPcache viene integrado en PHP 8.5 (ya no
# es una extensión aparte): solo se configura en php.ini.
RUN set -eux; \
    apk add --no-cache icu-libs libzip fcgi tzdata; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev linux-headers; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl bcmath intl zip; \
    pecl install redis-6.3.0; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -rf /tmp/pear ~/.pearrc; \
    php -m | grep -qi '^zend opcache$'; \
    for ext in pdo_mysql pcntl bcmath intl zip redis mbstring; do php -m | grep -qi "^$ext$"; done

COPY deploy/docker/php/php.ini /usr/local/etc/php/conf.d/99-remiseria.ini
COPY deploy/docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-remiseria.conf
COPY deploy/docker/php/entrypoint.sh /usr/local/bin/remiseria-entrypoint

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html

# Archivos que nunca deben ir a la imagen (también listados en .dockerignore).
RUN set -eux; \
    rm -rf .env tests node_modules graphify-out storage/logs/* .phpunit.result.cache; \
    mkdir -p storage/app/private/comprobantes storage/framework/cache storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod +x /usr/local/bin/remiseria-entrypoint

ENV TZ=America/Argentina/Buenos_Aires
USER www-data

# Healthcheck de PHP-FPM (ping del pool por FastCGI).
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

ENTRYPOINT ["remiseria-entrypoint"]
CMD ["php-fpm"]
