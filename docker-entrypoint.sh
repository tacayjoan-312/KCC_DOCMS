#!/bin/bash
set -e

PORT_NUM=${PORT:-80}

echo "Configuring Apache to listen on port ${PORT_NUM}..."
sed -i "s/Listen 80/Listen ${PORT_NUM}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:${PORT_NUM}>/g" /etc/apache2/sites-available/*.conf

exec apache2-foreground
