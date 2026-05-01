FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git curl zip unzip libzip-dev libicu-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring zip intl \
    && apt-get clean

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

ENV APP_ENV=prod
ENV APP_SECRET=temporarysecretforbuilding123456
ENV DATABASE_URL="mysql://root:root@localhost:3306/maya_db?serverVersion=8.0"

RUN composer install --no-dev --optimize-autoloader --no-scripts
RUN composer dump-autoload --optimize --no-dev
RUN mkdir -p var/cache var/log && chmod -R 777 var/

EXPOSE 8080
ENTRYPOINT ["/bin/sh", "/app/start.sh"]
