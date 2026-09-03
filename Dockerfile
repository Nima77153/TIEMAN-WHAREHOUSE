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

# --- Fix for Railway dynamic port ---
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf

EXPOSE 8080

CMD ["sh", "-c", "apache2-foreground"]