<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

/** @internal */
final class ExposedPackage implements \JsonSerializable
{
    private string $package;
    /** @var list<string> */
    private array $chain;
    private string $verdict;
    private ?string $lead;
    /** @var list<string> */
    private array $flagIds;

    /**
     * @param list<string> $chain   the shortest chain from the parent to the package
     * @param list<string> $flagIds the counted flags, in flag order
     */
    public function __construct(string $package, array $chain, string $verdict, ?string $lead, array $flagIds)
    {
        $this->package = $package;
        $this->chain = $chain;
        $this->verdict = $verdict;
        $this->lead = $lead;
        $this->flagIds = $flagIds;
    }

    public function package(): string
    {
        return $this->package;
    }

    /** @return list<string> */
    public function chain(): array
    {
        return $this->chain;
    }

    public function lead(): ?string
    {
        return $this->lead;
    }

    /** @return array{package: string, chain: list<string>, verdict: string, lead: ?string, flag_ids: list<string>} */
    public function jsonSerialize(): array
    {
        return ['package' => $this->package, 'chain' => $this->chain, 'verdict' => $this->verdict, 'lead' => $this->lead, 'flag_ids' => $this->flagIds];
    }
}
