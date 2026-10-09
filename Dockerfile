# syntax=docker/dockerfile:1
#
# Image chạy ứng dụng (PHP-FPM 8.3). Mã nguồn được mount từ thư mục dự án
# (xem docker-compose.yml), nên image chỉ chứa PHP, extension và Composer.
FROM php:8.3-fpm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    TZ=Asia/Ho_Chi_Minh

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql pgsql redis intl gd zip bcmath pcntl exif opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/app-entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]
