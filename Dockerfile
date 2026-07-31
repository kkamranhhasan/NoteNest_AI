FROM php:8.2-apache

# Install PHP extensions + Composer dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    curl \
    && docker-php-ext-install mysqli pdo pdo_mysql zip \
    && a2enmod rewrite \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first (for layer caching)
COPY composer.json composer.lock ./

# Install PHP dependencies (no dev packages)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy rest of application files
COPY . /var/www/html

# Ensure uploads directories have correct permissions
RUN mkdir -p uploads/notes uploads/recordings img/user_photos \
    && chmod -R 777 uploads img/user_photos

# Expose port 80
EXPOSE 80
