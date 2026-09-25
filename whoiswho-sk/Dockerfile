FROM php:8.4-apache

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip \
    && rm -rf /var/lib/apt/lists/*

RUN { \
    echo "expose_php = Off"; \
  } > /usr/local/etc/php/conf.d/whoiswho-hardening.ini && \
  { \
    echo "ServerTokens Prod"; \
    echo "ServerSignature Off"; \
  } > /etc/apache2/conf-available/whoiswho-hardening.conf && \
  ln -sf /etc/apache2/conf-available/whoiswho-hardening.conf /etc/apache2/conf-enabled/whoiswho-hardening.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction

COPY . .

RUN rm -f bootstrap/cache/*.php \
    && composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi

RUN chown -R www-data:www-data storage bootstrap/cache \
    && a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

EXPOSE 80

CMD ["apache2-foreground"]
