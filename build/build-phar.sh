#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/phar"

BOX_VERSION="4.7.0"
BOX_SHA256="3d390eeaec33288098fe83f8a54c60cc575cb6be295f38ff4482b4b4f26f8d52"

rm -rf vendor
composer update --no-interaction --no-dev --prefer-dist --optimize-autoloader
if [ ! -f box.phar ]; then
  curl -fsSL -o box.phar "https://github.com/box-project/box/releases/download/${BOX_VERSION}/box.phar"
  echo "${BOX_SHA256}  box.phar" | shasum -a 256 -c -
fi
php box.phar compile --no-parallel
php ../lockrot.phar --version
# --offline keeps tagged releases from depending on Packagist/GitHub being reachable: with an empty
# cache every verdict is "unknown", which is still a valid report and still exits 0.
cd ../.. && php build/lockrot.phar -d tests/fixtures/skeletons/laravel --format=json --target-php=8.4 --offline >/dev/null && echo "phar smoke test ok"
ls -lh build/lockrot.phar
