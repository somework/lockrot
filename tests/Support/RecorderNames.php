<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Lock\LockFile;

/** The locked names that `bin/record-fixtures` asks for. */
final class RecorderNames
{
    /**
     * @param bool $into a recording with `--into`, which the corpus runs replay and which reads no packages-dev name
     *
     * @return list<string>
     */
    public static function advisory(LockFile $lock, bool $into): array
    {
        return array_map(static fn ($package): string => $package->name(), $lock->packages(!$into));
    }
}
