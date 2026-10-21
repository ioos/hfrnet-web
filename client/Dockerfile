# Build stage with Node.js
FROM node:24-alpine AS builder

ARG API_URL=XXX
ENV API_URL=$API_URL

ARG SUBDIRECTORY=
ENV SUBDIRECTORY=$SUBDIRECTORY

WORKDIR /app

COPY package.json package-lock.json webpack.config.js ./
COPY ./www ./www

RUN npm ci --only=production
RUN npm run minify


# Runtime stage with PHP/Apache
FROM php:8.4-apache-trixie

ARG GOOGLE_MAPS_API_KEY=XXX
ENV GOOGLE_MAPS_API_KEY=$GOOGLE_MAPS_API_KEY

ARG API_URL=XXX
ENV API_URL=$API_URL

ARG SUBDIRECTORY=XXX
ENV SUBDIRECTORY=$SUBDIRECTORY

# Install system dependencies and PHP extensions
RUN apt-get update && \
    apt-get install -y \
        libpng-dev \
        libjpeg-dev \
        libfreetype-dev \
        zlib1g-dev && \
    docker-php-ext-configure gd --with-jpeg --with-freetype && \
    docker-php-ext-install -j"$(nproc)" gd mysqli pdo_mysql && \
    docker-php-ext-enable gd mysqli pdo_mysql && \
    rm -rf /var/lib/apt/lists/*

# Hide deprecation notices from responses. The image ships with no php.ini,
# so PHP 8 would otherwise report E_ALL, including E_DEPRECATED.
COPY php/zz-hfrnet.ini $PHP_INI_DIR/conf.d/zz-hfrnet.ini

# Set working directory
WORKDIR /var/www

# Copy built application files from builder stage
COPY --from=builder /app/www /var/www

# Set permissions
RUN chown -R www-data:www-data /var/www

# Expose Apache's default HTTP port
EXPOSE 80

# Start Apache in foreground
CMD ["apache2-foreground"]
