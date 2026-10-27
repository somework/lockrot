<?php

declare(strict_types=1);

namespace Lockrot\Allowlist;

use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;
use Lockrot\Output\TerminalText;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\ScoreModel;

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
     * Each `flags` list holds maintenance flags only. It runs before the config schema, so the
     * allowlist's own message prints, never the schema's enum error.
     *
     * @param mixed $rows `extra.lockrot.ignore`
     *
     * @return array<array-key, ConfigException> by the index of each entry whose `flags` lockrot refuses
     */
    public static function flagRefusals($rows): array
    {
        $refusals = [];
        foreach (\is_array($rows) ? $rows : [] as $index => $row) {
            try {
                self::flags((string) $index, \is_array($row) ? ($row['flags'] ?? null) : null);
            } catch (ConfigException $e) {
                $refusals[$index] = $e;
            }
        }

        return $refusals;
    }

    /**
     * @param mixed $flags an entry's `flags`
     *
     * @return ?list<string> each flag once, null for an entry without `flags`
     *
     * @throws ConfigException for a value that is no array, an empty list, or a flag that is not a maintenance flag
     */
    private static function flags(string $index, $flags): ?array
    {
        if ($flags === null) {
            return null;
        }
        $at = TerminalText::escape($index, 255);
        if (!\is_array($flags)) {
            throw new ConfigException(\sprintf('extra.lockrot.ignore[%s] "flags" must be an array of maintenance flags, such as ["stale"]', $at));
        }
        if ($flags === []) {
            throw new ConfigException(\sprintf('extra.lockrot.ignore[%s] "flags" must list at least one maintenance flag', $at));
        }
        $out = [];
        foreach ($flags as $flag) {
            if ($flag === FlagSet::VULNERABLE) {
                throw new ConfigException(\sprintf("extra.lockrot.ignore[%s] \"flags\": the allowlist accepts maintenance flags; accept advisories in Composer's audit policy (config.policy.advisories), which composer audit reads too", $at));
            }
            if (!\is_string($flag) || !isset(ScoreModel::POINTS[$flag])) {
                throw new ConfigException(\sprintf('extra.lockrot.ignore[%s] "flags" must list maintenance flags only: %s', $at, implode(', ', array_keys(ScoreModel::POINTS))));
            }
            if (!\in_array($flag, $out, true)) {
                $out[] = $flag;
            }
        }

        return $out;
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

        return new AllowlistEntry($package, $version, $reason, self::expires($index, $package, $row['expires'] ?? null), AllowlistEntry::BY_PROJECT, self::flags($index, $row['flags'] ?? null));
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
