FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && sed -i 's/^ServerName.*//' /etc/apache2/apache2.conf || true

# Deshabilitar MPM event y habilitar MPM prefork para evitar conflictos
RUN a2dismod mpm_event || true \
    && a2enmod mpm_prefork || true

COPY . /var/www/html/

EXPOSE 80