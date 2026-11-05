# Build Stage for PHP dependencies
FROM alpine:3.24 AS composer
WORKDIR /app
RUN apk add --no-cache git unzip "php83>=8.3.36" php83-phar php83-openssl \
    php83-curl php83-mbstring php83-dom php83-xml php83-xmlwriter \
    php83-ctype php83-tokenizer php83-fileinfo php83-pdo php83-pdo_sqlite \
    php83-session php83-sodium php83-iconv php83-zip \
    && ln -s /usr/bin/php83 /usr/bin/php
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && composer audit --no-dev --locked

# Build Stage for Node.js dependencies and assets
FROM node:24-alpine3.24 AS node
WORKDIR /app
COPY . .

RUN npm ci && npm audit --audit-level=moderate && \
    npx vite build \
    && rm -rf node_modules

# Final production image
FROM alpine:3.24

LABEL maintainer="Dean Ward"

# Multi-arch support - TARGETARCH is automatically set by Docker buildx
ARG TARGETARCH

# Version passed from build system (e.g., Komodo)
ARG VERSION=unknown
ENV APP_VERSION=${VERSION}

WORKDIR /var/www/html

ENV TZ=UTC
ENV SUPERVISOR_PHP_FPM_COMMAND="/usr/sbin/php-fpm83 -F"
ENV SUPERVISOR_CADDY_COMMAND="/usr/sbin/caddy run --config /etc/caddy/Caddyfile"
ENV SUPERVISOR_PHP_USER="erugo"

# Use the maintained Alpine community repository for PHP packages.
RUN sed -i 's|http://|https://|g' /etc/apk/repositories

# Install production requirements
RUN apk add --no-cache \
    curl \
    su-exec \
    sudo \
    supervisor \
    cronie \
    ffmpeg \
    "php83>=8.3.36" \
    php83-pdo_pgsql \
    php83-pdo_sqlite \
    php83-gd \
    php83-curl \
    php83-pecl-mongodb \
    php83-imap \
    php83-pdo_mysql \
    php83-mbstring \
    php83-xml \
    php83-zip \
    php83-bcmath \
    php83-soap \
    php83-intl \
    php83-ldap \
    php83-pecl-redis \
    php83-pecl-memcached \
    php83-phar \
    php83-openssl \
    php83-json \
    php83-dom \
    php83-tokenizer \
    php83-fileinfo \
    php83-ctype \
    php83-session \
    php83-simplexml \
    php83-xmlwriter \
    php83-sodium \
    php83-iconv \
    tzdata \
    libcap \
    zip \
    php83-fpm \
    caddy \
    && ln -s /usr/bin/php83 /usr/bin/php \
    && php -r 'exit(version_compare(PHP_VERSION, "8.3.36", ">=") ? 0 : 1);' \
    && rm -rf /var/cache/apk/*

# Download and install tusd for the correct architecture
ENV TUSD_VERSION=2.6.0
RUN TUSD_ARCH="${TARGETARCH}" && \
    if [ "$TUSD_ARCH" = "amd64" ]; then TUSD_ARCH="amd64"; \
    elif [ "$TUSD_ARCH" = "arm64" ]; then TUSD_ARCH="arm64"; \
    else echo "Unsupported architecture: $TARGETARCH" && exit 1; fi && \
    curl -L -o /tmp/tusd.tar.gz "https://github.com/tus/tusd/releases/download/v${TUSD_VERSION}/tusd_linux_${TUSD_ARCH}.tar.gz" && \
    tar -xzf /tmp/tusd.tar.gz -C /tmp && \
    mv /tmp/tusd_linux_${TUSD_ARCH}/tusd /usr/local/bin/tusd && \
    chmod +x /usr/local/bin/tusd && \
    rm -rf /tmp/tusd.tar.gz /tmp/tusd_linux_${TUSD_ARCH}

# Create necessary directories
RUN mkdir -p /run/php /var/www/html/public /etc/php83/php-fpm.d /etc/caddy

COPY ./docker/alpine/www.conf /etc/php83/php-fpm.d/www.conf
COPY ./docker/alpine/Caddyfile /etc/caddy/Caddyfile

# Set timezone
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Configure PHP capabilities
RUN setcap "cap_net_bind_service=+ep" /usr/bin/php83

# Create erugo user and group
RUN addgroup erugo && \
    adduser -D -G erugo erugo

# Set permissions
RUN chown -R erugo:erugo /run/php

# Copy necessary files from build stages and local context
COPY --chown=erugo:erugo . /var/www/html/
COPY --from=composer /app/vendor /var/www/html/vendor
COPY --from=node /app/public/build /var/www/html/public/build
RUN rm -rf /var/www/html/.env /var/www/html/.git /var/www/html/node_modules /var/www/html/storage

# Set up Laravel scheduler cron job
RUN echo "* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1" | crontab -u erugo -

# Copy configuration files
COPY ./docker/alpine/start-container /usr/local/bin/start-container
COPY ./docker/alpine/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY ./docker/alpine/php.ini /etc/php83/conf.d/99-erugo.ini
RUN chmod +x /usr/local/bin/start-container

# Create supervisor log directory
RUN mkdir -p /var/log/supervisor

EXPOSE 80/tcp

ENTRYPOINT ["start-container"]