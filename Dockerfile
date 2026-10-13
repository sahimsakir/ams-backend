FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y libpq-dev libzip-dev libonig-dev libxml2-dev libsqlite3-dev libpng-dev unzip git && docker-php-ext-install pdo_pgsql pdo_sqlite zip pcntl mbstring dom xml xmlwriter gd && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-interaction --prefer-dist --optimize-autoloader && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 8000
CMD ["php","artisan","serve","--host=0.0.0.0","--port=8000"]
