#!/usr/bin/env sh
# The uncached latency of every analytics report (NFR-PERF-2), measured the way
# `docs/how-to/benchmarks.md` publishes it.
#
# Runs INSIDE the php container, which is why it addresses http://nginx: that
# is the service name on the compose network. The published recipe used to mix
# a host shell with that hostname and left the token and the link undefined, so
# a reader could not run it (change harden-quality-and-docs, Gate 2 round 1,
# finding 2). This script is the recipe.
#
#   docker compose exec php bin/console app:demo:seed --clicks=1000000 --days=60 --reset
#   docker compose exec php sh scripts/report-benchmark.sh \
#       demo@example.com '<demo password>' admin@example.com '<admin password>' [samples]
#
# Both accounts are needed and the seed prints both: the three global reports
# require ROLE_ADMIN and answer 403 for the owner account — which this script
# treats as a failure rather than a measurement.
#
# The report cache is cleared before every single request, so every sample is a
# cache miss: these are the numbers of the miss, not of normal use.
set -eu

usage='usage: report-benchmark.sh <owner-email> <owner-password> <admin-email> <admin-password> [samples]'
email="${1?$usage}"
password="${2?$usage}"
admin_email="${3?$usage}"
admin_password="${4?$usage}"
# 20 by default, not 15: with nearest-rank percentiles over 15 samples the 95th
# IS the maximum (ceil(0.95 * 15) = 15), so a "p95" column would just repeat
# the worst sample. At 20 it is the 19th of 20.
samples="${5:-20}"
base="${BASE:-http://nginx}"

json() { printf '{"email":"%s","password":"%s"}' "$1" "$2"; }

authenticate() {
    curl -sS -X POST -H 'Content-Type: application/json' -H 'Accept: application/json' \
        --data "$(json "$1" "$2")" "$base/api/v1/auth/token" \
        | sed -n 's/.*"token":"\([^"]*\)".*/\1/p'
}

token="$(authenticate "$email" "$password")"
[ -n "$token" ] || { echo "could not authenticate as $email — is the stack seeded?" >&2; exit 1; }
admin_token="$(authenticate "$admin_email" "$admin_password")"
[ -n "$admin_token" ] || { echo "could not authenticate as $admin_email" >&2; exit 1; }

# The link is selected structurally, by PHP, from the collection's `items` and
# by the highest clickCount rather than by trusting the ordering: a `sed`
# extraction over the compact body returned the LAST id in it, because `.*` is
# greedy — so the reports were timed against the account's least-clicked link
# while the document said its most-clicked one (Gate 2 confirmation 1,
# finding 2). The selected link's click count is printed, so a wrong one cannot
# hide in the table again.
selected="$(curl -sS -H 'Accept: application/json' -H "Authorization: Bearer $token" \
    "$base/api/v1/links?order%5BclickCount%5D=desc" \
    | php -r '$body = json_decode(stream_get_contents(STDIN), true);
        $items = array_filter((array) ($body["items"] ?? $body), "is_array");
        if ([] === $items) { exit(0); }
        usort($items, static fn (array $a, array $b): int => ($b["clickCount"] ?? 0) <=> ($a["clickCount"] ?? 0));
        printf("%s %s", $items[0]["id"] ?? "", $items[0]["clickCount"] ?? 0);')"
link="${selected%% *}"
link_clicks="${selected##* }"
[ -n "$link" ] || { echo "the account owns no links — run app:demo:seed first" >&2; exit 1; }

# epoch arithmetic rather than `date -d '-29 days'`: the container's date is
# BusyBox, which understands `-d @<epoch>` and not relative expressions
now="$(date -u +%s)"
from="$(date -u -d "@$((now - 29 * 86400))" +%Y-%m-%dT00:00:00Z)"
to="$(date -u -d "@$((now + 86400))" +%Y-%m-%dT00:00:00Z)"

echo "link=$link ($link_clicks clicks, the account's most-clicked)  period=$from..$to  samples=$samples  clicks in table=$(bin/console dbal:run-sql 'SELECT count(*) FROM clicks' 2>/dev/null | sed -n 's/[^0-9]*\([0-9][0-9]*\).*/\1/p' | head -1)"
printf '%-18s %8s %8s %8s\n' report p50 p95 max

measure() {
    name="$1"; url="$2"; bearer="${3:-$token}"
    times=''
    i=0
    while [ "$i" -lt "$samples" ]; do
        bin/console cache:pool:clear cache.reports >/dev/null 2>&1
        out="$(curl -sS -o /dev/null -w '%{http_code} %{time_total}' -H 'Accept: application/json' -H "Authorization: Bearer $bearer" "$url")"
        code="${out%% *}"; secs="${out##* }"
        [ "$code" = "200" ] || { echo "$name answered $code, not 200" >&2; exit 1; }
        times="$times $secs"
        i=$((i + 1))
    done
    printf '%s|%s\n' "$name" "$times" >> "$tmp"
}

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

for report in summary timeseries countries devices referrers variants; do
    measure "link/$report" "$base/api/v1/links/$link/stats/$report?from=$from&to=$to"
done
for report in summary timeseries top-links; do
    measure "admin/$report" "$base/api/v1/admin/stats/$report?from=$from&to=$to" "$admin_token"
done

# percentiles over the collected samples, nearest-rank
awk -F'|' '{
    n = split($2, t, " ");
    m = 0; for (i = 1; i <= n; i++) if (t[i] != "") v[++m] = t[i] * 1000;
    for (i = 1; i < m; i++) for (j = i + 1; j <= m; j++) if (v[j] < v[i]) { x = v[i]; v[i] = v[j]; v[j] = x }
    p50 = v[int(m * 0.5 + 0.999)]; p95 = v[int(m * 0.95 + 0.999)];
    printf "%-18s %7.1f %7.1f %7.1f\n", $1, p50, p95, v[m];
}' "$tmp"
