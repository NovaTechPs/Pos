FROM php:8.4-fpm

# تثبيت الملحقات المطلوبة لتشغيل لارافل وقاعدة البيانات
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev zip unzip nginx

RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# تثبيت Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# نسخ ملفات المشروع إلى السيرفر
WORKDIR /var/www
COPY . .

# تثبيت حزم Laravel وتجهيز الصلاحيات
RUN composer install --no-interaction --optimize-autoloader --no-dev
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

# إعداد خادم Nginx
COPY ./nginx.conf /etc/nginx/sites-available/default

EXPOSE 80

CMD php artisan migrate --force && service nginx start && php-fpm
