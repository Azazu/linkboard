#!/bin/sh
# The one-shot init service of the production stack (change
# stretch-public-hosting, design decision 3). It is the ONLY writer of the
# shared volumes, and every long-running service waits for it to exit 0
# (`depends_on: { condition: service_completed_successfully }`).
#
# Two jobs:
#   1. the signing keypair, generated only when a usable one is absent;
#   2. the public assets the proxy serves, which PHP-FPM cannot serve itself.
#
# Both boot the kernel, which is why they are here and not in the image build:
# the boot runs the required-settings check, so it can only happen once the
# settings exist (design decision 2).
set -eu

KEYDIR=/app/config/jwt/prod
STAGING="$KEYDIR/.staging"
PRIVATE="$KEYDIR/private.pem"
PUBLIC="$KEYDIR/public.pem"

log() { printf 'provision: %s\n' "$1"; }

# --- the keypair -----------------------------------------------------------
#
# `lexik:jwt:generate-keypair --skip-if-exists` treats EITHER file's presence
# as "already provisioned", and it writes the two files separately. A run
# interrupted between those writes therefore leaves half a pair that every
# later run would accept. Two moves cannot be made one atomic operation, so the
# guarantee is not that an interrupted run leaves nothing behind — it is that
# what it leaves behind is never served (this script exits non-zero, so no
# dependant starts) and never inherited (the next run repairs it).
repair_keys() {
    if [ -e "$PRIVATE" ] && [ ! -e "$PUBLIC" ]; then
        log 'found a private key with no public key — discarding the partial pair'
        rm -f "$PRIVATE"
        return
    fi
    if [ -e "$PUBLIC" ] && [ ! -e "$PRIVATE" ]; then
        log 'found a public key with no private key — discarding the partial pair'
        rm -f "$PUBLIC"
        return
    fi
    if [ -e "$PRIVATE" ] && [ -e "$PUBLIC" ]; then
        if openssl rsa -in "$PRIVATE" -passin env:JWT_PASSPHRASE -pubout 2>/dev/null \
            | diff -q - "$PUBLIC" >/dev/null 2>&1; then
            log 'keypair present and matching'
        else
            log 'found a pair whose public key is not the one belonging to its private key — discarding it'
            rm -f "$PRIVATE" "$PUBLIC"
        fi
    fi
}

provision_keys() {
    repair_keys

    if [ -e "$PRIVATE" ] && [ -e "$PUBLIC" ]; then
        return
    fi

    log 'generating a keypair'
    rm -rf "$STAGING"
    mkdir -p "$STAGING"
    # Generated into a staging directory inside the same volume, so the move
    # into place is a rename on one filesystem rather than a copy.
    JWT_SECRET_KEY="$STAGING/private.pem" \
    JWT_PUBLIC_KEY="$STAGING/public.pem" \
        php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction

    # PROVISION_FAIL_BETWEEN_MOVES exists for the test that proves an
    # interrupted publication stops the deployment instead of being served
    # (tasks 2.3). It is never set outside that test.
    mv "$STAGING/private.pem" "$PRIVATE"
    if [ -n "${PROVISION_FAIL_BETWEEN_MOVES:-}" ]; then
        log 'interrupted between the two moves (test hook)'
        exit 70
    fi
    mv "$STAGING/public.pem" "$PUBLIC"
    rmdir "$STAGING" 2>/dev/null || true
    log 'keypair generated'
}

# --- the public assets -----------------------------------------------------
#
# `assets:install` produces public/bundles (API Platform's documentation UI),
# `asset-map:compile` produces public/assets (the web UI's own CSS and JS).
# Both are mounted into the proxy, which serves them directly; PHP-FPM cannot.
provision_assets() {
    log 'installing bundle assets'
    php bin/console assets:install public --no-interaction
    log 'compiling the asset map'
    php bin/console asset-map:compile --no-interaction
}

# --- the schema ------------------------------------------------------------
#
# A deployment's database starts empty, and nothing else in the stack would
# create it. Migrations belong here for the same reason the keys do: one
# writer, before anything serves, and idempotent — `--allow-no-migration` makes
# a start with nothing to do a success rather than an error.
#
# The trade-off is deliberate and stated: this instance migrates itself on
# every start. That is right for one demo instance whose migrations are
# reviewed and reversible; a fleet would separate the two so that a rollout
# cannot half-migrate behind a half-rolled-out image.
provision_schema() {
    log 'applying migrations'
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
}

provision_keys
provision_schema
provision_assets
log 'done'
