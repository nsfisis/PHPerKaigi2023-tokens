FROM composer:2.5.1

FROM php:8.2.1

WORKDIR /work

RUN : && \
    apt-get update && \
    apt-get install -y git libffi-dev unzip && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/* && \
    :

RUN : && \
    docker-php-ext-configure ffi --with-ffi && \
    docker-php-ext-install ffi && \
    :

COPY --from=composer /usr/bin/composer /usr/bin/composer

COPY Q3.composer.json Q3.composer.lock /work/

RUN : && \
    git clone --depth=1 --branch=v9.5.0 https://github.com/laravel/laravel.git && \
    cd laravel && \
    cp -f ../Q3.composer.json composer.json && \
    cp -f ../Q3.composer.lock composer.lock && \
    COMPOSER_ALLOW_SUPERUSER=1 composer install --prefer-dist --no-dev && \
    cp -f .env.example .env && \
    php artisan key:generate --ansi && \
    :

COPY Q3.php /work/
