FROM php:8.3-apache

# Install system dependencies
RUN apt-get update && apt-get install -y git

# Install PHP extensions
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www