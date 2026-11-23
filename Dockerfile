# syntax=docker/dockerfile:1
#
# Nextcloud with the Merlin app preinstalled - for local development and
# testing, not for production. See docker/README.md for usage.
#
#   docker compose up --build        (recommended, includes database + cron)
#   docker build -t merlin-nextcloud .
#
# Where the app source comes from:
#   - built inside a merlin-nextcloud checkout: the local working tree
#   - built anywhere else (only Dockerfile, docker-compose.yml and docker/ in
#     the directory): cloned from GitHub, branch/tag/commit via MERLIN_REF
#
# The source stage picks one, then the next stage builds the Vue frontend
# (frontend/ -> nextcloud/js, nextcloud/css), stage 2 installs the PHP runtime
# dependencies (vendor/, including the shared core/), stage 3 puts both
# together with the app source and content-filters/ onto
# the official Nextcloud image. The app itself is copied into custom_apps and
# enabled on container start by docker/merlin-hook.sh, because custom_apps
# lives on the persistent /var/www/html volume.

# Nextcloud major version; appinfo/info.xml currently supports 30-35.
ARG NEXTCLOUD_VERSION=35-apache

# ---------------------------------------------------------------------------
FROM alpine:3.22@sha256:49340b346798875b5592e5d420241f2f9cfba5f47e62ebb1446a1b34734e898f AS source
ARG MERLIN_REPO=https://github.com/Dexxes/merlin-nextcloud.git
ARG MERLIN_REF=main
# BuildKit re-checks the remote ref on every build, so a new commit on
# MERLIN_REF is picked up without --no-cache. Only used without a checkout.
ADD ${MERLIN_REPO}#${MERLIN_REF} /git
COPY . /ctx
RUN if [ -f /ctx/nextcloud/appinfo/info.xml ]; then \
        echo "Merlin source: local checkout"; mv /ctx /app; \
    else \
        echo "Merlin source: ${MERLIN_REPO}#${MERLIN_REF}"; mv /git /app; \
    fi \
 && rm -rf /app/.git /app/frontend/node_modules /app/nextcloud/vendor \
           /app/nextcloud/js /app/docker /app/Dockerfile /app/docker-compose.yml

# ---------------------------------------------------------------------------
FROM node:22-alpine@sha256:1314fb30ec37592d5845e5a160d522c0e680972aa534549940b8d73bde88481a AS frontend
WORKDIR /build
# Installs exactly what frontend/package-lock.json pins (npm ci).
# Same layout as the repository: frontend/ builds into ../nextcloud/js and
# ../nextcloud/css (see frontend/vite.config.mjs).
COPY --from=source /app/frontend/package.json /app/frontend/package-lock.json ./frontend/
RUN cd frontend && npm ci --no-audit --no-fund
COPY --from=source /app/frontend ./frontend
COPY --from=source /app/nextcloud/css ./nextcloud/css
RUN cd frontend && npm run build

# ---------------------------------------------------------------------------
FROM composer:2@sha256:50ea4d33fdcde1adb0f045547295faa1548b33872e5f789dae5b02ce2fb5de93 AS vendor
# Same layout as the repository: nextcloud/composer.json pulls the shared core
# (composer package merlin/core) from the path repository ../core. Mirroring
# copies it into vendor/merlin/core instead of symlinking it.
ENV COMPOSER_MIRROR_PATH_REPOS=1
WORKDIR /build/nextcloud
COPY --from=source /app/core ../core
COPY --from=source /app/nextcloud/composer.json ./
COPY --from=source /app/nextcloud/lib ./lib
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# ---------------------------------------------------------------------------
FROM nextcloud:${NEXTCLOUD_VERSION}

# Optional short commit hash, shown by the app (appinfo/build.txt).
ARG MERLIN_BUILD=docker

# Staged outside /var/www/html; the hook syncs it into custom_apps/merlin.
COPY --from=source --chown=www-data:www-data /app/nextcloud /usr/src/merlin
COPY --from=source --chown=www-data:www-data /app/README.md /app/CHANGELOG.md /app/LICENSE /app/NOTICE /usr/src/merlin/
COPY --from=frontend --chown=www-data:www-data /build/nextcloud/js /usr/src/merlin/js
COPY --from=frontend --chown=www-data:www-data /build/nextcloud/css /usr/src/merlin/css
COPY --from=vendor --chown=www-data:www-data /build/nextcloud/vendor /usr/src/merlin/vendor
# Bundled content filters live at the repository root, shared by all variants.
COPY --from=source --chown=www-data:www-data /app/content-filters /usr/src/merlin/content-filters
RUN echo "${MERLIN_BUILD}" > /usr/src/merlin/appinfo/build.txt

# Runs on every start, after a fresh install or upgrade (official image hook).
COPY --chmod=755 docker/merlin-hook.sh /docker-entrypoint-hooks.d/before-starting/50-merlin.sh
