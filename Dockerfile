FROM php:8.3-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev \
 && docker-php-ext-install pdo_mysql zip intl \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# COMPOSER_HOME in /tmp so the non-root container user can write to it.
# PHP_CLI_SERVER_WORKERS > 1 lets `artisan serve` handle concurrent requests
# (the default single worker would silently serialise parallel bookings).
ENV COMPOSER_HOME=/tmp/composer \
    PHP_CLI_SERVER_WORKERS=4

WORKDIR /var/www/html

COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
