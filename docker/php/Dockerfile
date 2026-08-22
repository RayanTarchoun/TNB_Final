# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Image applicative TNB : PHP 8.4 + Apache + Symfony
#
# Le meme Dockerfile sert au developpement et a la production ; seule la cible
# ("stage") change, ce qui garantit un environnement homogene du poste de dev
# jusqu'au serveur (CDCF 3.5 "Portabilite").
#
# PHP 8.4 est la version minimale exigee par les dependances du projet :
# doctrine/doctrine-bundle 3.3 et doctrine-migrations-bundle 4.0 requierent
# ^8.4. Le CDCF 3.4 demande "PHP 8+", cette version le satisfait.
# ---------------------------------------------------------------------------

FROM php:8.4-apache AS base

# Dependances systeme necessaires aux extensions PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# Controle de sante interroge par Docker : l'application est consideree
# saine si /sante repond, ce qui inclut la joignabilite de la base.
#
# Le delai est volontairement large : en developpement, le code est monte
# depuis l'hote et Symfony tourne en mode debug, ce qui porte le temps de
# reponse a plusieurs secondes. L'image de production, elle, repond en
# quelques dizaines de millisecondes (code embarque + opcache preload).
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -fsS http://localhost/sante || exit 1

# Extensions exigees par Symfony et Doctrine
RUN docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        pdo_mysql \
        zip \
        gd \
        opcache

# Apache : reecriture d'URL et en-tetes, indispensables au controleur frontal
RUN a2enmod rewrite headers
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ---------------------------------------------------------------------------
# Developpement : le code est monte en volume, opcache en revalidation
# ---------------------------------------------------------------------------
FROM base AS dev

ENV APP_ENV=dev
COPY docker/php/php.dev.ini /usr/local/etc/php/conf.d/tnb.ini

# ---------------------------------------------------------------------------
# Production : le code et les dependances sont embarques dans l'image
# ---------------------------------------------------------------------------
FROM base AS prod

ENV APP_ENV=prod
ENV APP_DEBUG=0
COPY docker/php/php.prod.ini /usr/local/etc/php/conf.d/tnb.ini

# Les dependances sont installees avant le code applicatif : tant que
# composer.json ne change pas, cette couche reste en cache.
COPY composer.json composer.lock symfony.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .

RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer run-script --no-dev post-install-cmd \
    && chown -R www-data:www-data var

USER www-data
