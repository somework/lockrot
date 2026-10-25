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
    private ?string $newestPhp;
    private ?string $platformOverride;
    private ?bool $refusedTargetInside;

    /**
     * @param list<string> $dependents       `direct_dependents`
     * @param ?string      $projectPhpLowest `run.project_php_lowest`
     * @param ?string      $newestPhp        R, the newest PHP minor that the root php allows ({@see \Lockrot\Signal\PhpCap})
     * @param ?string      $platformOverride `run.php_window.platform_override`
     * @param ?bool        $refusedTargetInside `run.php_window.target.inside` while `run.move_check.baseline` is `refused`, else null
     */
    public function __construct(string $package, string $version, bool $direct, array $dependents, ?string $targetPhp, ?string $projectPhpLowest, ?string $newestPhp = null, ?string $platformOverride = null, ?bool $refusedTargetInside = null)
    {
        $this->refusedTargetInside = $refusedTargetInside;
        $this->platformOverride = $platformOverride;
        $this->newestPhp = $newestPhp;
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

    public function newestPhp(): ?string
    {
        return $this->newestPhp;
    }

    public function platformOverride(): ?string
    {
        return $this->platformOverride;
    }

    public function refusedTargetInside(): ?bool
    {
        return $this->refusedTargetInside;
    }
}
