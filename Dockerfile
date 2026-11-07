FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libgmp-dev \
 && docker-php-ext-install -j"$(nproc)" gmp pdo_mysql \
 && rm -rf /var/lib/apt/lists/* \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Only public/ is served; src/ and bin/ sit beside it, out of reach.
ENV APACHE_DOCUMENT_ROOT=/app/public
RUN sed -ri 's#/var/www/html#${APACHE_DOCUMENT_ROOT}#g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
 && printf 'ServerTokens Prod\nServerSignature Off\n<Directory /app/public>\n  Options -Indexes\n  AllowOverride None\n  Require all granted\n</Directory>\n' > /etc/apache2/conf-enabled/zz-portal.conf \
 && mkdir -p /data /downloads && chown www-data:www-data /data

COPY --chown=root:root public /app/public
COPY --chown=root:root src /app/src
COPY --chown=root:root bin /app/bin
WORKDIR /app
