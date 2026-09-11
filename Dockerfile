FROM php:8.4-fpm

# 1. تثبيت الملحقات المطلوبة والأدوات اللازمة
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev libpq-dev zip unzip nginx \
    && apt-get clean && rm -rf /var/var/lib/apt/lists/*

# 2. تثبيت تعريفات PHP وقواعد البيانات (PostgreSQL & MySQL)
RUN docker-php-ext-install pdo_mysql pdo_pgsql pgsql mbstring exif pcntl bcmath gd

# 3. نسخ Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. تحديد مجلد العمل
WORKDIR /var/www

# 5. نسخ ملفات Composer أولاً لتسريع عملية البناء (Docker Layer Caching)
COPY composer.json composer.lock ./

# 6. تثبيت الحزم وتجاوز فحص المتطلبات أثناء الـ Build
RUN composer install --no-interaction --optimize-autoloader --no-dev --ignore-platform-reqs

# 7. نسخ باقي ملفات المشروع بالكامل
COPY . .

# 8. ضبط صلاحيات مجلدات Storage و Cache
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

# 9. إعداد Nginx
COPY ./nginx.conf /etc/nginx/sites-available/default

EXPOSE 80

# 10. تشغيل الـ Migration والـ Seed ثم تشغيل PHP-FPM و Nginx معاً
CMD php artisan migrate --seed --force && php-fpm -D && nginx -g 'daemon off;'
