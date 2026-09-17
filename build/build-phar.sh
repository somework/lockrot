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
# Composer writes vendor/composer/*.php from its own source, so the Composer that runs this script
# is part of the recipe: the release workflow pins it (`tools: composer:<version>` in
# .github/workflows/phar.yml, at the tag), and the script prints the version it ran with. PHP only
# runs Box; CI builds on two PHP versions and compares, so the bytes are known not to depend on it.
# The lockrot composer.json inside the archive is not read at runtime; it stays in for parity with
# the archives before the allowlist.
set -euo pipefail
# Unconditional: the version of the build manifest's root is not a knob. A caller's exported value
# (common when working with path repositories) would silently change installed.php and the hash.
export COMPOSER_ROOT_VERSION=dev-main
cd "$(dirname "$0")/phar"

# sha256sum is coreutils; shasum is a Perl script that minimal images (the official php ones among
# them) do not carry.
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
# --no-plugins --no-scripts: nothing installed globally on the building machine runs inside the
# build. The autoloader is dumped here, authoritative classmap as Box would, and box.json has
# dump-autoload off, so Box does not call the host Composer a second time without these flags.
composer install --no-interaction --no-dev --prefer-dist --classmap-authoritative --no-plugins --no-scripts
# A cached box.phar is checked on every run, not only right after the download: a stale one from
# before a BOX_VERSION bump, or a replaced one, is removed and fetched again.
if [ -f box.phar ] && ! echo "${BOX_SHA256}  box.phar" | sha256 -c --status -; then
  rm -f box.phar
fi
if [ ! -f box.phar ]; then
  curl -fsSL -o box.phar "https://github.com/box-project/box/releases/download/${BOX_VERSION}/box.phar"
fi
echo "${BOX_SHA256}  box.phar" | sha256 -c -
# box.json plus the timestamp of this build; Box reads the timestamp from its configuration only.
php -r '$c = json_decode(file_get_contents("box.json"), true, 512, JSON_THROW_ON_ERROR); $c["timestamp"] = $argv[1]; file_put_contents("box.build.json", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");' "$TIMESTAMP"
php box.phar compile --no-parallel --sort-compiled-files --config box.build.json
php ../lockrot.phar --version
# --offline keeps tagged releases from depending on Packagist/GitHub being reachable: with an empty
# cache every verdict is "unknown", which is still a valid report and still exits 0.
cd ../.. && php build/lockrot.phar -d tests/fixtures/skeletons/laravel --format=json --target-php=8.4 --offline >/dev/null && echo "phar smoke test ok"
echo "source date ${TIMESTAMP} (${TIMESTAMP_SOURCE}); built with $(php -r 'echo "PHP ".PHP_VERSION;'), $(composer -V 2>/dev/null | cut -d' ' -f1-3), Box ${BOX_VERSION}"
ls -lh build/lockrot.phar
sha256 build/lockrot.phar
