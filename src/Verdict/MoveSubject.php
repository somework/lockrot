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
    /** @var array<string, string> section => the key as written ({@see ProjectConfig::listedIn()}) */
    private array $keys = [];
    /** @var array<string, string> section => the key as written, for `php` */
    private array $phpKeys = [];
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
     * A copy that knows the literal composer.json keys of the package and of `php`. A literal key
     * chooses the section of a printed `composer require` over `named_in`, as Composer chooses it.
     *
     * @param array<string, string> $keys    {@see ProjectConfig::listedIn()} of the package
     * @param array<string, string> $phpKeys {@see ProjectConfig::listedIn()} of `php`
     */
    public function withKeys(array $keys, array $phpKeys): self
    {
        $clone = clone $this;
        $clone->keys = $keys;
        $clone->phpKeys = $phpKeys;

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
        return $this->keys !== [] || $this->listedIn !== null || $this->direct;
    }

    public function isListedInRequireDevOnly(): bool
    {
        return $this->keys === [] ? $this->listedIn === ProjectConfig::REQUIRE_DEV : array_keys($this->keys) === [ProjectConfig::REQUIRE_DEV];
    }

    /** Whether both literal sections list the package, so its `composer require` removes the require-dev line. */
    public function isListedInBoth(): bool
    {
        return \count($this->keys) === 2;
    }

    /**
     * The section option of the package's `composer require`. `--no-interaction` keeps a name that
     * both sections list in `require` under any terminal answer, and `--dev` deletes that line.
     *
     * @return list<string>
     */
    public function requireOptions(): array
    {
        if ($this->isListedInBoth()) {
            return ['--no-interaction'];
        }

        return $this->isListedInRequireDevOnly() ? ['--dev'] : [];
    }

    /** The package argument: the require-dev key as written when both sections list it, because Composer removes that key exact-case. */
    public function requireArgument(): string
    {
        return $this->isListedInBoth() ? $this->keys[ProjectConfig::REQUIRE_DEV] : $this->package;
    }

    /** Whether require-dev lists `php`, so the raise of require.php removes that line. */
    public function isPhpInRequireDev(): bool
    {
        return isset($this->phpKeys[ProjectConfig::REQUIRE_DEV]);
    }

    /** @return list<string> the section option of the `composer require php:…` step */
    public function phpRaiseOptions(): array
    {
        return $this->isPhpInRequireDev() ? ['--no-interaction'] : [];
    }

    public function phpRaiseArgument(): string
    {
        return $this->phpKeys[ProjectConfig::REQUIRE_DEV] ?? 'php';
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
