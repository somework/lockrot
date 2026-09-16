#!/usr/bin/env bash
# Records the README demo from a real run and renders it: asciinema captures the terminal session,
# agg turns the recording into docs/assets/lockrot-demo.gif. No browser involved.
#
#   LOCKROT_DEMO_DIR=<scratch copy of tests/fixtures/apps/matomo-org_matomo, holding lockrot.phar> \
#   GITHUB_TOKEN=<token> COMPOSER_CACHE_DIR=<scratch dir> docs/assets/lockrot-demo.sh
#
# Warm Composer's metadata cache with one plain run first, then delete <cache>/lockrot so the
# activity round is live and the footer is the plain one. The typing is scripted (the command is
# printed one character at a time behind a bare prompt); the run itself is real.
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
cast=$(mktemp -t lockrot-demo).cast
: "${LOCKROT_DEMO_DIR:?export LOCKROT_DEMO_DIR}"

session() {
    printf '$ '
    sleep 0.6
    for ((i = 0; i < ${#1}; i++)); do
        printf '%s' "${1:i:1}"
        sleep 0.045
    done
    sleep 0.7
    printf '\n'
    (cd "$LOCKROT_DEMO_DIR" && COLUMNS=100 php lockrot.phar --ansi --target-php=8.4) || true
    sleep 6
}
export -f session

asciinema rec --overwrite --quiet --window-size 100x33 --command 'session "php lockrot.phar --target-php=8.4"' "$cast"
agg --font-size 20 --line-height 1.3 --theme monokai --speed 1 --last-frame-duration 5 --fps-cap 20 "$cast" "$here/lockrot-demo.gif"
rm -f "$cast"
ls -la "$here/lockrot-demo.gif"
