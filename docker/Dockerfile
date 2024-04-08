# This file is part of Bileto.
# Copyright 2022-2024 Probesys
# SPDX-License-Identifier: AGPL-3.0-or-later

FROM php:8.2-fpm

ENV COMPOSER_HOME /tmp

RUN apt-get update && apt-get install -y \
    git \
    libzip-dev \
    unzip \
  && docker-php-ext-install -j$(nproc) zip

COPY --from=composer/composer /usr/bin/composer /usr/bin/composer
