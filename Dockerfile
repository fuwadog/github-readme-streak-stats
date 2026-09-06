# syntax=docker/dockerfile:1

# Use PHP 8.4 on the supported Debian Trixie base.
FROM docker.io/library/node:24.20.0-trixie-slim@sha256:50c3b2f6988dfc307b86e5301d69611af31f4789bdf232863b07d3b02fe55ae0 AS verification-node

FROM php:8.4-apache-trixie@sha256:51da594c844a97f31b1cd6b1ac6660982f40788f4fe13e75f7fd39e2f9b58651 AS runtime-base

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Install system dependencies and PHP extensions in one layer
RUN apt-get update && apt-get install -y --no-install-recommends \
    apache2=2.4.68-1~deb13u1 \
    openssl=3.5.7-1~deb13u2 \
    libxml2=2.12.7+dfsg+really2.9.14-2.1+deb13u3 \
    libxml2-dev=2.12.7+dfsg+really2.9.14-2.1+deb13u3 \
    perl=5.40.1-6 \
    libaprutil1t64=1.6.3-3+deb13u1 \
    libgnutls30t64=3.8.9-3+deb13u4 \
    libc6=2.41-12+deb13u3 \
    libc-bin=2.41-12+deb13u3 \
    libc-dev-bin=2.41-12+deb13u3 \
    linux-libc-dev=6.12.107-1 \
    libexpat1=2.8.3-1~deb13u1 \
    dpkg=1.22.22 \
    libpam0g=1.7.0-5 \
    libpcre2-8-0=10.46-1~deb13u1 \
    libsqlite3-0=3.46.1-7+deb13u1 \
    git=1:2.47.3-0+deb13u1 \
    unzip=6.0-29+deb13u1 \
    libicu-dev=76.1-4 \
    libcurl4-openssl-dev=8.14.1-2+deb13u4 \
    curl=8.14.1-2+deb13u4 \
    libcap2-bin=1:2.75-10+deb13u1+b1 \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" curl intl \
    && php -m | grep -qx curl \
    && php -m | grep -qx intl \
    && setcap cap_net_bind_service=+ep /usr/sbin/apache2 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer in the shared build base.
COPY --from=composer/composer:2-bin@sha256:536116acd18cd2d99d0351c1dfded22c83f34cef481d83f2747ff2cbca7587cd /composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Non-deployed verification target. It deliberately contains Node.js and
# development dependencies only so the default production target does not.
FROM runtime-base AS verification

USER root

COPY --from=verification-node /usr/local/ /usr/local/
COPY composer.json composer.lock package.json package-lock.json .prettierignore .prettierrc.js ./
COPY api/ ./api/
COPY tests/ ./tests/
COPY scripts/ ./scripts/
COPY docs/ ./docs/
COPY .github/ ./.github/
COPY README.md CONTRIBUTING.md CODE_OF_CONDUCT.md .editorconfig .env.example vercel.json ./

RUN npm ci --ignore-scripts \
    && apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && pecl install pcov-1.0.12 \
    && docker-php-ext-enable pcov \
    && php -m | grep -qx pcov \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts \
    && composer check

# npm is required only while building the verification image. Remove its
# bundled dependency tree before the image is scanned and retained.
RUN rm -rf /usr/local/lib/node_modules/npm \
    /usr/local/bin/npm \
    /usr/local/bin/npx \
    /usr/local/bin/corepack

# Default/deployable production target.
FROM runtime-base AS production

# Copy composer files and install dependencies
COPY composer.json composer.lock ./
COPY api/ ./api/
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Keep build-only tooling and development headers out of the production image.
RUN apt-get purge -y --auto-remove \
    git \
    unzip \
    libicu-dev \
    libcurl4-openssl-dev \
    libcap2-bin \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Configure Apache to serve from api/ directory and pass environment variables
RUN a2enmod rewrite headers && \
    printf '%b' 'ServerTokens Prod\n\
    ServerSignature Off\n\
    PassEnv TOKEN\n\
    PassEnv TOKEN2\n\
    PassEnv WHITELIST\n\
    PassEnv DISABLE_CACHE\n\
    PassEnv CACHE_TTL\n\
    PassEnv CACHE_TTL_DEFAULT\n\
    PassEnv RATE_LIMITER_MODE\n\
    <VirtualHost *:80>\n\
    ServerAdmin webmaster@localhost\n\
    DocumentRoot /var/www/html/api\n\
    <Directory /var/www/html/api>\n\
    Options -Indexes\n\
    AllowOverride None\n\
    Require all granted\n\
    Header always set Access-Control-Allow-Origin "*"\n\
    Header always set X-Frame-Options "DENY"\n\
    Header always set Referrer-Policy "strict-origin-when-cross-origin"\n\
    Header always set Content-Type "image/svg+xml" "expr=%{REQUEST_URI} =~ m#\\.svg$#i"\n\
    Header always set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; img-src data:;" "expr=%{REQUEST_URI} =~ m#\\.svg$#i"\n\
    Header always set X-Content-Type-Options "nosniff"\n\
    </Directory>\n\
    ErrorLog /proc/self/fd/2\n\
    CustomLog /proc/self/fd/1 combined\n\
    </VirtualHost>' > /etc/apache2/sites-available/000-default.conf

RUN mkdir -p /var/www/html/cache

# Set secure permissions (cache dir needs write access for www-data)
RUN mkdir -p /var/run/apache2 /var/lock/apache2 && \
    chown -R www-data:www-data /var/www/html /var/run/apache2 /var/lock/apache2 && \
    find /var/www/html -type d -exec chmod 755 {} \; && \
    find /var/www/html -type f -exec chmod 644 {} \; && \
    chmod 775 /var/www/html/cache

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD ["curl", "--fail", "--silent", "--show-error", "http://localhost/demo/"]

# Expose port
EXPOSE 80

# Apache binds port 80 with a narrowly scoped file capability; request workers
# and the application run as the non-root www-data user.
USER www-data

# Start Apache
CMD ["apache2-foreground"]
