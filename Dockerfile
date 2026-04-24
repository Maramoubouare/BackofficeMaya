FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    git curl zip unzip libzip-dev libicu-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring zip intl opcache \
    && apt-get clean

RUN a2enmod rewrite && a2dismod mpm_event && a2enmod mpm_prefork

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

ENV APP_ENV=prod
ENV APP_SECRET=temporarysecretforbuilding123456
ENV DATABASE_URL="mysql://root:root@localhost:3306/maya_db?serverVersion=8.0"

RUN composer install --no-dev --optimize-autoloader --no-scripts

RUN mkdir -p var/cache var/log && chmod -R 777 var/

RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html/public\n\
    <Directory /var/www/html/public>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80
CMD ["/bin/sh", "/start.sh"]
