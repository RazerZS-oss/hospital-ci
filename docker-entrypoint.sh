#!/bin/sh
set -e

# Support dynamic PORT (e.g., Render, Koyeb, Cloud Run)
PORT="${PORT:-80}"
sed -i "s/listen [0-9]\+;/listen ${PORT};/g" /etc/nginx/http.d/default.conf

# Ensure CodeIgniter writable subdirectories exist and have proper permissions
mkdir -p /var/www/html/writable/cache /var/www/html/writable/logs /var/www/html/writable/session /var/www/html/writable/uploads
chmod -R 777 /var/www/html/writable

# Start PHP-FPM in daemon mode
echo "Starting PHP-FPM..."
php-fpm -D

# Start Nginx in foreground on configured port
echo "Starting Nginx on port ${PORT}..."
exec nginx -g "daemon off;"
