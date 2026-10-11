<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

/**
 * S8's highest releasing branch whose php the floors admit (docs/verdicts.md#left-behind).
 *
 * @internal
 */
final class ReachableBranch
{
    private string $branch;
    private string $version;
    private string $release;
    private float $years;
    private ?string $php;
    /** @var array{project: ?bool, target: ?bool} */
    private array $admits;
    private ?string $suggestedConstraint;

    /**
     * @param string                               $release an ISO 8601 date and time
     * @param float                                $years   the age of the release in years on the run clock
     * @param array{project: ?bool, target: ?bool} $admits  whether each floor admits the branch's php, null for a floor lockrot could not compare
     */
    public function __construct(string $branch, string $version, string $release, float $years, ?string $php, array $admits, ?string $suggestedConstraint)
    {
        $this->branch = $branch;
        $this->version = $version;
        $this->release = $release;
        $this->years = $years;
        $this->php = $php;
        $this->admits = $admits;
        $this->suggestedConstraint = $suggestedConstraint;
    }

    public function branch(): string
    {
        return $this->branch;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function release(): string
    {
        return $this->release;
    }

    public function years(): float
    {
        return $this->years;
    }

    public function php(): ?string
    {
        return $this->php;
    }

    /** @return array{project: ?bool, target: ?bool} */
    public function admits(): array
    {
        return $this->admits;
    }

    public function suggestedConstraint(): ?string
    {
        return $this->suggestedConstraint;
    }
}
