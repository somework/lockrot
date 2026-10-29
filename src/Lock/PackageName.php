<?php

declare(strict_types=1);

namespace Lockrot\Lock;

/** @internal */
final class PackageName
{
    /**
     * The identity of a package name: Composer's BasePackage stores every name lower-cased, so two
     * spellings of one name are one package.
     */
    public static function key(string $name): string
    {
        return strtolower($name);
    }
}
