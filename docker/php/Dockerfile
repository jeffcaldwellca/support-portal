FROM php:8.1-apache

# Enable Apache mod_rewrite
RUN a2enmod rewrite headers

# Install system dependencies
RUN apt-get update && apt-get install -y \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    libldap2-dev \
    libonig-dev \
    libicu-dev \
    libxml2-dev \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure ldap \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_sqlite \
        gd \
        ldap \
        mbstring \
        zip \
        exif \
        intl \
        opcache \
        fileinfo

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set Apache DocumentRoot to public directory
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Configure Apache for Slim Framework
RUN echo '<Directory /var/www/html/public>\n\
    Options FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/slim.conf \
    && a2enconf slim

# Set working directory
WORKDIR /var/www/html

# Configure PHP for production
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Create custom PHP configuration
RUN echo "upload_max_filesize = 20M" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "post_max_size = 25M" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "memory_limit = 256M" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "max_execution_time = 600" >> "$PHP_INI_DIR/conf.d/custom.ini"

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
