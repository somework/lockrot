<?php

declare(strict_types=1);

namespace Lockrot\Solver;

/**
 * The Composer request behind one move: `composer require <pkg>:<constraint>` for a package that
 * composer.json requires, else `composer update <pkg>:<constraint>`, with a root php requirement
 * that a `raise-php` move writes first.
 *
 * @internal
 */
final class MoveQuery
{
    private string $package;
    private string $constraint;
    private string $version;
    private bool $isRequire;
    private ?string $php;

    /**
     * @param string  $version the release that the move names, which the hold scan reads
     * @param ?string $php     the `require.php` that the move writes first, null for none
     */
    private function __construct(string $package, string $constraint, string $version, bool $isRequire, ?string $php)
    {
        $this->package = $package;
        $this->constraint = $constraint;
        $this->version = $version;
        $this->isRequire = $isRequire;
        $this->php = $php;
    }

    public static function update(string $package, string $constraint, string $version, ?string $php = null): self
    {
        return new self($package, $constraint, $version, false, $php);
    }

    public static function require(string $package, string $constraint, string $version, ?string $php = null): self
    {
        return new self($package, $constraint, $version, true, $php);
    }

    public function package(): string
    {
        return $this->package;
    }

    public function constraint(): string
    {
        return $this->constraint;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function isRequire(): bool
    {
        return $this->isRequire;
    }

    public function php(): ?string
    {
        return $this->php;
    }

    /** One key per Composer request: two moves with one request share one check. */
    public function key(): string
    {
        return implode(' ', [$this->isRequire ? 'require' : 'update', $this->package, $this->constraint, $this->php]);
    }

    public function request(): SolveRequest
    {
        $php = $this->php === null ? [] : ['php' => $this->php];

        return $this->isRequire
            ? SolveRequest::require($this->package, [$this->package => $this->constraint] + $php)
            : SolveRequest::update([$this->package => $this->constraint])->withRootChanges($php);
    }
}
