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

# 5. نسخ كافة ملفات المشروع
COPY . .

# 6. تثبيت الحزم بدون تشغيل السكربتات التلقائية
RUN composer install --no-interaction --optimize-autoloader --no-dev --ignore-platform-reqs --no-scripts

# 7. ضبط صلاحيات مجلدات Laravel
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

# 8. إعداد خادم Nginx
COPY ./nginx.conf /etc/nginx/sites-available/default

EXPOSE 80

# 9. التعديل هنا: حذف الجدول وإنشاؤها من جديد مع زرع البيانات
CMD php artisan package:discover --ansi && php artisan migrate:fresh --seed --force && php-fpm -D && nginx -g 'daemon off;'
