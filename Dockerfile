# syntax=docker/dockerfile:1

FROM php:8.2-fpm-alpine AS base

# System packages: nginx web server, su-exec for dropping privileges, and helpers.
RUN apk add --no-cache \
        nginx \
        su-exec \
        curl \
        bash \
        tzdata \
    && mkdir -p /www

# Install PHP extensions required by the application.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions \
        pdo_mysql \
        pdo_sqlite \
        bcmath \
        pcntl \
        zip \
        gd \
        redis \
        opcache \
        sockets \
        mbstring \
        exif \
        intl

# Install Composer from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy the application source.
WORKDIR /www
COPY . .

# Install production dependencies and warm the autoloader.
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && composer check-platform-reqs --no-dev

# Create a dedicated runtime user (uid 1000) and grant ownership of the app.
RUN addgroup -g 1000 -S www \
    && adduser -u 1000 -S -G www -s /sbin/nologin www \
    && chown -R www:www /www

# Runtime configuration: nginx site, PHP-FPM pool, and PHP settings.
COPY deploy/nginx.conf /etc/nginx/http.d/default.conf
COPY deploy/www.conf /usr/local/etc/php-fpm.d/zz-cboard.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-cboard.ini
COPY deploy/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
