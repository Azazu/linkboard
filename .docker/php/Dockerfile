# syntax=docker/dockerfile:1
# Linkboard's PHP image, in three stages from one base so that the PHP version,
# the extension set and every pinned digest are written once (change
# stretch-public-hosting, design decision 1):
#
#   base — the interpreter, its extensions and composer. No user, no source.
#   dev  — base + a user built from the developer's own ids, for a bind-mounted
#          working tree. What `make up` starts; behaviour unchanged.
#   prod — base + the application installed inside the image, a user fixed in
#          the image, and production settings. Boots nothing at build time.
#
# The build context is the REPOSITORY ROOT for every stage, because the prod
# stage copies the application in and Docker cannot reach outside its context:
#
#   docker build -f .docker/php/Dockerfile --target prod -t linkboard-prod .
#
# `.dockerignore` keeps that context to the application itself (509 MB → 5.8 MB
# measured).
#
# Every external input is pinned by tag AND digest (resolved 2026-09-08);
# bumping one is a reviewed change.
FROM php:8.4.25-fpm-alpine@sha256:49734670eccf414af884c2a0c2e558401e228615f8028f1c9fca30a0d4fb1bc2 AS base

# Extension installer: compiles/enables extensions with their build deps
# and removes the deps afterwards (build-time only, no runtime footprint).
COPY --from=ghcr.io/mlocati/php-extension-installer:2.11.12@sha256:b6d3fa381b9ba5cf051117c1c601d6a523b590e534bf3d56eb4fbe352949c138 \
     /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql intl opcache redis gd zip apcu

COPY --from=composer:2.10.3@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 \
     /usr/bin/composer /usr/local/bin/composer

# make: lets the CI command path (make test-db EXEC= && make check EXEC=) run inside the container
RUN apk add --no-cache git unzip postgresql16-client make

COPY .docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /app

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=0

EXPOSE 9000

# ---------------------------------------------------------------------------
# Development: the working tree is mounted over /app, so nothing is copied in.
FROM base AS dev

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

USER app

CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Production: the application lives in the image and NOTHING here boots the
# kernel (design decision 2). Warming the cache, compiling the asset map and
# generating the signing keys are all kernel boots, and the boot is where the
# required-settings check runs — so doing them here would mean handing the
# build real secrets or exempting it from the check. They happen at container
# start instead: the shared artefacts in the one-shot init service
# (provision.sh), each container's private cache in its own entrypoint.
FROM base AS prod

# Settings that differ from development: no timestamp validation, a larger
# opcode cache, JIT off (this workload is I/O bound, not arithmetic).
COPY .docker/php/conf.d/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini

# A user fixed in the image, not taken from the building machine: the same
# image must behave identically wherever it was built.
RUN set -eu; \
    addgroup -g 1000 -S app; \
    adduser -u 1000 -S -G app -h /app -s /bin/sh app; \
    sed -i 's/^user = .*/user = app/; s/^group = .*/group = app/' /usr/local/etc/php-fpm.d/www.conf

# Dependencies first, so a source change does not reinstall them.
# --no-scripts is not an optimisation: `post-install-cmd` runs `cache:clear`,
# which boots the kernel.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --no-autoloader

COPY . .

RUN set -eu; \
    composer dump-autoload --no-dev --classmap-authoritative --no-scripts; \
    mkdir -p var config/jwt/prod public/assets public/bundles; \
    chown -R app:app var config/jwt public/assets public/bundles

COPY --chmod=0755 .docker/php/provision.sh /usr/local/bin/provision
COPY --chmod=0755 .docker/php/entrypoint.sh /usr/local/bin/entrypoint

ENV APP_ENV=prod \
    APP_DEBUG=0

USER app

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
