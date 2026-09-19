#!/bin/sh
# The scheduler service of the production stack: the periodic commands the
# service already has, given a runner. Cadences arrive as seconds from the
# compose file, which is where they are named.
#
# A plain loop rather than cron: one process, its output on stdout where the
# container log is, and no second configuration language. Each command is
# allowed to fail without ending the loop — `app:demo:seed` in particular
# exits non-zero when another run holds its lock, which is a normal outcome
# rather than an error.
set -eu

DEMO_RELOAD_SECONDS="${DEMO_RELOAD_SECONDS:-3600}"
PARTITIONS_SECONDS="${PARTITIONS_SECONDS:-86400}"

log() { printf 'scheduler: %s\n' "$1"; }

demo_due=0
partitions_due=0
tick=30

log "demo reload every ${DEMO_RELOAD_SECONDS}s, partition maintenance every ${PARTITIONS_SECONDS}s"

while true; do
    if [ "$partitions_due" -le 0 ]; then
        log 'running partition maintenance'
        php bin/console app:click:partitions --no-interaction || log 'partition maintenance failed; it runs again next cycle'
        partitions_due="$PARTITIONS_SECONDS"
    fi

    if [ "$demo_due" -le 0 ]; then
        if [ "${DEMO_INSTANCE:-false}" = "true" ]; then
            log 'reloading the demo dataset'
            php bin/console app:demo:seed --reset --no-interaction || log 'the demo reload did not run (another run holds the lock, or it refused)'
        fi
        demo_due="$DEMO_RELOAD_SECONDS"
    fi

    sleep "$tick"
    demo_due=$((demo_due - tick))
    partitions_due=$((partitions_due - tick))
done
