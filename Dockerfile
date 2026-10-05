FROM php:8.2-apache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install required PHP extensions (like curl)
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl

# Copy your application code to the Apache document root
COPY . /var/www/html/

# Expose the default port
ENV PORT=80
EXPOSE 80

# Configure Apache to listen on the $PORT environment variable dynamically at runtime
CMD sed -i "s/80/$PORT/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf && docker-php-entrypoint apache2-foreground
