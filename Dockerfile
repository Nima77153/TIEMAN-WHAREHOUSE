FROM php:8.2-apache

# Disable conflicting MPM modules and enable prefork
RUN a2dismod mpm_event mpm_worker || true \
    && a2enmod mpm_prefork

# Install PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copy project files
COPY . /var/www/html/

# Allow Apache/PHP to write uploaded images
RUN chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 775 /var/www/html/uploads

EXPOSE 80
