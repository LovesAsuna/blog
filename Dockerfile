ARG TAG=fpm-bullseye
FROM php:$TAG

WORKDIR /var/www/html

COPY app/ Caddyfile Makefile ./

RUN apt-get update \
  && apt-get install -y wget \
  libpq-dev \
  wget \
  unzip

RUN docker-php-ext-install -j$(nproc) \
  pdo_pgsql \
  pgsql \
  pdo_mysql \
  mysqli

CMD ["make"]
