FROM ghcr.io/khoirulanammyid/laravel-base-image@sha256:9c40603c43401990f872397fab2fc7df8473296402c122fcbc8b9ca0fcebb612

# Install Nginx and PostgreSQL client & extensions for CodeIgniter 4
RUN apk add --no-cache nginx postgresql-dev \
    && docker-php-ext-install pdo_pgsql pgsql

# Copy Nginx server configuration
COPY nginx/default.conf /etc/nginx/http.d/default.conf

# Copy container entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Set working directory
WORKDIR /var/www/html

# Copy application files (used when deployed or built standalone)
COPY . /var/www/html

# Ensure write permissions for CodeIgniter's writable directory
RUN mkdir -p /var/www/html/writable/cache /var/www/html/writable/logs /var/www/html/writable/session /var/www/html/writable/uploads \
    && chmod -R 777 /var/www/html/writable

# Expose HTTP port
EXPOSE 80

# Run both PHP-FPM and Nginx via entrypoint
CMD ["/usr/local/bin/docker-entrypoint.sh"]
