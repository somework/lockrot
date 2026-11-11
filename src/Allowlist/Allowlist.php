<?php

declare(strict_types=1);

namespace Lockrot\Allowlist;

use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockedPackage;

/** @internal */
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

    /**
     * A name entry wins, unless it accepts only some flags and the package has a finished type: the
     * type entry accepts every maintenance flag, so the narrower name entry would add nothing but a
     * loss.
     */
    public function match(LockedPackage $package, ?PackageMetadata $metadata, \DateTimeImmutable $now): ?AllowlistEntry
    {
        $byName = null;
        foreach ($this->entries as $entry) {
            if (!$entry->isExpired($now) && $entry->matches($package->name(), $package->version())) {
                $byName = $entry;
                break;
            }
        }
        if ($byName !== null && $byName->acceptsAll()) {
            return $byName;
        }
        foreach ([$package->type(), $metadata !== null ? $metadata->type() : null] as $type) {
            if ($type !== null && \in_array($type, $this->finishedTypes, true)) {
                return AllowlistEntry::forType($type);
            }
        }

        return $byName;
    }

    /** A type that both sides list stays twice: {@see match()} only asks `in_array()`. */
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
