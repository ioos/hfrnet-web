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
FROM php:7.4-apache

ARG GOOGLE_MAPS_API_KEY=XXX
ENV GOOGLE_MAPS_API_KEY=$GOOGLE_MAPS_API_KEY

ARG API_URL=XXX
ENV API_URL=$API_URL

ARG SUBDIRECTORY=XXX
ENV SUBDIRECTORY=$SUBDIRECTORY

# Bullseye LTS ended 2026-08-31. deb.debian.org still publishes the
# bullseye-security index, but the .deb files now live on the archive.
RUN printf 'Acquire::Check-Valid-Until "false";\n' > /etc/apt/apt.conf.d/99no-check-valid-until \
 && sed -i \
      -e 's|http://deb.debian.org/debian-security|http://archive.debian.org/debian-security|g' \
      -e 's|http://security.debian.org/debian-security|http://archive.debian.org/debian-security|g' \
      /etc/apt/sources.list \
 && apt-get update && \
    apt-get install -y \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        zlib1g-dev && \
    docker-php-ext-configure gd --with-jpeg --with-freetype && \
    docker-php-ext-install -j"$(nproc)" gd mysqli pdo_mysql && \
    docker-php-ext-enable gd mysqli pdo_mysql && \
    rm -rf /var/lib/apt/lists/*

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
