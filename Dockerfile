FROM php:8.4-fpm

# 1. تثبيت الأدوات والملحقات المطلوبة
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev libpq-dev zip unzip nginx \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. تثبيت امتدادات PHP لقواعد البيانات
RUN docker-php-ext-install pdo_mysql pdo_pgsql pgsql mbstring exif pcntl bcmath gd

# 3. تثبيت Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. تحديد مجلد العمل
WORKDIR /var/www

# 5. نسخ كافة ملفات المشروع أولاً (لتوفير ملف artisan وبقية السكربتات)
COPY . .

# 6. تثبيت الحزم مع إضافة خيار --no-scripts لتجنب المشاكل أثناء الـ Build
RUN composer install --no-interaction --optimize-autoloader --no-dev --ignore-platform-reqs --no-scripts

# 7. ضبط صلاحيات مجلدات Laravel
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

# 8. إعداد خادم Nginx
COPY ./nginx.conf /etc/nginx/sites-available/default

EXPOSE 80

# 9. تشغيل أمر package:discover ثم الـ Migrations و Nginx عند بدء الحاوية
CMD php artisan package:discover --ansi && php artisan migrate --seed --force && php-fpm -D && nginx -g 'daemon off;'
