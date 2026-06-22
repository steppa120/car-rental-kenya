FROM php:8.2-apache

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd mysqli pdo pdo_mysql \
    && a2enmod rewrite headers

# PHP ini tweaks: hide version, turn off display_errors in production
RUN echo "expose_php = Off\ndisplay_errors = Off\nlog_errors = On\nerror_log = /dev/stderr\nupload_max_filesize = 10M\npost_max_size = 12M\nmax_execution_time = 30" \
    > /usr/local/etc/php/conf.d/custom.ini

# Copy application
COPY . /var/www/html/

# Ensure uploads directory exists and is writable
RUN mkdir -p /var/www/html/uploads/vehicle_images \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads

# Enable .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Render provides the port via $PORT — default 10000
EXPOSE 10000

CMD sed -i "s/Listen 80/Listen ${PORT:-10000}/" /etc/apache2/ports.conf \
    && sed -i "s/:80>/:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf \
    && apache2-foreground
