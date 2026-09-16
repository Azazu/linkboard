#!/bin/sh
# Fixture suite for scripts/migrations-roundtrip.sh: a throwaway repository, a
# stubbed `bin/console`, one demonstrated failing input per rule, and no
# database anywhere (change harden-gate-floor, design decision 6).
#
# What this suite is NOT allowed to be asked: whether the fingerprint SQL sees
# an index. A stub returns whatever it is told to, so it proves the script's
# control flow — ordering, exit codes, ownership, cleanup — and nothing about
# the query. That question is answered against real PostgreSQL by
# tests/Integration/Db/SchemaFingerprintTest.php.
#
# Usage: scripts/migrations_roundtrip_test.sh ; exit non-zero on any failing case.
set -u
HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
SCRIPT="$HERE/migrations-roundtrip.sh"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
PASS=0; FAILED=0

ok()   { PASS=$((PASS+1)); }
bad()  { FAILED=$((FAILED+1)); printf 'FAIL: %s\n' "$1"; [ -f "$TMP/out" ] && sed 's/^/    /' "$TMP/out" | tail -6; }
texit()  { exp=$1; shift; ( cd "$REPO" && "$@" ) >"$TMP/out" 2>&1; rc=$?; [ "$rc" = "$exp" ] && ok || bad "exit $rc, expected $exp: $*"; }
tgrep()  { if grep -q -- "$1" "$TMP/out"; then ok; else bad "no match '$1' in the output"; fi; }
tngrep() { if grep -q -- "$1" "$TMP/out"; then bad "unexpected match '$1' in the output"; else ok; fi; }
tlog()   { if grep -q -- "$1" "$REPO/log"; then ok; else bad "the console was never asked to: $1"; fi; }
tnolog() { if grep -q -- "$1" "$REPO/log"; then bad "the console was asked to: $1"; else ok; fi; }

# --- the throwaway repository -----------------------------------------
new_repo() {
    REPO="$TMP/repo.$1"
    mkdir -p "$REPO/bin" "$REPO/scripts"
    : > "$REPO/log"
    printf 'SELECT 1\n' > "$REPO/scripts/schema-fingerprint.sql"
    printf 'ok\n' > "$REPO/create-mode"
    printf '0\n' > "$REPO/fingerprint-calls"
    printf 'doctrine_migration_versions messenger_messages\n' > "$REPO/survivors"
    printf 'ok\n' > "$REPO/down-mode"
    printf 'stable\n' > "$REPO/fingerprint-mode"
    : > "$REPO/query-fail"
    : > "$REPO/current-database"
    cat > "$REPO/bin/console" <<'STUB'
#!/bin/sh
# console stub: records every invocation with the DATABASE_URL it was given,
# and answers from the mode files.
#
# `current_database()` is answered the way DBAL resolves it — the query
# string's `dbname` wins over the URL's path (DsnParser merges the query after
# the path) — so the script's own check of which database it reached is
# exercised for real rather than mocked away.
set -u
printf '%s | DATABASE_URL=%s\n' "$*" "${DATABASE_URL:-}" >> "$PWD/log"

stage=fingerprint
case "$*" in
    *"CREATE DATABASE"*) stage=create ;;
    *"DROP DATABASE"*) stage=drop ;;
    *doctrine:migrations:migrate*) stage=migrate ;;
    *"current_database()"*) stage=current_database ;;
    *information_schema.tables*) stage=tables ;;
esac
if [ "$stage" = fingerprint ]; then
    n=$(( $(cat "$PWD/fingerprint-calls") + 1 )); printf '%s\n' "$n" > "$PWD/fingerprint-calls"
    stage="fingerprint$n"
fi
if [ "$stage" = "$(cat "$PWD/query-fail" 2>/dev/null)" ]; then
    echo 'SQLSTATE[08006]: the query failed' >&2
    exit 7
fi

