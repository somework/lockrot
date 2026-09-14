<?php

declare(strict_types=1);

namespace Lockrot\Allowlist;

use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;

final class BuiltinAllowlist
{
    public static function load(?string $path = null): Allowlist
    {
        $path ??= __DIR__.'/../../resources/finished-packages.json';
        $data = JsonReader::readObject($path);
        if (!\is_array($data['entries'] ?? null)) {
            throw new ConfigException('Cannot read built-in allowlist from '.$path);
        }
        $entries = [];
        foreach ($data['entries'] as $row) {
            if (!\is_array($row) || !\is_string($row['pattern'] ?? null) || !\is_string($row['reason'] ?? null)) {
                throw new ConfigException('Invalid built-in allowlist entry in '.$path);
            }
            $version = $row['version'] ?? null;
            $entries[] = new AllowlistEntry($row['pattern'], \is_string($version) ? $version : null, $row['reason'], null, 'builtin');
        }

        return new Allowlist($entries);
    }
}
