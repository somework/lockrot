#!/usr/bin/env bash
# Builds build/lockrot.phar reproducibly: the same checkout, Box version and PHP series give the
# same bytes, so a release PHAR can be rebuilt and compared against the published sha256.
#
# What is pinned and why:
#  - dependencies come from build/phar/composer.lock (`composer install`, never `update`), so a
#    newer composer/composer on Packagist cannot change a rebuild;
#  - the lockrot package itself is a path repository with `reference: none` and a fixed version,
#    so the lock does not carry the commit hash of the checkout and installed.php is the same on
#    a branch and on a tag; the root of the build manifest gets its version from
#    COMPOSER_ROOT_VERSION for the same reason (Composer would otherwise guess it from the
#    branches present in the checkout, which differ between a clone and a CI checkout);
#  - the Composer autoloader suffix is fixed (config.autoloader-suffix), so the autoloader class
#    is not named after a random hash;
#  - the PHAR alias is fixed in box.json (Box would otherwise use the absolute build path);
#  - every file timestamp inside the archive is the source date: SOURCE_DATE_EPOCH when set
#    (https://reproducible-builds.org/specs/source-date-epoch/), else the commit date of HEAD;
#  - Box compiles the files in sorted order and is itself pinned by version and sha256;
#  - only an allowlist of the lockrot package goes in (src, resources, bin/lockrot,
#    composer.json), so an untracked file in the checkout cannot end up in the archive.
# Not pinned, printed at the end instead: the PHP and Composer that ran the build. Composer writes
# vendor/composer/*.php from its own source, so a rebuild needs the same Composer minor; PHP
# only runs Box and has not changed the bytes between 8.4 and 8.5.
set -euo pipefail
export COMPOSER_ROOT_VERSION="${COMPOSER_ROOT_VERSION:-dev-main}"
cd "$(dirname "$0")/phar"

BOX_VERSION="4.7.0"
BOX_SHA256="3d390eeaec33288098fe83f8a54c60cc575cb6be295f38ff4482b4b4f26f8d52"

if [ -n "${SOURCE_DATE_EPOCH:-}" ]; then
  TIMESTAMP="$(php -r 'echo gmdate("Y-m-d\TH:i:s\Z", (int) $argv[1]);' "$SOURCE_DATE_EPOCH")"
else
  TIMESTAMP="$(TZ=UTC git -C ../.. log -1 --format=%cd --date=format-local:%Y-%m-%dT%H:%M:%SZ)"
fi

rm -rf vendor
composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader
if [ ! -f box.phar ]; then
  curl -fsSL -o box.phar "https://github.com/box-project/box/releases/download/${BOX_VERSION}/box.phar"
  echo "${BOX_SHA256}  box.phar" | shasum -a 256 -c -
fi
# box.json plus the timestamp of this build; Box reads the timestamp from its configuration only.
php -r '$c = json_decode(file_get_contents("box.json"), true); $c["timestamp"] = $argv[1]; file_put_contents("box.build.json", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");' "$TIMESTAMP"
php box.phar compile --no-parallel --sort-compiled-files --config box.build.json
php ../lockrot.phar --version
# --offline keeps tagged releases from depending on Packagist/GitHub being reachable: with an empty
# cache every verdict is "unknown", which is still a valid report and still exits 0.
cd ../.. && php build/lockrot.phar -d tests/fixtures/skeletons/laravel --format=json --target-php=8.4 --offline >/dev/null && echo "phar smoke test ok"
echo "source date ${TIMESTAMP}; built with $(php -r 'echo "PHP ".PHP_VERSION;'), $(composer -V 2>/dev/null | cut -d' ' -f1-3), Box ${BOX_VERSION}"
ls -lh build/lockrot.phar
shasum -a 256 build/lockrot.phar
