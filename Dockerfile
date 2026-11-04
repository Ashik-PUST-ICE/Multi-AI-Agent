FROM node:22-bookworm-slim AS assets

WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

FROM php:8.3-fpm-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        libfreetype6 \
        libicu72 \
        libjpeg62-turbo \
        libonig5 \
        libpng16-16 \
        libwebp7 \
        libzip4 \
        tar \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd intl mbstring opcache pcntl pdo_mysql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-mark manual libfreetype6 libicu72 libjpeg62-turbo libonig5 libpng16-16 libwebp7 libzip4 \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS libfreetype6-dev libicu-dev libjpeg62-turbo-dev libonig-dev libpng-dev libwebp-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY docker/app-entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod 0555 /usr/local/bin/app-entrypoint

FROM php-base AS app-build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader
COPY . .
COPY --from=assets /build/public/build ./public/build
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && php artisan package:discover --ansi \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

FROM php-base AS app

WORKDIR /var/www/html
COPY --from=app-build --chown=www-data:www-data /var/www/html /var/www/html
USER www-data
ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:1.29-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app-build /var/www/html/public /var/www/html/public
USER 101:101
ENTRYPOINT ["nginx"]
CMD ["-g", "daemon off;"]
