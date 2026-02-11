FROM php:8.4-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install Node.js 22.x (versi terbaru LTS)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy package files terlebih dahulu (untuk caching layer)
COPY package*.json ./

# Install Node dependencies
RUN npm ci --ignore-scripts || npm install --ignore-scripts

# Copy composer files
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-scripts

# Copy seluruh aplikasi
COPY . .

# Build assets (dengan error handling)
RUN npm run build || echo "Build skipped - no build script found"

# Set permissions
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache

# Cache Laravel configs
RUN php artisan config:clear && \
    php artisan cache:clear && \
    php artisan view:clear

EXPOSE 8000

CMD php artisan migrate --force && \
    php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache && \
    php artisan serve --host=0.0.0.0 --port=${PORT:-8000}