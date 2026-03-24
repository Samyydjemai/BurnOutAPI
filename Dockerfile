FROM php:8.4-cli

# Installer les extensions nécessaires pour MySQL
RUN apt-get update && apt-get install -y libonig-dev default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql

# Copier Composer depuis l'image officielle
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

# Installer les dépendances PHP
RUN composer install --no-scripts --no-autoloader

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]