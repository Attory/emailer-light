ARG PHP_VERSION=8.4.24

FROM composer:2.10.2 AS composer_binary

FROM php:${PHP_VERSION}-cli-bookworm

ARG GITLEAKS_VERSION=8.30.1
ARG GITLEAKS_SHA256=551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb

RUN apt-get update \
    && apt-get install --yes --no-install-recommends \
        ca-certificates \
        curl \
        git \
        jq \
        libcurl4-openssl-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        ripgrep \
        unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j2 curl gd intl mbstring pdo_mysql zip \
    && curl --fail --silent --show-error --location \
        "https://github.com/gitleaks/gitleaks/releases/download/v${GITLEAKS_VERSION}/gitleaks_${GITLEAKS_VERSION}_linux_x64.tar.gz" \
        --output /tmp/gitleaks.tar.gz \
    && printf '%s  %s\n' "${GITLEAKS_SHA256}" /tmp/gitleaks.tar.gz | sha256sum --check \
    && tar -xzf /tmp/gitleaks.tar.gz -C /usr/local/bin gitleaks \
    && apt-get clean \
    && find /var/lib/apt/lists /tmp -mindepth 1 -depth -delete

COPY --from=composer_binary /usr/bin/composer /usr/local/bin/composer

RUN git config --system safe.directory /app \
    && groupadd --gid 1000 emailer \
    && useradd --create-home --uid 1000 --gid emailer emailer \
    && mkdir -p /app /emailer-secrets /opt/emailer-assets-vendor /opt/emailer-vendor \
    && chown -R emailer:emailer /app /emailer-secrets /opt/emailer-assets-vendor /opt/emailer-vendor

WORKDIR /app

ENV APP_ENV=dev \
    APP_DEBUG=0 \
    APP_SECRET=phase1-container-only \
    DATABASE_URL=mysql://emailer:local-only@database:3306/emailer_test?serverVersion=10.11.19-MariaDB\&charset=utf8mb4 \
    DEFAULT_URI=http://127.0.0.1:8080 \
    LOCK_DSN=flock \
    MAILER_DSN=smtp://mailpit:1025 \
    MESSENGER_TRANSPORT_DSN=doctrine://default

COPY --chown=emailer:emailer composer.json composer.lock symfony.lock ./

USER emailer

RUN composer install \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts \
    && composer validate --strict \
    && composer audit --locked --abandoned=fail

COPY --chown=emailer:emailer . .

RUN composer dump-autoload --classmap-authoritative --no-interaction \
    && composer run auto-scripts \
    && mkdir -p var \
    && cp -a vendor/. /opt/emailer-vendor/ \
    && cp -a assets/vendor/. /opt/emailer-assets-vendor/

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
