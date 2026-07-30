FROM php:8.2-apache

# Install PHP extensions required for MySQL and general extensions
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    && docker-php-ext-install mysqli pdo pdo_mysql zip \
    && a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html

# Ensure uploads directories have correct permissions
RUN mkdir -p uploads/notes uploads/recordings img/user_photos \
    && chmod -R 777 uploads img/user_photos

# Expose port 80
EXPOSE 80
