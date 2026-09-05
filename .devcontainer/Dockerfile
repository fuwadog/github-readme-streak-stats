# syntax=docker/dockerfile:1

FROM docker.io/library/node:24.7.0-bookworm-slim@sha256:0104d9447ea3ddf7373643be7f9915fc7b7c896e41d0d33229338e457217cd78 AS node

FROM mcr.microsoft.com/devcontainers/php:1-8.4-bookworm@sha256:5b7eb0fa180636ba5a610bb0757a202c95de7665bb7e45e9f7961ee331889dd8

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

COPY first-run-notice.txt /usr/local/etc/vscode-dev-containers/first-run-notice.txt
COPY --from=node /usr/local/ /usr/local/

RUN rm -f /etc/apt/sources.list.d/yarn.list && \
    apt-get update -y && \
    apt-get install -y --no-install-recommends \
        inkscape=1.2.2-2+b1 \
        libicu-dev=72.1-3+deb12u1 && \
    docker-php-ext-configure intl && \
    docker-php-ext-install -j"$(nproc)" intl && \
    test "$(node --version)" = "v24.7.0" && \
    php --version | grep -q '^PHP 8\.4\.' && \
    php -m | grep -qx curl && \
    php -m | grep -qx intl && \
    php -m | grep -qx mbstring && \
    php -m | grep -qx dom && \
    php -m | grep -qx xml && \
    php -m | grep -qx xmlwriter && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*
