# Official Composer image, pinned to its multi-architecture manifest digest.
FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS php-dependencies
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --classmap-authoritative \
    && composer audit --no-interaction

# Official PHP 8.3/Apache image, pinned to the multi-architecture manifest digest.
FROM php:8.3-apache-trixie@sha256:c2c73159da2f7a19167a2849bd9785ca9ad4f5eeff01f85295ef861a5e9bcf4d

RUN docker-php-ext-install pdo_mysql opcache \
    && a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!/var/www/html/public!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '%s\n' \
        'ServerName localhost' \
        'ServerTokens Prod' \
        'ServerSignature Off' \
        'TraceEnable Off' \
        '<Directory /var/www/html/public>' \
        'Options -Indexes' \
        'AllowOverride FileInfo' \
        'LimitRequestBody 1048576' \
        '</Directory>' \
        > /etc/apache2/conf-available/app-security.conf \
    && a2enconf app-security \
    && printf '%s\n' \
        'expose_php=Off' \
        'display_errors=Off' \
        'log_errors=On' \
        'zend.exception_ignore_args=On' \
        'memory_limit=128M' \
        'max_execution_time=30' \
        'max_input_time=30' \
        'post_max_size=1M' \
        'cgi.fix_pathinfo=0' \
        'opcache.enable=1' \
        'opcache.memory_consumption=128' \
        'opcache.max_accelerated_files=10000' \
        'opcache.validate_timestamps=0' \
        'session.sid_length=48' \
        'session.sid_bits_per_character=6' \
        'session.use_strict_mode=1' \
        'session.use_only_cookies=1' \
        > /usr/local/etc/php/conf.d/app-security.ini

WORKDIR /var/www/html
COPY . /var/www/html
COPY --from=php-dependencies /app/vendor /var/www/html/vendor
RUN chmod +x /var/www/html/docker-entrypoint.sh \
    && mkdir -p /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/storage

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
CMD ["apache2-foreground"]

EXPOSE 80
