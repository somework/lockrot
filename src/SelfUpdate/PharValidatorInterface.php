<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

/** @internal */
interface PharValidatorInterface
{
    /** @return string|null the runtime's message, or null when the archive is readable */
    public function validate(string $path): ?string;
}
