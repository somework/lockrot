<?php

declare(strict_types=1);

namespace Lockrot\Allowlist;

use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;

/** @internal */
final class ProjectIgnoreList
{
    /**
     * @param array<string, mixed> $lockrotExtra `extra.lockrot` of composer.json. The shape is checked
     *                                            again for a caller that bypasses the config schema
     */
    public static function fromExtra(array $lockrotExtra): Allowlist
    {
        $rows = $lockrotExtra['ignore'] ?? [];
        if (!\is_array($rows)) {
            throw new ConfigException('extra.lockrot.ignore must be an array');
        }
        $entries = [];
        foreach ($rows as $index => $row) {
            $entries[] = self::entry((string) $index, $row);
        }

        return new Allowlist($entries, []);
    }

    /**
     * The schema cannot express two checks: that "reason" is not only whitespace (`minLength` counts
     * characters) and that "expires" is a real calendar date (the pattern checks only the shape).
     *
     * @param mixed $row
     */
    private static function entry(string $index, $row): AllowlistEntry
    {
        if (!\is_array($row)) {
            throw new ConfigException(\sprintf('extra.lockrot.ignore[%s] must be an object', $index));
        }
        $row = JsonReader::stringKeyed($row);

        $package = $row['package'] ?? null;
        if (!\is_string($package) || $package === '') {
            throw new ConfigException(\sprintf('extra.lockrot.ignore[%s] must have a "package" name', $index));
        }
        $reason = $row['reason'] ?? null;
        if (!\is_string($reason) || trim($reason) === '') {
            throw new ConfigException(\sprintf('extra.lockrot.ignore[%s] (%s) needs a non-empty "reason"', $index, $package));
        }
        $version = $row['version'] ?? null;
        if ($version !== null && !\is_string($version)) {
            throw new ConfigException(\sprintf('extra.lockrot.ignore[%s] (%s) "version" must be a string', $index, $package));
        }

        return new AllowlistEntry($package, $version, $reason, self::expires($index, $package, $row['expires'] ?? null), 'project');
    }

    /** @param mixed $expires */
    private static function expires(string $index, string $package, $expires): ?\DateTimeImmutable
    {
        if ($expires === null) {
            return null;
        }
        $matches = [];
        if (!\is_string($expires) || preg_match('{^(\d{4})-(\d{2})-(\d{2})$}', $expires, $matches) !== 1) {
            throw new ConfigException(\sprintf('extra.lockrot.ignore[%s] (%s) "expires" must be YYYY-MM-DD', $index, $package));
        }
        if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw new ConfigException(\sprintf('extra.lockrot.ignore[%s] (%s) "expires" must be a valid YYYY-MM-DD date', $index, $package));
        }

        return new \DateTimeImmutable($expires.'T23:59:59+00:00');
    }
}
