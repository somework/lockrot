<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

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
