<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

/**
 * Opens the downloaded file as a PHP archive, the same check Composer's own self-update runs before
 * it swaps the file in (`Composer\Command\SelfUpdateCommand::validatePhar()`, 2.10.3
 * src/Composer/Command/SelfUpdateCommand.php:712).
 *
 * Two deliberate differences from Composer's version:
 *
 * - Composer returns early (treating the archive as good) when `phar.readonly` is On, which is the
 *   default, so in practice its check rarely runs. lockrot always runs it: opening an existing
 *   archive is a read, which `phar.readonly` does not restrict.
 * - Only `UnexpectedValueException` is turned into a message. Composer also catches `PharException`,
 *   but `Phar::__construct()` is not declared to throw it, so a catch for it here is dead code.
 *   Anything else propagates, exactly as Composer lets it: {@see PharUpdater::install()} has
 *   already removed the temporary file by then and the running archive is untouched, so the command
 *   reports the failure rather than treating an unknown fault as "the download is damaged".
 *
 * The file being checked is a copy of an archive whose alias may already be mapped by the running
 * PHAR. That is not a conflict: PHP only rejects a duplicate alias when it is being registered, not
 * when an archive is opened for reading (covered by PharValidatorTest).
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
