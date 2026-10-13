<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Score\CountedAdvisory;
use Lockrot\Verdict\Finding;

/**
 * A project `ignore[]` entry that matches a package with counted advisories: one entry of the
 * `ignore_does_not_cover_advisories` note's `entries`.
 *
 * @internal
 */
final class UncoveredAdvisories implements \JsonSerializable
{
    private string $package;
    private string $pattern;
    /** @var list<string> */
    private array $advisories;

    /** @param list<string> $advisories the counted advisory ids, sorted */
    public function __construct(string $package, string $pattern, array $advisories)
    {
        $this->package = $package;
        $this->pattern = $pattern;
        $this->advisories = $advisories;
    }

    /**
     * The project `ignore[]` entries that match findings with counted advisories, in report order.
     *
     * @param list<Finding> $findings
     *
     * @return list<self>
     */
    public static function of(array $findings): array
    {
        usort($findings, [Report::class, 'compareGraded']);
        $out = [];
        foreach ($findings as $finding) {
            $entry = $finding->details()->entry();
            $advisories = array_map(static fn (CountedAdvisory $advisory): string => $advisory->id(), $finding->flags()->advisories());
            if ($entry === null || $entry->by() !== AllowlistEntry::BY_PROJECT || $advisories === []) {
                continue;
            }
            usort($advisories, 'strcmp');
            $out[] = new self($finding->package(), (string) $entry->pattern(), $advisories);
        }

        return $out;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    /** @return list<string> */
    public function advisories(): array
    {
        return $this->advisories;
    }

    /** @return array{package: string, pattern: string, advisories: list<string>} */
    public function jsonSerialize(): array
    {
        return ['package' => $this->package, 'pattern' => $this->pattern, 'advisories' => $this->advisories];
    }
}
