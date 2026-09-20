# syntax=docker/dockerfile:1

# Keep development checks on the same canonical Node.js 24.20.0 image as the
# verification target in the root Dockerfile.
FROM docker.io/library/node:24.20.0-trixie-slim@sha256:50c3b2f6988dfc307b86e5301d69611af31f4789bdf232863b07d3b02fe55ae0 AS node

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
    test "$(node --version)" = "v24.20.0" && \
    php --version | grep -q '^PHP 8\.4\.' && \
    php -m | grep -qx curl && \
    php -m | grep -qx intl && \
    php -m | grep -qx mbstring && \
    php -m | grep -qx dom && \
    php -m | grep -qx xml && \
    php -m | grep -qx xmlwriter && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*
