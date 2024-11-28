# Base Image
FROM php:8.1-apache

# Set working directory
WORKDIR /var/www/html

# Install necessary PHP extensions and dependencies
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite

# Enable Apache mod_rewrite for clean URLs
RUN a2enmod rewrite

# Copy application files to the container
COPY . /var/www/html

# Move SQLite database outside the web root
RUN mkdir /var/www/db && \
    chown -R www-data:www-data /var/www/db && \
    chmod -R 700 /var/www/db

# Set file permissions for Apache
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# Expose port 80 for HTTP
EXPOSE 80

# Start Apache server
CMD ["apache2-foreground"]
