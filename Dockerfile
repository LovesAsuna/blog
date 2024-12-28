ARG TAG=fpm-bullseye
FROM php:$TAG AS PHP

WORKDIR /var/www/html

COPY app/ Caddyfile justfile ./

RUN apt-get update \
  && apt-get install -y wget \
  libpq-dev \
  wget \
  unzip \
  zlib1g-dev \
  libjpeg62-turbo-dev \
  libfreetype6-dev \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) \
  pdo_pgsql \
  pgsql \
  pdo_mysql \
  mysqli \
  gd

RUN curl --proto '=https' --tlsv1.2 -sSf https://just.systems/install.sh | bash -s -- --to /usr/local/bin
CMD ["just"]