case "$stage" in
    create)
        if [ "$(cat "$PWD/create-mode")" = taken ]; then
            echo 'SQLSTATE[42P04]: database "x" already exists' >&2
            exit 7
        fi
        exit 0 ;;
    drop) exit 0 ;;
    migrate)
        [ -f "$PWD/hold" ] && while [ -f "$PWD/hold" ]; do sleep 0.05; done
        case "$*" in
            *" first"*) [ "$(cat "$PWD/down-mode")" = fail ] && { echo 'the down failed' >&2; exit 1; } ;;
        esac
        exit 0 ;;
    current_database)
        forced=$(cat "$PWD/current-database" 2>/dev/null || true)
        if [ -n "$forced" ]; then
            name=$forced
        else
            url=${DATABASE_URL:-}
            name=${url%%\?*}; name=${name##*/}
            case "$url" in *\?*)
                for part in $(printf '%s' "${url#*\?}" | tr '&' ' '); do
                    case "$part" in dbname=*) name=${part#dbname=} ;; esac
                done ;;
            esac
        fi
        echo ' current_database'
        echo "  $name"
        exit 0 ;;
    tables)
        echo ' table_name'
        for t in $(cat "$PWD/survivors"); do echo "  $t"; done
        exit 0 ;;
    *)
        echo ' line'
        echo '  column widgets.id uuid NOT NULL -'
        if [ "$(cat "$PWD/fingerprint-mode")" = drifts ] && [ "$stage" != fingerprint1 ]; then
            echo '  index idx_widgets_label ON widgets (label)'
        fi
        exit 0 ;;
esac
STUB
    chmod +x "$REPO/bin/console"
}

run() { ( cd "$REPO" && DATABASE_URL="$1" sh "$SCRIPT" ) >"$TMP/out" 2>&1; }
URL='postgresql://u:p@h:5432/fixture?serverVersion=16'

# --- the happy path ---------------------------------------------------
new_repo happy
run "$URL"; [ $? = 0 ] && ok || bad 'the happy path failed'
tgrep 'the round trip reproduced the schema exactly'
tlog 'CREATE DATABASE "fixture_roundtrip_'
tlog 'DROP DATABASE IF EXISTS "fixture_roundtrip_'

# --- a second listing that differs ------------------------------------
new_repo drift
printf 'drifts\n' > "$REPO/fingerprint-mode"
run "$URL"; [ $? != 0 ] && ok || bad 'a differing listing passed'
tgrep 'differs from the schema before it'
tgrep 'idx_widgets_label'
tlog 'DROP DATABASE IF EXISTS'

# --- a table the declared set does not name ---------------------------
new_repo survivor
printf 'doctrine_migration_versions messenger_messages leftovers\n' > "$REPO/survivors"
run "$URL"; [ $? != 0 ] && ok || bad 'an unexpected surviving table passed'
tgrep 'a full down left tables the declared set does not name'
tgrep 'leftovers'

# --- a down that fails ------------------------------------------------
new_repo downfail
printf 'fail\n' > "$REPO/down-mode"
run "$URL"; [ $? != 0 ] && ok || bad 'a failing down passed'
tlog 'DROP DATABASE IF EXISTS'

# --- a create collision, on an empty and on a non-empty database ------
for case in empty populated; do
    new_repo "collision.$case"
    printf 'taken\n' > "$REPO/create-mode"
    [ "$case" = populated ] && printf 'doctrine_migration_versions messenger_messages widgets\n' > "$REPO/survivors"
    run "$URL"; [ $? != 0 ] && ok || bad "a create collision ($case) passed"
    tgrep 'the name is taken, and this run owns nothing'
    tnolog 'doctrine:migrations:migrate'
    tnolog 'DROP DATABASE'
done

