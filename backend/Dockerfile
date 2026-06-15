FROM php:8.4-fpm-alpine

# System libs + PHP extensions. phpredis (PECL) gives us a fast Redis client for
# cache / queue / OTP storage; the rest match a standard Laravel API footprint.
RUN apk add --no-cache \
        git curl libpng-dev libjpeg-turbo-dev libwebp-dev libzip-dev \
        icu-dev oniguruma-dev freetype-dev libxml2-dev \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql mbstring exif gd zip intl opcache bcmath pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize \
    && php artisan storage:link || true \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000

# Retry migrate until the DB accepts queries (mysqladmin ping can pass before the
# server is query-ready on first init), then start php-fpm.
CMD ["sh", "-c", "for i in $(seq 1 30); do php artisan migrate --force && break; echo \"db not ready, retry $i\"; sleep 2; done; php-fpm"]
