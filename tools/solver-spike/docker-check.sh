#!/bin/sh
# Spike: inline constraints and the MoveVerifier code path on one Composer image.
# Usage: tools/solver-spike/docker-check.sh <composer image tag> <project dir> <report.json> <out dir>
# One container at a time. The project dir is copied, so the container writes nothing back to it.
set -eu
tag=$1 project=$2 report=$3 out=$4
repo=$(cd "$(dirname "$0")/../.." && pwd)
mkdir -p "$out"
docker run --rm \
  -v "$repo":/w:ro -v "$project":/src:ro -v "$report":/report.json:ro -v "$out":/out \
  -v lockrot-spike-composer-cache:/tmp/cache -e COMPOSER_CACHE_DIR=/tmp/cache \
  --entrypoint sh "composer:$tag" -c '
    set -u
    composer --version 2>/dev/null | head -1 > /out/version.txt
    for cmd in "update symfony/yaml" "update symfony/yaml:^5.4.54" "update phpseclib/phpseclib:^3.0.57" "update symfony/monolog-bridge:^5.4.52 -W"; do
      rm -rf /p && mkdir /p && cp /src/composer.json /src/composer.lock /p/
      slug=$(echo "$cmd" | tr " /:^" "____")
      (cd /p && composer $cmd --dry-run --no-plugins --no-scripts --no-interaction --no-ansi "--ignore-platform-req=ext-*" > "/out/$slug.txt" 2>&1; echo "exit $?" >> "/out/$slug.txt")
    done
    rm -rf /p && mkdir /p && cp /src/composer.json /src/composer.lock /p/
    echo "<?php Phar::loadPhar(\"/usr/bin/composer\", \"composer.phar\"); return require \"phar://composer.phar/vendor/autoload.php\";" > /out/autoload.php
    SPIKE_AUTOLOAD=/out/autoload.php php -d error_reporting=E_ALL^E_DEPRECATED /w/tools/solver-spike/verify.php /p /report.json /out/solver.json > /out/solver.log 2>&1; echo "exit $?" >> /out/solver.log
  '
