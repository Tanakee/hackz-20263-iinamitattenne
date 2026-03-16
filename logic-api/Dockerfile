FROM php:8.2-cli

WORKDIR /app

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . .

# Start built-in PHP server
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000"]
