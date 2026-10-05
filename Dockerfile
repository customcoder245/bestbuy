FROM php:8.2-apache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install required PHP extensions (like curl)
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl

# Copy your application code to the Apache document root
COPY . /var/www/html/

# Expose port 80 for Render
EXPOSE 80
