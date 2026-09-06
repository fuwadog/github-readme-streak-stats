# syntax=docker/dockerfile:1

# Use a pinned Debian Trixie runtime base; the renderer does not require Node.
FROM docker.io/library/debian:13-slim@sha256:d7e12182ce18b85b93007c1dedf31f2d29e01ccf3182cc4017c709b6259bc132 AS renderer-base

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Keep every renderer package resolution on the same immutable official Debian
# snapshot. The timestamp is after the authoritative package publication dates
# for all explicitly pinned versions below; apt fails if any pin is unavailable.
RUN rm -f /etc/apt/sources.list /etc/apt/sources.list.d/* \
    && printf '%s\n' \
        'deb [check-valid-until=no] http://snapshot.debian.org/archive/debian/20260901T000000Z trixie main' \
        'deb [check-valid-until=no] http://snapshot.debian.org/archive/debian-security/20260901T000000Z trixie-security main' \
        > /etc/apt/sources.list.d/debian-snapshot.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        python3=3.13.5-1 \
        inkscape=1.4-6 \
        fonts-dejavu-core=2.37-8 \
        libssl3t64=3.5.7-1~deb13u2 \
        openssl-provider-legacy=3.5.7-1~deb13u2 \
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
