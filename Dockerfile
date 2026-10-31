FROM php:8.5-fpm-bookworm AS backend
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip nginx libpng-dev libonig-dev libxml2-dev libpq-dev libzip-dev \
    default-mysql-client postgresql-client \
    && docker-php-ext-install pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd calendar zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY . .
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev --no-scripts \
    && composer check-platform-reqs --no-dev

FROM node:22-bookworm-slim AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=backend /var/www/vendor ./vendor
RUN npm run build


FROM backend
COPY --from=assets /build/public/build ./public/build
COPY nginx.conf /etc/nginx/sites-available/default
ENV APP_ENV=production APP_DEBUG=false
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache
EXPOSE 80
CMD php artisan package:discover --ansi --no-interaction \
    && php artisan migrate --force --no-interaction \
    && php artisan db:seed --class=MainDataSeeder --force --no-interaction \
    && php artisan storage:link --force --no-interaction \
    && php artisan optimize --no-interaction \
    && php-fpm -D \
    && nginx -g 'daemon off;'
