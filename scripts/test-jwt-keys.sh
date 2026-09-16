#!/usr/bin/env sh
# The test JWT keypair, generated with the passphrase the test environment
# actually declares — and replaced when the one on disk is not that
# (change harden-quality-and-docs, design decision 6a).
#
# Why this exists. `.env.test` declares JWT_PASSPHRASE, but
# `docker-compose.yml` passes `.env` into the php container's process
# environment and Symfony's Dotenv never overrides a real variable with a
# file's — so `lexik:jwt:generate-keypair --env=test` run inside the container
# takes `.env`'s empty value. Measured: the key it writes is *encrypted with an
# empty passphrase*, opens with `pass:` and refuses the declared one, so a
# fresh `make init` leaves a keypair the suite cannot authenticate with. CI is
# unaffected — it runs the same target natively, where `.env.test` applies.
#
# The precedence is the one `tests/bootstrap.php` uses, so the generator and
# the suite can never disagree about which value is in force: `.env.test`,
# then `.env.test.local` if it exists.
#
# Detection, when a non-empty passphrase is in force:
#   the private key must REFUSE an empty passphrase (so it is encrypted at all)
#   and ACCEPT the effective one, and the stored public key must be the one
#   derived from it. Anything else is regenerated. "Accepts the effective
#   passphrase" alone is not enough: an unencrypted key accepts every
#   passphrase, so that test would keep exactly the key it means to replace.
#
# Run through `make jwt-keys`; it is not meant to be called directly.
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
dir="$root/config/jwt/test"
private="$dir/private.pem"
public="$dir/public.pem"

passphrase=''
for file in "$root/.env.test" "$root/.env.test.local"; do
    [ -f "$file" ] || continue
    value="$(sed -n 's/^[[:space:]]*JWT_PASSPHRASE=//p' "$file" | tail -n 1)"
    [ -n "$value" ] || continue
    # strip one layer of matching quotes, as dotenv does
    value="$(printf '%s' "$value" | sed "s/^'\(.*\)'$/\1/; s/^\"\(.*\)\"$/\1/")"
    passphrase="$value"
done

usable() {
    [ -f "$private" ] && [ -f "$public" ] || return 1
    if [ -n "$passphrase" ]; then
        # encrypted at all: an empty passphrase must not open it
        openssl pkey -in "$private" -passin pass: -noout >/dev/null 2>&1 && return 1
    fi
    openssl pkey -in "$private" -passin "pass:$passphrase" -noout >/dev/null 2>&1 || return 1
    derived="$(openssl pkey -in "$private" -passin "pass:$passphrase" -pubout 2>/dev/null)" || return 1
    [ "$derived" = "$(cat "$public")" ] || return 1
    return 0
}

if usable; then
    echo "[OK]   config/jwt/test: the keypair matches the passphrase the test environment declares"
    exit 0
fi

[ -e "$private" ] && echo "[..]   config/jwt/test: the keypair does not match the declared passphrase — regenerating"
mkdir -p "$dir"
rm -f "$private" "$public"
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 \
    -aes256 -pass "pass:$passphrase" -out "$private" 2>/dev/null
openssl pkey -in "$private" -passin "pass:$passphrase" -pubout -out "$public" 2>/dev/null
chmod 644 "$private" "$public"

usable || { echo "[FAIL] config/jwt/test: the regenerated keypair is still not usable" >&2; exit 1; }
echo "[OK]   config/jwt/test: keypair generated with the declared passphrase"
