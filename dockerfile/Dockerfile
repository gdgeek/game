# Dockerfile-app

# Use PHP 5.6 with Apache for the base image
FROM  php:8.3-apache

# Enable the Rewrite Apache mod
RUN cd /etc/apache2/mods-enabled && \
    ln -s ../mods-available/rewrite.load && \
    ln -s ../mods-available/headers.load 

# Install required PHP extensions
# -- GD
RUN apt-get update && apt-get install -y \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        git \
        openssl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

RUN docker-php-ext-install -j$(nproc) mysqli pdo pdo_mysql


RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY ./api/ /var/www/html/api/

RUN chmod -R 777 /var/www/html/api/runtime
RUN chmod -R 777 /var/www/html/api/web/assets
RUN chmod -R 755 /var/www/html

COPY files/api/config/* /var/www/html/api/config/

COPY files/api/web/index.php /var/www/html/api/web/
COPY files/api/web/robots.txt /var/www/html/api/web/

# Copy HTTP server config
COPY ./dockerfile/000-default.conf /etc/apache2/sites-available/

WORKDIR /var/www/html/api

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && export PATH="${PATH}:/usr/local/bin" \
    && composer install


