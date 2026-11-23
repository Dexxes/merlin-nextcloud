# Produktions-Image des Standalone-Servers (ghcr.io/dexxes/merlin-standalone).
# Gebaut aus dem Wurzelordner des Repos:
#
#   docker build -f standalone/Dockerfile -t merlin-standalone .
#   docker run -p 8080:8080 -v merlin-data:/data \
#     -e MERLIN_BASE_URL=http://localhost:8080 \
#     -e MERLIN_ADMIN_USER=admin -e MERLIN_ADMIN_EMAIL=admin@example.org \
#     -e MERLIN_ADMIN_PASSWORD=… merlin-standalone
#
# Stufe 1 baut die Weboberfläche (frontend/ -> standalone/public/app), Stufe 2
# stellt mit standalone/tools/assemble.sh dasselbe Paket zusammen wie der
# Release-Tarball, Stufe 3 legt es mit nginx und PHP-FPM auf PHP 8.4 (Alpine).
# Datenbank, Log und Instanz-Geheimnis liegen im Volume /data, Migrationen
# laufen beim Start, die täglichen Aufgaben übernimmt ein Cron-Lauf im
# Container. Einstellungen kommen aus Umgebungsvariablen (docker/config.php)
# oder, falls vorhanden, aus /data/config.php. Siehe standalone/README.md.

# ---------------------------------------------------------------------------
FROM node:22-alpine AS frontend
WORKDIR /build
# Kein package-lock.json im Repo, daher wie `npm install` frisch aufgelöst.
COPY frontend/package.json ./frontend/
RUN cd frontend && npm install --no-audit --no-fund
COPY frontend ./frontend
RUN mkdir -p standalone/public && cd frontend && npm run build:standalone

# ---------------------------------------------------------------------------
FROM composer:2 AS package
WORKDIR /build
COPY core ./core
COPY content-filters ./content-filters
COPY nextcloud/l10n ./nextcloud/l10n
COPY nextcloud/img ./nextcloud/img
COPY nextcloud/appinfo/info.xml ./nextcloud/appinfo/info.xml
COPY LICENSE NOTICE CHANGELOG.md ./
COPY standalone ./standalone
COPY --from=frontend /build/standalone/public/app ./standalone/public/app
RUN rm -rf standalone/vendor standalone/data standalone/config/config.php \
 && sh standalone/tools/assemble.sh /merlin

# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine

RUN apk add --no-cache nginx su-exec icu-libs \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev \
 && docker-php-ext-install -j"$(nproc)" intl opcache \
 && apk del .build-deps \
 && rm -rf /tmp/pear

COPY standalone/docker/php.ini /usr/local/etc/php/conf.d/merlin.ini
COPY standalone/docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-merlin.conf
COPY standalone/docker/nginx.conf /etc/nginx/http.d/default.conf
COPY --chmod=755 standalone/docker/entrypoint.sh /usr/local/bin/merlin-entrypoint
COPY --from=package --chown=root:root /merlin /var/www/merlin
COPY standalone/docker/config.php /var/www/merlin/config/docker.php

ENV MERLIN_BASE_URL=http://localhost:8080 \
    MERLIN_PHP_WORKERS=16

WORKDIR /var/www/merlin
VOLUME /data
EXPOSE 8080
HEALTHCHECK --interval=60s --timeout=5s --start-period=20s \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/api/capabilities || exit 1

ENTRYPOINT ["merlin-entrypoint"]
CMD ["nginx", "-g", "daemon off;"]
