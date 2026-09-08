# syntax=docker/dockerfile:1
# Local development image for Linkboard (PHP-FPM behind nginx).
# Every external input is pinned by tag AND digest (resolved 2026-09-08);
# bumping one is a reviewed change.
FROM php:8.4.25-fpm-alpine@sha256:49734670eccf414af884c2a0c2e558401e228615f8028f1c9fca30a0d4fb1bc2

# Extension installer: compiles/enables extensions with their build deps
# and removes the deps afterwards (build-time only, no runtime footprint).
COPY --from=ghcr.io/mlocati/php-extension-installer:2.11.12@sha256:b6d3fa381b9ba5cf051117c1c601d6a523b590e534bf3d56eb4fbe352949c138 \
     /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql intl opcache redis gd zip apcu

COPY --from=composer:2.10.3@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 \
     /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache git unzip postgresql16-client

COPY conf.d/app.ini /usr/local/etc/php/conf.d/app.ini

# Run as the developer's ids so the bind-mounted var/ and vendor/ stay
# owned by the host user. Names are HOST_UID/HOST_GID on purpose: UID and
# GID are read-only variables in Bash and cannot be passed from a shell.
ARG HOST_UID=1000
ARG HOST_GID=1000
RUN set -eu; \
    if ! getent group "$HOST_GID" >/dev/null; then addgroup -g "$HOST_GID" app; fi; \
    GROUP_NAME="$(getent group "$HOST_GID" | cut -d: -f1)"; \
    adduser -D -u "$HOST_UID" -G "$GROUP_NAME" -h /app -s /bin/sh app; \
    sed -i "s/^user = .*/user = app/; s/^group = .*/group = $GROUP_NAME/" /usr/local/etc/php-fpm.d/www.conf; \
    mkdir -p /app && chown "$HOST_UID:$HOST_GID" /app

WORKDIR /app
USER app

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=0

EXPOSE 9000
CMD ["php-fpm"]
