FROM php:8.2-apache

# Disable conflicting MPM modules and force mpm_prefork
RUN a2dismod mpm_event mpm_worker || true \
    && a2enmod mpm_prefork

RUN docker-php-ext-install mysqli pdo pdo_mysql

COPY . /var/www/html/

EXPOSE 80