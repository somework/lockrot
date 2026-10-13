<?php

declare(strict_types=1);

namespace Lockrot\Allowlist;

use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;
use Lockrot\Signal\Data\Check;

/**
 * `extra.lockrot.accept-unchecked`: the checks a project accepts as permanently unchecked
 * (docs/configuration.md#accept-unchecked). An accepted check leaves S10. It hides no flag and
 * changes no score. The first entry, in config order, that accepts a check is the one report-2
 * writes.
 *
 * @internal
 */
final class UncheckedAcceptance
{
    /** @var list<UncheckedAcceptanceEntry> in config order */
    private array $entries;

    /** @param list<UncheckedAcceptanceEntry> $entries */
    private function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param mixed $rows `extra.lockrot.accept-unchecked` of composer.json, null when it is not set
     *
     * @throws ConfigException for a list that is not an array, or for any invalid entry
     */
    public static function fromConfig($rows): self
    {
        $rows ??= [];
        if (!\is_array($rows) || ($rows !== [] && !JsonReader::isList($rows))) {
            throw new ConfigException('extra.lockrot.accept-unchecked must be an array');
        }
        $entries = [];
        foreach ($rows as $index => $row) {
            $entries[] = UncheckedAcceptanceEntry::fromConfig($index, $row);
        }

        return new self($entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * The first entry that accepts the S10 entry of the package, null when none does.
     *
     * @param list<?string> $composerUrls the URLs that name the Composer repository that served the package
     */
    public function acceptedBy(Check $unchecked, string $package, ?string $activityHost, ?string $activityRepo, ?string $sourceUrl, array $composerUrls, \DateTimeImmutable $now): ?UncheckedAcceptanceEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->accepts($unchecked, $package, $activityHost, $activityRepo, $sourceUrl, $composerUrls, $now)) {
                return $entry;
            }
        }

        return null;
    }
}
