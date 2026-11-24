FROM composer:2.8 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
# Image composer hanya membawa PHP minimal (tanpa pcntl/gd), jadi pengecekan ekstensi dilewati di
# sini dan dilakukan di image runtime di bawah (check-platform-reqs), tempat ekstensi itu dipasang.
# Versi PHP tetap dicek: hanya ext-* yang diabaikan.
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts \
    --ignore-platform-req='ext-*'

# FrankenPHP is a production PHP app server (Caddy + embedded PHP, multi-threaded).
# `php artisan serve` is a single-process development server and must not face traffic.
FROM dunglas/frankenphp:1.9-php8.4-bookworm

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PORT=8080

RUN install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        zip

WORKDIR /app

COPY --from=vendor /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .

# Build gagal di sini bila image runtime kekurangan ekstensi yang diminta composer.json/lock.
RUN composer check-platform-reqs --no-dev

# Run as www-data: Caddy needs its data/config dirs writable, and port 8080 needs no
# bind capability, so the file capability on the binary is dropped as well.
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache /data/caddy /config/caddy \
    && chmod -R ug+rwX storage bootstrap/cache \
    && setcap -r /usr/local/bin/frankenphp

USER www-data

EXPOSE 8080

CMD ["sh", "-c", "exec frankenphp php-server --listen \":${PORT:-8080}\" --root public/"]
