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
# The source stage picks one, then the next stage builds the Vue frontend (js/), stage 2 installs the PHP runtime
# dependencies (vendor/), stage 3 puts both together with the app source onto
# the official Nextcloud image. The app itself is copied into custom_apps and
# enabled on container start by docker/merlin-hook.sh, because custom_apps
# lives on the persistent /var/www/html volume.

# Nextcloud major version; appinfo/info.xml currently supports 30-35.
ARG NEXTCLOUD_VERSION=35-apache

# ---------------------------------------------------------------------------
FROM alpine:3.22 AS source
ARG MERLIN_REPO=https://github.com/Dexxes/merlin-nextcloud.git
ARG MERLIN_REF=main
# BuildKit re-checks the remote ref on every build, so a new commit on
# MERLIN_REF is picked up without --no-cache. Only used without a checkout.
ADD ${MERLIN_REPO}#${MERLIN_REF} /git
COPY . /ctx
RUN if [ -f /ctx/appinfo/info.xml ]; then \
        echo "Merlin source: local checkout"; mv /ctx /app; \
    else \
        echo "Merlin source: ${MERLIN_REPO}#${MERLIN_REF}"; mv /git /app; \
    fi \
 && rm -rf /app/.git /app/node_modules /app/vendor /app/js \
           /app/docker /app/Dockerfile /app/docker-compose.yml

# ---------------------------------------------------------------------------
FROM node:22-alpine AS frontend
WORKDIR /build
# No package-lock.json is committed, so this resolves fresh like `npm install`.
COPY --from=source /app/package.json ./
RUN npm install --no-audit --no-fund
COPY --from=source /app/vite.config.mjs ./
COPY --from=source /app/src ./src
COPY --from=source /app/css ./css
RUN npm run build

# ---------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /build
COPY --from=source /app/composer.json ./
COPY --from=source /app/lib ./lib
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# ---------------------------------------------------------------------------
FROM nextcloud:${NEXTCLOUD_VERSION}

# Optional short commit hash, shown by the app (appinfo/build.txt).
ARG MERLIN_BUILD=docker

# Staged outside /var/www/html; the hook syncs it into custom_apps/merlin.
COPY --from=source --chown=www-data:www-data /app /usr/src/merlin
COPY --from=frontend --chown=www-data:www-data /build/js /usr/src/merlin/js
COPY --from=frontend --chown=www-data:www-data /build/css /usr/src/merlin/css
COPY --from=vendor --chown=www-data:www-data /build/vendor /usr/src/merlin/vendor
RUN echo "${MERLIN_BUILD}" > /usr/src/merlin/appinfo/build.txt \
 && rm -rf /usr/src/merlin/src

# Runs on every start, after a fresh install or upgrade (official image hook).
COPY --chmod=755 docker/merlin-hook.sh /docker-entrypoint-hooks.d/before-starting/50-merlin.sh
