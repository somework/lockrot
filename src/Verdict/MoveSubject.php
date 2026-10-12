<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * What a move's sentence reads beside the move: the package, how the project reaches it and the
 * run's PHP.
 *
 * @internal
 */
final class MoveSubject
{
    private string $package;
    private string $version;
    private bool $direct;
    /** @var list<string> */
    private array $dependents;
    private ?string $targetPhp;
    private ?string $projectPhpLowest;

    /**
     * @param list<string> $dependents       `direct_dependents`
     * @param ?string      $projectPhpLowest `run.project_php_lowest`
     */
    public function __construct(string $package, string $version, bool $direct, array $dependents, ?string $targetPhp, ?string $projectPhpLowest)
    {
        $this->package = $package;
        $this->version = $version;
        $this->direct = $direct;
        $this->dependents = $dependents;
        $this->targetPhp = $targetPhp;
        $this->projectPhpLowest = $projectPhpLowest;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function isDirect(): bool
    {
        return $this->direct;
    }

    /** @return list<string> */
    public function dependents(): array
    {
        return $this->dependents;
    }

    public function targetPhp(): ?string
    {
        return $this->targetPhp;
    }

    public function projectPhpLowest(): ?string
    {
        return $this->projectPhpLowest;
    }
}