# --- two runs that overlap in time ------------------------------------
new_repo overlap
touch "$REPO/hold"
( cd "$REPO" && DATABASE_URL="$URL" sh "$SCRIPT" >"$TMP/first.out" 2>&1; echo $? > "$TMP/first.rc" ) &
first=$!
# the first run is now parked inside its migration step, holding its database
tries=0
while ! grep -q 'doctrine:migrations:migrate' "$REPO/log" 2>/dev/null && [ $tries -lt 100 ]; do sleep 0.05; tries=$((tries+1)); done
held=$(sed -n 's/.*CREATE DATABASE "\([^"]*\)".*/\1/p' "$REPO/log" | head -1)
REPO2="$TMP/repo.overlap2"; cp -r "$REPO" "$REPO2"; rm -f "$REPO2/hold"; : > "$REPO2/log"; printf '0\n' > "$REPO2/fingerprint-calls"
( cd "$REPO2" && DATABASE_URL="$URL" sh "$SCRIPT" ) >"$TMP/out" 2>&1
[ $? = 0 ] && ok || bad 'the second run failed while the first was live'
second=$(sed -n 's/.*CREATE DATABASE "\([^"]*\)".*/\1/p' "$REPO2/log" | head -1)
[ -n "$held" ] && [ -n "$second" ] && [ "$held" != "$second" ] && ok || bad "the two runs took the same database ($held / $second)"
if grep -q "DROP DATABASE IF EXISTS \"$held\"" "$REPO2/log"; then bad "the second run dropped the first run's database"; else ok; fi
rm -f "$REPO/hold"; wait $first
[ "$(cat "$TMP/first.rc")" = 0 ] && ok || bad 'the released first run failed'
if grep -q "DROP DATABASE IF EXISTS \"$held\"" "$REPO/log"; then ok; else bad 'the first run did not drop its own database'; fi
if grep -q "DROP DATABASE IF EXISTS \"$second\"" "$REPO/log"; then bad "the first run dropped the second run's database"; else ok; fi

# --- the guard against the configured database ------------------------
new_repo guard
run 'postgresql://u:p@h:5432/?serverVersion=16'
[ $? != 0 ] && ok || bad 'an empty database name passed'
tgrep 'refusing to operate on the configured database'
tnolog 'CREATE DATABASE'

# --- a DATABASE_URL whose query selects the database (Gate 2 round 1, #1) ----
# DBAL merges the query over the path, so a retained `dbname` would have sent
# every migration — every `down` included — to the configured database
new_repo dbname
run 'postgresql://u:p@h:5432/fixture?dbname=fixture&serverVersion=16'
[ $? = 0 ] && ok || bad 'a dbname query parameter broke the run'
tgrep 'the round trip reproduced the schema exactly'
if grep 'doctrine:migrations:migrate' "$REPO/log" | grep -q 'dbname='; then
    bad 'a migration was sent to the database the query string selected'
else ok; fi
if grep 'doctrine:migrations:migrate' "$REPO/log" | grep -q 'fixture_roundtrip_'; then ok; else bad 'the migrations did not reach the scratch database'; fi

# --- the connection resolving to something else entirely --------------------
new_repo resolution
printf 'fixture\n' > "$REPO/current-database"
run "$URL"; [ $? != 0 ] && ok || bad 'a connection resolving to the configured database passed'
tgrep 'not to the database this run created'
tnolog 'doctrine:migrations:migrate'
tlog 'DROP DATABASE IF EXISTS'

# --- a listing query that fails, at each stage (Gate 2 round 1, #2) ---------
# piping the console straight into `sed` reported the pipeline's status, so a
# failed query read as an empty result and the run announced success
for stage in current_database fingerprint1 tables fingerprint2; do
    new_repo "queryfail.$stage"
    printf '%s\n' "$stage" > "$REPO/query-fail"
    run "$URL"; [ $? != 0 ] && ok || bad "a failing $stage query passed"
    tgrep 'SQLSTATE'
    tngrep 'the round trip reproduced the schema exactly'
    tlog 'DROP DATABASE IF EXISTS'
done

# --- a migration that fails is not swallowed either -------------------------
new_repo migratefail
printf 'fail\n' > "$REPO/down-mode"
run "$URL"; [ $? != 0 ] && ok || bad 'a failing migration passed'
tgrep 'doctrine:migrations:migrate first failed'

printf '%s passed, %s failed\n' "$PASS" "$FAILED"
[ "$FAILED" = 0 ] || exit 1
