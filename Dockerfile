# syntax=docker/dockerfile:1

# ---- Stage 1: Composer dependencies ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# Install WITH dev dependencies: the database seeder relies on fakerphp/faker
# (a require-dev package) to generate the demo data on first boot.
# --no-scripts: artisan package:discover runs at container boot instead (needs the full app).
# ext-mongodb is only needed at runtime (installed in the app stage below), so skip
# that platform check while merely downloading dependencies here.
RUN composer install --no-scripts --no-interaction --prefer-dist \
    --optimize-autoloader --no-progress --ignore-platform-req=ext-mongodb

# ---- Stage 2: Runtime (PHP-FPM + nginx via supervisor) ----
# PHP 8.4: the locked dependencies require >= 8.4.1.
FROM php:8.4-fpm-alpine AS app
WORKDIR /var/www/html

# System packages + PHP extensions. predis is pure-PHP (no redis ext); mongodb
# (for activity logs) needs the pecl ext, built with temporary toolchain deps.
RUN apk add --no-cache nginx supervisor postgresql-dev bash \
    && docker-php-ext-install pdo pdo_pgsql bcmath pcntl opcache \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS openssl-dev \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/pear

# Application source + vendor from the composer stage.
COPY . /var/www/html
COPY --from=vendor /app/vendor /var/www/html/vendor

# Service configs.
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache \
       storage/logs bootstrap/cache /run/nginx \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
