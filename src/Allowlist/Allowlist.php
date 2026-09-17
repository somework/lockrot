<?php

declare(strict_types=1);

namespace Lockrot\Allowlist;

use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockedPackage;

final class Allowlist
{
    public const DEFAULT_FINISHED_TYPES = ['metapackage', 'symfony-pack'];

    /** @var list<AllowlistEntry> */
    private array $entries;
    /** @var list<string> */
    private array $finishedTypes;

    /**
     * @param list<AllowlistEntry> $entries
     * @param list<string> $finishedTypes
     */
    public function __construct(array $entries, array $finishedTypes = self::DEFAULT_FINISHED_TYPES)
    {
        $this->entries = $entries;
        $this->finishedTypes = $finishedTypes;
    }

    public function match(LockedPackage $package, ?PackageMetadata $metadata, \DateTimeImmutable $now): ?AllowlistEntry
    {
        foreach ($this->entries as $entry) {
            if (!$entry->isExpired($now) && $entry->matches($package->name(), $package->version())) {
                return $entry;
            }
        }
        foreach ([$package->type(), $metadata !== null ? $metadata->type() : null] as $type) {
            if ($type !== null && \in_array($type, $this->finishedTypes, true)) {
                return new AllowlistEntry('type:'.$type, null, 'package type "'.$type.'" only lists dependencies', null, 'builtin');
            }
        }

        return null;
    }

    /**
     * Both lists of finished types are kept. A type listed by both sides is not deduplicated,
     * because {@see match()} only ever asks `in_array()` whether a type is in the list.
     */
    public function merge(Allowlist $other): self
    {
        return new self(array_merge($this->entries, $other->entries), array_merge($this->finishedTypes, $other->finishedTypes));
    }

    /** @return list<AllowlistEntry> */
    public function entries(): array
    {
        return $this->entries;
    }
}
