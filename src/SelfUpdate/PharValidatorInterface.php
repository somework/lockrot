<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

/** @internal */
interface PharValidatorInterface
{
    /**
     * Checks that the archive at $path is one the PHP runtime can open.
     *
     * @return string|null the runtime's own complaint, or null when the archive is readable
     */
    public function validate(string $path): ?string;
}
