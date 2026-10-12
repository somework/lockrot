<?php

declare(strict_types=1);

namespace Lockrot\Baseline;

use Lockrot\Verdict\ScoreModel;

/**
 * What a baseline knows about one package: its counted facts, each with the date it became known
 * (docs/baseline.md, "What `--generate-baseline` does").
 *
 * @internal
 */
final class BaselineEntry implements \JsonSerializable
{
    private string $package;
    private string $version;
    private string $firstSeen;
    /** @var array<string, string> maintenance flag => known_since, in flag order */
    private array $flags;
    /** @var list<RecordedAdvisory> by id, compared with strcmp */
    private array $advisories;

    /**
     * @param array<string, string>  $flags      maintenance flag => known_since, in any order
     * @param list<RecordedAdvisory> $advisories in any order
     *
     * @throws \InvalidArgumentException for a flag that is not a maintenance flag
     */
    public function __construct(string $package, string $version, string $firstSeen, array $flags, array $advisories)
    {
        $unknown = array_diff(array_keys($flags), array_keys(ScoreModel::POINTS));
        if ($unknown !== []) {
            throw new \InvalidArgumentException('a baseline entry stores maintenance flags only, not '.implode(', ', $unknown));
        }
        usort($advisories, static fn (RecordedAdvisory $a, RecordedAdvisory $b): int => strcmp($a->id(), $b->id()));
        $this->package = $package;
        $this->version = $version;
        $this->firstSeen = $firstSeen;
        $this->flags = [];
        foreach (array_keys(ScoreModel::POINTS) as $flag) {
            if (isset($flags[$flag])) {
                $this->flags[$flag] = $flags[$flag];
            }
        }
        $this->advisories = $advisories;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function version(): string
    {
        return $this->version;
    }

    /** The `Y-m-d` date this package first got an entry. */
    public function firstSeen(): string
    {
        return $this->firstSeen;
    }

    /** @return array<string, string> maintenance flag => known_since, in flag order */
    public function flags(): array
    {
        return $this->flags;
    }

    /** The first flag in flag order, null for an entry that stores advisories only. */
    public function recordedLead(): ?string
    {
        return array_key_first($this->flags);
    }

    /** @return list<RecordedAdvisory> by id */
    public function advisories(): array
    {
        return $this->advisories;
    }

    public function advisory(string $id): ?RecordedAdvisory
    {
        foreach ($this->advisories as $advisory) {
            if ($advisory->id() === $id) {
                return $advisory;
            }
        }

        return null;
    }

    /**
     * The entry as baseline-2 writes it. A map key that is empty is left out, because
     * `json_decode(…, true)` reads `{}` back as `[]`. Advisory ids go through an object, so `"0"` stays
     * a key.
     *
     * @return array{version: string, first_seen: string, flags?: array<string, array{known_since: string}>, advisories?: \stdClass}
     */
    public function jsonSerialize(): array
    {
        $entry = ['version' => $this->version, 'first_seen' => $this->firstSeen];
        if ($this->flags !== []) {
            $entry['flags'] = array_map(static fn (string $since): array => ['known_since' => $since], $this->flags);
        }
        if ($this->advisories !== []) {
            $advisories = new \stdClass();
            foreach ($this->advisories as $advisory) {
                $advisories->{$advisory->id()} = $advisory;
            }
            $entry['advisories'] = $advisories;
        }

        return $entry;
    }
}
