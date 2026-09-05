# syntax=docker/dockerfile:1

# Reuse the repository's already pinned Debian runtime base. Node is removed
# below; it is used only because this digest is already verified in CI.
FROM docker.io/library/node:24.7.0-bookworm-slim@sha256:0104d9447ea3ddf7373643be7f9915fc7b7c896e41d0d33229338e457217cd78 AS renderer-base

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        python3 \
        inkscape \
        fonts-dejavu-core \
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
