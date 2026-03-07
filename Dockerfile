FROM php:8.2-apache

# Enable Apache Rewrite Module (for clean URLs)
RUN a2enmod rewrite

# Install Database Extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copy Application Files
COPY . /var/www/html/

# Fix Permissions (Critical for Render)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Create Uploads Folder with Permissions
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 777 /var/www/html/uploads

# Configure Apache to allow .htaccess
RUN echo '<Directory /var/www/html/> \n\
    Options Indexes FollowSymLinks \n\
    AllowOverride All \n\
    Require all granted \n\
    </Directory>' > /etc/apache2/conf-available/override.conf \
    && a2enconf override

# Configure PHP upload limits
RUN { \
    echo "file_uploads = On"; \
    echo "memory_limit = 256M"; \
    echo "upload_max_filesize = 50M"; \
    echo "post_max_size = 50M"; \
    echo "max_execution_time = 600"; \
    } > /usr/local/etc/php/conf.d/uploads.ini

EXPOSE 80
