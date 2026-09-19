#!/bin/sh
# Entrypoint of the long-running production containers (web, worker,
# scheduler). It warms only this container's OWN cache: the shared artefacts —
# the signing keypair and the public assets — belong to the one-shot init
# service, which has already finished by the time this runs (design decision
# 3). Doing them here as well is what raced on a clean host.
#
# The cache is private to the container, so warming it here is safe and is the
# reason nothing needs warming at build time (design decision 2): by now the
# required settings exist, and the kernel boot that warms the cache is also the
# boot that checks them — a container with a missing or committed-default
# setting fails here rather than serving.
set -eu

php bin/console cache:warmup --no-interaction

exec "$@"
