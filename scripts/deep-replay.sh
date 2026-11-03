#!/usr/bin/env bash
# Replay the deep evidence pack for the security fix batch (da53f6d -> ffb0aec).
#
# Requires: docker, a PHP 8.2 CLI image with pdo_sqlite and pdo_mysql, a MySQL
# 8 server reachable on P1_DB_HOST:P1_DB_PORT, and a Laravel 8 / PHPUnit 9
# vendor directory. All of them are passed by environment variable.
#
# Exit codes: 0 = every checked invariant held; 1 = at least one invariant broke;
# 2 = the environment was not usable, nothing was executed.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_IMAGE="${DEEP_PHP_IMAGE:-ghcr.io/ipevel/cboard:latest}"
VENDOR_DIR="${DEEP_VENDOR_DIR:-${P1_VENDOR_DIR:-}}"
PRE_TREE="${DEEP_PRE_TREE:-}"
DB_DRIVER="${P1_DB_DRIVER:-sqlite}"
DB_HOST="${P1_DB_HOST:-127.0.0.1}"
DB_PORT="${P1_DB_PORT:-33079}"
DB_NAME="${P1_DB_DATABASE:-v2board_p1_deep}"
DB_USER="${P1_DB_USER:-root}"
DB_PASSWORD="${P1_DB_PASSWORD:-root}"
OUT_DIR="${DEEP_OUT_DIR:-$ROOT/tests/evidence/replay}"

fail() { echo "ENV: $*" >&2; exit 2; }
[ -n "$VENDOR_DIR" ] || fail "set DEEP_VENDOR_DIR to a Laravel 8 / PHPUnit 9 vendor directory"
[ -f "$VENDOR_DIR/autoload.php" ] || fail "no autoload.php under DEEP_VENDOR_DIR=$VENDOR_DIR"
[ -d "$PRE_TREE" ] || fail "set DEEP_PRE_TREE to a checkout of the pre-fix commit (da53f6d)"
command -v docker >/dev/null || fail "docker is required"

# The PHP image must carry the driver the chosen database needs. Without this
# check a pdo_mysql-less image (e.g. plain php:8.2-cli) yields 70 "could not
# find driver" errors that look like code failures but are purely environmental.
need_ext="pdo_sqlite"
[ "$DB_DRIVER" = "mysql" ] && need_ext="pdo_mysql"
docker run --rm --entrypoint php "$PHP_IMAGE" -m 2>/dev/null | grep -qi "^${need_ext}$" \
    || fail "PHP image $PHP_IMAGE lacks ${need_ext} (required for P1_DB_DRIVER=$DB_DRIVER)"

mkdir -p "$OUT_DIR"

run_php() { # run_php <source-root> <php-file> [extra env...]
    local source_root="$1"; shift
    local file="$1"; shift
    docker run --rm --network host \
        -v "$ROOT:/app" -v "$PRE_TREE:/pre:ro" -v "$VENDOR_DIR:/vendor:ro" \
        -w /app \
        -e P1_VENDOR_AUTOLOAD=/vendor/autoload.php \
        -e P1_SOURCE_ROOT="$source_root" \
        -e P1_DB_DRIVER="$DB_DRIVER" \
        -e P1_DB_HOST="$DB_HOST" -e P1_DB_PORT="$DB_PORT" \
        -e P1_DB_DATABASE="$DB_NAME" -e P1_DB_USER="$DB_USER" -e P1_DB_PASSWORD="$DB_PASSWORD" \
        --entrypoint php "$PHP_IMAGE" "$file"
}

run_phpunit() { # run_phpunit <config> <source-root>
    docker run --rm --network host \
        -v "$ROOT:/app" -v "$PRE_TREE:/pre:ro" -v "$VENDOR_DIR:/vendor:ro" \
        -w /app \
        -e P1_VENDOR_AUTOLOAD=/vendor/autoload.php \
        -e P1_SOURCE_ROOT="$2" \
        -e P1_DB_DRIVER="$DB_DRIVER" \
        -e P1_DB_HOST="$DB_HOST" -e P1_DB_PORT="$DB_PORT" \
        -e P1_DB_DATABASE="$DB_NAME" -e P1_DB_USER="$DB_USER" -e P1_DB_PASSWORD="$DB_PASSWORD" \
        --entrypoint php "$PHP_IMAGE" /vendor/bin/phpunit -c "$1"
}

status=0

echo "== step 1: harness suite on the post tree =="
run_phpunit tests/p1.xml /app | tee "$OUT_DIR/step1-phpunit.txt" || status=1

echo "== step 2: negative proofs, pre vs post =="
for script in 01-login-limit.php 02-payment-callback.php 03-node-private-key.php 04-settlement-freeze.php; do
    echo "-- $script (pre)"
    run_php /pre "/app/tests/evidence/repro/$script" | tee "$OUT_DIR/pre-$script.json" || status=1
    echo "-- $script (post)"
    run_php /app "/app/tests/evidence/repro/$script" | tee "$OUT_DIR/post-$script.json" || status=1
done

echo "== verdict =="
python3 "$ROOT/scripts/deep-replay-verdict.py" "$OUT_DIR" || status=1

exit "$status"
