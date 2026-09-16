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
# Which database the migrations actually reach is asked, not assumed. Rewriting
# the URL's path is not enough: DBAL merges the query string over the path
# (`DsnParser::parseDatabaseUrlQuery` runs after `parseDatabaseUrlPath` and
# `array_merge`s), so a legal `DATABASE_URL` carrying `?dbname=app` would have
# sent every migration — including every `down` — to the configured database
# while the cleanup dropped an untouched scratch one (Gate 2 round 1, finding
# 1). So a `dbname` parameter is dropped when the scratch URL is built, and
# before anything is migrated the connection is asked `SELECT
# current_database()`; a name that is not this run's own aborts the run.
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
configured="${base##*/}"
prefix="${base%/*}"

# the query, minus any database selection: DBAL merges it over the path, so a
# retained `dbname` would point every migration back at the configured database
query=''
case "$url" in *\?*)
    for part in $(printf '%s' "${url#*\?}" | tr '&' ' '); do
        case "$part" in dbname=*) continue ;; esac
        query="${query:+$query&}$part"
    done
    [ -n "$query" ] && query="?$query"
    ;;
esac

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

raw="$(mktemp)"

# Runs a query on the scratch database and writes one trimmed row per line to
# $2. The console's status is checked BEFORE the output is formatted: piping it
# straight into `sed` would have reported the pipeline's status, so a query
# that failed read as an empty result and the run carried on to announce
# success (Gate 2 round 1, finding 2).
query_scratch() {
    if ! COLUMNS=4000 DATABASE_URL="$scratch_url" bin/console dbal:run-sql --force-fetch -- "$1" > "$raw" 2>&1; then
        echo "[FAIL] a query against $scratch failed:" >&2
        sed 's/^/       /' "$raw" >&2
        exit 1
    fi
    # one trimmed line per row, header dropped: `dbal:run-sql` prints a table,
    # and COLUMNS keeps it from wrapping a long definition into two lines
    sed -e '/^ *-\{3,\} *$/d' -e 's/^ *//' -e 's/ *$//' -e '/^$/d' -e '/^\[/d' "$raw" | tail -n +2 > "$2"
}

migrate() {
    if ! DATABASE_URL="$scratch_url" bin/console doctrine:migrations:migrate "$1" --no-interaction > "$raw" 2>&1; then
        echo "[FAIL] doctrine:migrations:migrate $1 failed:" >&2
        sed 's/^/       /' "$raw" >&2
        exit 1
    fi
}

echo "[..]   scratch database: $scratch"
bin/console dbal:run-sql -- "CREATE DATABASE \"$scratch\"" >/dev/null || {
    echo "[FAIL] could not create $scratch — the name is taken, and this run owns nothing: nothing was migrated and nothing dropped" >&2
    exit 1
}
created=1

before="$(mktemp)"
after="$(mktemp)"
listing="$(mktemp)"
trap 'rm -f "$before" "$after" "$listing" "$raw"; cleanup' EXIT INT TERM

# which database the migrations will actually reach, asked through the same
# resolution they use, before a single one runs
query_scratch 'SELECT current_database()' "$listing"
effective="$(cat "$listing")"
if [ "$effective" != "$scratch" ]; then
    echo "[FAIL] the connection resolves to \"$effective\", not to the database this run created (\"$scratch\")" >&2
    echo '       nothing was migrated; check DATABASE_URL for a database-selecting parameter' >&2
    exit 1
fi
echo "[OK]   the connection resolves to $scratch"

migrate latest
query_scratch "$(cat scripts/schema-fingerprint.sql)" "$before"
[ -s "$before" ] || { echo '[FAIL] the schema fingerprint after the first migration is empty' >&2; exit 1; }
echo "[OK]   up: $(wc -l < "$before" | tr -d ' ') schema objects"

migrate first
query_scratch "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() ORDER BY 1" "$listing"
unexpected=''
while read -r table; do
    [ -n "$table" ] || continue
    case " $SURVIVORS " in
        *" $table "*) ;;
        *) unexpected="$unexpected $table" ;;
    esac
done < "$listing"
if [ -n "$unexpected" ]; then
    echo "[FAIL] a full down left tables the declared set does not name:$unexpected" >&2
    echo "       declared survivors: $SURVIVORS" >&2
    exit 1
fi
echo "[OK]   down: only the declared survivors remain ($SURVIVORS)"

migrate latest
query_scratch "$(cat scripts/schema-fingerprint.sql)" "$after"
if ! diff -u "$before" "$after"; then
    echo '[FAIL] the schema after down and up again differs from the schema before it (lines above)' >&2
    exit 1
fi
echo "[OK]   the round trip reproduced the schema exactly"
