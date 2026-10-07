<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

/**
 * Opens the download as a PHP archive, like Composer's `SelfUpdateCommand::validatePhar()`, but
 * also when `phar.readonly` is On: opening an existing archive is a read. The catch covers only
 * `UnexpectedValueException`: `Phar::__construct()` does not declare `PharException`, and PHPStan
 * rejects that catch. Any other exception propagates, so an unknown fault is not reported as a
 * damaged download. The copy can carry the alias that the running PHAR maps: PHP rejects a
 * duplicate alias only when it registers one.
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
