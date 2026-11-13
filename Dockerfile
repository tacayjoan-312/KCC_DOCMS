FROM php:8.2-apache

# Install PDO MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache mod_rewrite for .htaccess support
RUN a2enmod rewrite

# Enable AllowOverride All for /var/www/html
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Copy project files
WORKDIR /var/www/html
COPY . /var/www/html/

# Ensure storage directories exist and are writable
RUN mkdir -p /var/www/html/storage/uploads && \
    chmod -R 777 /var/www/html/storage

EXPOSE 80

CMD ["apache2-foreground"]
