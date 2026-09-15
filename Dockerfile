FROM php:8.4-fpm

# 1. تثبيت الأدوات والملحقات المطلوبة + Node.js & npm
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev libpq-dev zip unzip nginx \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. تثبيت امتدادات PHP لقواعد البيانات
RUN docker-php-ext-install pdo_mysql pdo_pgsql pgsql mbstring exif pcntl bcmath gd

# 3. تثبيت Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. تحديد مجلد العمل
WORKDIR /var/www

# 5. نسخ كافة ملفات المشروع
COPY . .

# 6. تثبيت مكتبات PHP
RUN composer install --no-interaction --optimize-autoloader --no-dev --ignore-platform-reqs --no-scripts

# 7. تثبيت حزم NPM وتجميع ملفات CSS/JS (Vite/Tailwind)
RUN npm ci || npm install
RUN npm run build

# 8. ضبط الملكية وصلاحيات المجلدات كاملة لـ www-data (تشمل المجلدات الفرعية)
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache /var/www/public \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# 9. إعداد خادم Nginx
COPY ./nginx.conf /etc/nginx/sites-available/default

EXPOSE 80

# 10. تشغيل الأوامر الأساسية لـ Laravel وإنشاء الـ storage:link عند إقلاع الحاوية
CMD php artisan storage:link --force && \
    php artisan package:discover --ansi && \
    php artisan migrate --seed --force && \
    php-fpm -D && \
    nginx -g 'daemon off;'
