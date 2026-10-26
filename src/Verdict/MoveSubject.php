<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Lock\ProjectConfig;

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
    private ?string $listedIn = null;
    /** @var list<string> */
    private array $chain = [];

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

    /**
     * A copy that knows the composer.json section that lists the package: `require` when both do.
     *
     * @param list<string> $namedIn `named_in`
     */
    public function withNamedIn(array $namedIn): self
    {
        $clone = clone $this;
        $clone->listedIn = \in_array(ProjectConfig::REQUIRE, $namedIn, true) ? ProjectConfig::REQUIRE : (\in_array(ProjectConfig::REQUIRE_DEV, $namedIn, true) ? ProjectConfig::REQUIRE_DEV : null);

        return $clone;
    }

    /**
     * A copy that knows the finding's `chain`.
     *
     * @param list<string> $chain
     */
    public function withChain(array $chain): self
    {
        $clone = clone $this;
        $clone->chain = $chain;

        return $clone;
    }

    /** The package that requires this one on `chain`, when the chain passes another package between it and the direct requirement. */
    public function requirer(): ?string
    {
        return \count($this->chain) >= 3 ? $this->chain[\count($this->chain) - 2] : null;
    }

    /** `chain[0]`: the direct requirement that the chain starts at. */
    public function chainHead(): ?string
    {
        return $this->chain[0] ?? null;
    }

    /** Whether composer.json lists the package, so its command is the root command of that section, with or without `--dev`. */
    public function isListed(): bool
    {
        return $this->listedIn !== null || $this->direct;
    }

    public function isListedInRequireDevOnly(): bool
    {
        return $this->listedIn === ProjectConfig::REQUIRE_DEV;
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
