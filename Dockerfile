FROM php:8.2-apache

# Install ca-certificates and PDO MySQL
RUN apt-get update && apt-get install -y ca-certificates && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache rewrite module
RUN a2enmod rewrite

# Enable AllowOverride for .htaccess in /var/www/html
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . /var/www/html/

# Ensure storage directories exist and are writable
RUN mkdir -p /var/www/html/storage/uploads && \
    chmod -R 777 /var/www/html/storage

# Copy entrypoint script to dynamically bind to Render's assigned PORT
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80 10000

CMD ["docker-entrypoint.sh"]
