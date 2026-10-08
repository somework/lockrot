<?php

declare(strict_types=1);

namespace Lockrot\Allowlist;

use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;

/** @internal */
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
            if (!\is_array($row) || !\is_string($row['id'] ?? null) || !\is_string($row['pattern'] ?? null) || !\is_string($row['reason'] ?? null)) {
                throw new ConfigException('Invalid built-in allowlist entry in '.$path);
            }
            $version = $row['version'] ?? null;
            $entries[] = new AllowlistEntry($row['pattern'], \is_string($version) ? $version : null, $row['reason'], null, AllowlistEntry::BY_BUILTIN, null, $row['id']);
        }

        return new Allowlist($entries);
    }

    /**
     * Every id of a reason lockrot writes: the built-in entries' in file order, then the type
     * entries' ({@see Allowlist::DEFAULT_FINISHED_TYPES}).
     *
     * @return list<string>
     */
    public static function reasonIds(): array
    {
        $ids = [];
        foreach (self::load()->entries() as $entry) {
            $ids[] = (string) $entry->reasonId();
        }
        foreach (Allowlist::DEFAULT_FINISHED_TYPES as $type) {
            $ids[] = (string) AllowlistEntry::forType($type)->reasonId();
        }

        return array_values(array_unique($ids));
    }
}
