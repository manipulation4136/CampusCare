FROM php:8.2-apache

# Install mysqli extension for database connection
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copy all files to the server
COPY . /var/www/html/

# ✅ FIX: Create uploads folder and set permissions
RUN mkdir -p /var/www/html/uploads && chmod 777 /var/www/html/uploads

# Open port 80
EXPOSE 80
