#!/usr/bin/env sh
# Every migration down, then up again, on a database of this run's own — and
# the schema after the round trip compared with the schema before it, line by
# line (change harden-gate-floor, design decision 4).
#
# What it proves: every `down()` runs against a schema its `up()` built, and
# the pair is symmetric — a `down` that forgets an index, or an `up` that
# recreates something differently, shows up as a differing line naming the
# object. What it does not prove: that a `down` preserves data (these drop or
# keep whole tables, none rewrites rows), and that the mapping agrees with the
# migrations, which is a different question with its own answer.
#
# Ownership. The scratch database is taken with `CREATE DATABASE`, which is
# atomic and fails on a name that already exists — that failure, not the random
# suffix, is what makes a collision safe: nothing is migrated and nothing is
# dropped on that path. `doctrine:database:create` is deliberately not used
# here, because it reports an existing database as a notice and exits 0, which
# would turn a collision into the silent adoption of somebody else's database.
# The cleanup drops exactly the database whose create succeeded in this
# process; a run killed outright leaks one rather than deleting one it does not
# own.
#
# Runs from the repository root — `bin/console` and
# `scripts/schema-fingerprint.sql` are read relative to the working directory —
# through `make migrations-roundtrip`, which supplies the container or CI's
# native PHP.
set -eu

# the tables a full `down` is allowed to leave standing, and why
SURVIVORS='doctrine_migration_versions messenger_messages'

url="${DATABASE_URL:?DATABASE_URL must be set}"
base="${url%%\?*}"
query=''
case "$url" in *\?*) query="?${url#*\?}" ;; esac
configured="${base##*/}"
prefix="${base%/*}"

suffix="$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')"
scratch="${configured}_roundtrip_${suffix}"
if [ "$scratch" = "$configured" ] || [ -z "$configured" ]; then
    echo "[FAIL] refusing to operate on the configured database ($configured)" >&2
    exit 1
fi
scratch_url="$prefix/$scratch$query"
created=0

cleanup() {
    [ "$created" = 1 ] || return 0
    bin/console dbal:run-sql -- "DROP DATABASE IF EXISTS \"$scratch\"" >/dev/null 2>&1 \
        || echo "[WARN] the scratch database $scratch could not be dropped" >&2
}
trap cleanup EXIT INT TERM

# one trimmed line per row, header dropped: `dbal:run-sql` prints a table, and
# COLUMNS keeps it from wrapping a long index definition into two lines
rows() {
    COLUMNS=4000 sed -e '/^ *-\{3,\} *$/d' -e 's/^ *//' -e 's/ *$//' -e '/^$/d' -e '/^\[/d' | tail -n +2
}

fingerprint() {
    COLUMNS=4000 DATABASE_URL="$scratch_url" bin/console dbal:run-sql --force-fetch -- "$(cat scripts/schema-fingerprint.sql)" | rows
}

tables() {
    COLUMNS=4000 DATABASE_URL="$scratch_url" bin/console dbal:run-sql --force-fetch -- \
        "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() ORDER BY 1" | rows
}

migrate() {
    DATABASE_URL="$scratch_url" bin/console doctrine:migrations:migrate "$1" --no-interaction >/dev/null
}

echo "[..]   scratch database: $scratch"
bin/console dbal:run-sql -- "CREATE DATABASE \"$scratch\"" >/dev/null || {
    echo "[FAIL] could not create $scratch — the name is taken, and this run owns nothing: nothing was migrated and nothing dropped" >&2
    exit 1
}
created=1

before="$(mktemp)"
after="$(mktemp)"
trap 'rm -f "$before" "$after"; cleanup' EXIT INT TERM

migrate latest
fingerprint > "$before"
[ -s "$before" ] || { echo '[FAIL] the schema fingerprint after the first migration is empty' >&2; exit 1; }
echo "[OK]   up: $(wc -l < "$before" | tr -d ' ') schema objects"

migrate first
unexpected=''
for table in $(tables); do
    case " $SURVIVORS " in
        *" $table "*) ;;
        *) unexpected="$unexpected $table" ;;
    esac
done
if [ -n "$unexpected" ]; then
    echo "[FAIL] a full down left tables the declared set does not name:$unexpected" >&2
    echo "       declared survivors: $SURVIVORS" >&2
    exit 1
fi
echo "[OK]   down: only the declared survivors remain ($SURVIVORS)"

migrate latest
fingerprint > "$after"
if ! diff -u "$before" "$after"; then
    echo '[FAIL] the schema after down and up again differs from the schema before it (lines above)' >&2
    exit 1
fi
echo "[OK]   the round trip reproduced the schema exactly"
