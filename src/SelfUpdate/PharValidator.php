<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

/**
 * Opens the downloaded file as a PHP archive, as Composer's
 * `SelfUpdateCommand::validatePhar()` does, with two differences.
 *
 * Composer skips the check when `phar.readonly` is On, which is the default. lockrot always runs
 * it, because opening an existing archive is a read that `phar.readonly` does not restrict. The
 * catch covers only `UnexpectedValueException`: `Phar::__construct()` does not declare
 * `PharException`, and PHPStan rejects that catch. Any other exception propagates, so an unknown
 * fault is not reported as a damaged download. The copy can carry the alias that the running PHAR
 * maps: PHP rejects a duplicate alias only when it registers one, not when it opens one.
 *
 * @internal
 */
final class PharValidator implements PharValidatorInterface
{
    public function validate(string $path): ?string
    {
        try {
            $phar = new \Phar($path);
            // Frees the handle so the file can be renamed over the running archive right after.
            unset($phar);

            return null;
        } catch (\UnexpectedValueException $e) {
            return $e->getMessage();
        }
    }
}
