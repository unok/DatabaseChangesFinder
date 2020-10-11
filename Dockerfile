FROM php:7.4-cli

RUN ln -sf /usr/share/zoneinfo/Asia/Tokyo /etc/localtime
RUN apt-get update && apt-get install -y git libpq-dev && pecl install xdebug && docker-php-ext-enable xdebug && docker-php-ext-install pdo_pgsql
RUN curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer && chmod +x /usr/local/bin/composer