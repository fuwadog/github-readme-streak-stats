# syntax=docker/dockerfile:1

# The slim Debian base does not include a CA bundle. Bootstrap trust from the
# already verified, digest-pinned Debian-derived runtime source used by the
# main image; never inherit certificates from the build host.
FROM docker.io/library/php:8.4-apache-trixie@sha256:51da594c844a97f31b1cd6b1ac6660982f40788f4fe13e75f7fd39e2f9b58651 AS ca-trust
RUN test -s /etc/ssl/certs/ca-certificates.crt \
    || { echo 'ERROR: verified CA bundle is unavailable' >&2; exit 1; }

# Use a pinned Debian Trixie runtime base; the renderer does not require Node.
FROM docker.io/library/debian:13-slim@sha256:d7e12182ce18b85b93007c1dedf31f2d29e01ccf3182cc4017c709b6259bc132 AS renderer-base

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Make the verified trust bundle available before any snapshot access.
COPY --from=ca-trust /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
# Debian slim omits OpenSSL's default CA-file path; establish it before apt.
RUN mkdir -p /usr/lib/ssl \
    && ln -s /etc/ssl/certs/ca-certificates.crt /usr/lib/ssl/cert.pem

# Keep every renderer package resolution on the same immutable official Debian
# snapshot. The timestamp is after the authoritative package publication dates
# for all explicitly pinned versions below; apt fails if any pin is unavailable.
RUN rm -f /etc/apt/sources.list /etc/apt/sources.list.d/* \
    && printf '%s\n' \
        'deb [check-valid-until=no] https://snapshot.debian.org/archive/debian/20260901T000000Z trixie main' \
        'deb [check-valid-until=no] https://snapshot.debian.org/archive/debian-security/20260901T000000Z trixie-security main' \
        > /etc/apt/sources.list.d/debian-snapshot.list \
    && test -s /etc/ssl/certs/ca-certificates.crt \
    && (apt-get -o APT::Update::Error-Mode=any update || { echo 'ERROR: Debian snapshot TLS/source access failed' >&2; exit 1; }) \
    && for package_version in \
        'ca-certificates=20250419' \
        'python3=3.13.5-1' \
        'inkscape=1.4-6' \
        'fonts-dejavu-core=2.37-8' \
        'libssl3t64=3.5.7-1~deb13u2' \
        'openssl-provider-legacy=3.5.7-1~deb13u2'; do \
        package="${package_version%%=*}"; \
        expected="${package_version#*=}"; \
        candidate="$(apt-cache policy "${package}" | awk '/Candidate:/ { print $2; exit }')"; \
        if [[ "${candidate}" != "${expected}" ]]; then \
            printf 'ERROR: snapshot candidate for %s is %s; expected %s\n' "${package}" "${candidate:-<none>}" "${expected}" >&2; \
            exit 1; \
        fi; \
    done \
    && apt-get install -y --no-install-recommends \
        ca-certificates=20250419 \
        python3=3.13.5-1 \
        inkscape=1.4-6 \
        fonts-dejavu-core=2.37-8 \
        libssl3t64=3.5.7-1~deb13u2 \
        openssl-provider-legacy=3.5.7-1~deb13u2 \
    && update-ca-certificates \
    && test "$(dpkg-query -W -f='${Version}' ca-certificates)" = '20250419' \
    && test "$(dpkg-query -W -f='${Version}' python3)" = '3.13.5-1' \
    && test "$(dpkg-query -W -f='${Version}' inkscape)" = '1.4-6' \
    && test "$(dpkg-query -W -f='${Version}' fonts-dejavu-core)" = '2.37-8' \
    && test "$(dpkg-query -W -f='${Version}' libssl3t64)" = '3.5.7-1~deb13u2' \
    && test "$(dpkg-query -W -f='${Version}' openssl-provider-legacy)" = '3.5.7-1~deb13u2' \
    && rm -rf /usr/local/lib/node_modules /usr/local/bin/node /usr/local/bin/npm /usr/local/bin/npx /usr/local/bin/corepack \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd --gid 10001 renderer \
    && useradd --uid 10001 --gid 10001 --create-home --home-dir /nonexistent --shell /usr/sbin/nologin renderer \
    && install --directory --owner=renderer --group=renderer --mode=0750 /run/streak-renderer /tmp/renderer

FROM renderer-base AS production

WORKDIR /app
COPY --chown=renderer:renderer renderer/server.py /app/server.py
RUN chmod 0555 /app/server.py

ENV HOME=/tmp/renderer \
    TMPDIR=/tmp/renderer \
     RENDERER_SOCKET=/run/streak-renderer/renderer.sock \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

# The renderer has no TCP listener. The socket is intended to be shared only
# with the web process through a private Unix-socket volume.
USER renderer
STOPSIGNAL SIGTERM
CMD ["python3", "/app/server.py"]

FROM production AS test

COPY --chown=renderer:renderer renderer/test_server.py /app/test_server.py
RUN chmod 0555 /app/test_server.py
