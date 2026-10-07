#!/usr/bin/env bash
# Builds build/lockrot.phar reproducibly: the same checkout, Box version and PHP series give the
# same bytes, so you can rebuild a release PHAR and compare it with the published sha256.
#
# What is pinned and why:
#  - Dependencies come from build/phar/composer.lock (`composer install`, never `update`), so a
#    newer composer/composer on Packagist cannot change a rebuild.
#  - The lockrot package itself is a path repository with `reference: none` and a fixed version.
#    So the lock does not carry the commit hash of the checkout, and installed.php is the same on
#    a branch and on a tag. The root of the build manifest gets its version from
#    COMPOSER_ROOT_VERSION for the same reason (else Composer guesses it from the
#    branches in the checkout, which differ between a clone and a CI checkout).
#  - The Composer autoloader suffix is fixed (config.autoloader-suffix), so the autoloader class
#    does not carry a random hash in its name.
#  - The PHAR alias is fixed in box.json (else Box uses the absolute build path).
#  - Every file timestamp inside the archive is the source date: SOURCE_DATE_EPOCH when set
#    (https://reproducible-builds.org/specs/source-date-epoch/), else the commit date of HEAD.
#  - Box compiles the files in sorted order and is itself pinned by version and sha256.
#  - Only an allowlist of the lockrot package goes in (src, resources, bin/lockrot,
#    composer.json), so an untracked file in the checkout cannot end up in the archive.
# Composer writes vendor/composer/*.php from its own source, so the Composer version is part of the
# recipe. The release workflow pins it with `tools: composer:<version>` in .github/workflows/phar.yml,
# and the script prints the version that it ran with. PHP only runs Box: the phar-reproducible job
# in .github/workflows/ci.yml rebuilds on another PHP and compares the bytes.
set -euo pipefail
# Set unconditionally: a value that the caller exported (common with path repositories) changes
# installed.php and the hash.
export COMPOSER_ROOT_VERSION=dev-main
cd "$(dirname "$0")/phar"

# sha256sum is in coreutils. shasum is a Perl script, which minimal images (among them the official
# php ones) do not carry.
sha256() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$@"; else shasum -a 256 "$@"; fi; }

BOX_VERSION="4.7.0"
BOX_SHA256="3d390eeaec33288098fe83f8a54c60cc575cb6be295f38ff4482b4b4f26f8d52"

if [ -n "${SOURCE_DATE_EPOCH:-}" ]; then
  TIMESTAMP="$(php -r 'echo gmdate("Y-m-d\TH:i:s\Z", (int) $argv[1]);' "$SOURCE_DATE_EPOCH")"
  TIMESTAMP_SOURCE="SOURCE_DATE_EPOCH"
elif TIMESTAMP="$(TZ=UTC git -C ../.. log -1 --format=%cd --date=format-local:%Y-%m-%dT%H:%M:%SZ 2>/dev/null)" && [ -n "$TIMESTAMP" ]; then
  TIMESTAMP_SOURCE="the commit date of HEAD"
else
  echo "cannot read the commit date of HEAD (no git, or not a git checkout); set SOURCE_DATE_EPOCH to the release commit's date instead" >&2
  exit 2
fi

rm -rf vendor
# With --no-plugins --no-scripts nothing installed globally on the build machine runs inside the
# build. This command dumps the autoloader with an authoritative classmap, as Box does, and box.json
# sets dump-autoload off, so Box does not call the host Composer a second time without these flags.
composer install --no-interaction --no-dev --prefer-dist --classmap-authoritative --no-plugins --no-scripts
# The script checks a cached box.phar on every run, not only after the download. It removes a stale
# one (from an older BOX_VERSION) or a replaced one and fetches it again.
if [ -f box.phar ] && ! echo "${BOX_SHA256}  box.phar" | sha256 -c --status -; then
  rm -f box.phar
fi
if [ ! -f box.phar ]; then
  curl -fsSL -o box.phar "https://github.com/box-project/box/releases/download/${BOX_VERSION}/box.phar"
fi
echo "${BOX_SHA256}  box.phar" | sha256 -c -
# box.json plus the timestamp of this build. Box reads the timestamp from its configuration only.
php -r '$c = json_decode(file_get_contents("box.json"), true, 512, JSON_THROW_ON_ERROR); $c["timestamp"] = $argv[1]; file_put_contents("box.build.json", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");' "$TIMESTAMP"
php box.phar compile --no-parallel --sort-compiled-files --config box.build.json
php ../lockrot.phar --version
# `--offline` keeps a tagged release independent of Packagist and GitHub. With an empty cache every
# verdict is "unknown", which is a valid report and exits 0.
cd ../.. && php build/lockrot.phar -d tests/fixtures/skeletons/laravel --format=json --target-php=8.4 --offline >/dev/null && echo "phar smoke test ok"
echo "source date ${TIMESTAMP} (${TIMESTAMP_SOURCE}); built with $(php -r 'echo "PHP ".PHP_VERSION;'), $(composer -V 2>/dev/null | cut -d' ' -f1-3), Box ${BOX_VERSION}"
ls -lh build/lockrot.phar
sha256 build/lockrot.phar
