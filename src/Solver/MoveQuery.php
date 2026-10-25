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
    private ?string $newest;
    private bool $dev = false;

    /**
     * @param string  $version the release that the move names, which the hold scan reads
     * @param ?string $php     the `require.php` that the move writes first, null for none
     * @param ?string $newest  the release that the move installs, `newest`: a form resolves the move only when it installs it; null for any release
     */
    private function __construct(string $package, string $constraint, string $version, bool $isRequire, ?string $php, ?string $newest)
    {
        $this->newest = $newest;
        $this->package = $package;
        $this->constraint = $constraint;
        $this->version = $version;
        $this->isRequire = $isRequire;
        $this->php = $php;
    }

    public static function update(string $package, string $constraint, string $version, ?string $php = null, ?string $newest = null): self
    {
        return new self($package, $constraint, $version, false, $php, $newest);
    }

    public static function require(string $package, string $constraint, string $version, ?string $php = null, ?string $newest = null): self
    {
        return new self($package, $constraint, $version, true, $php, $newest);
    }

    /** A copy for a package that only `require-dev` lists: `composer require --dev`. */
    public function inRequireDev(): self
    {
        $clone = clone $this;
        $clone->dev = $this->isRequire;

        return $clone;
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

    public function newest(): ?string
    {
        return $this->newest;
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
            ? SolveRequest::require($this->package, [$this->package => $this->constraint] + $php, $this->dev)
            : SolveRequest::update([$this->package => $this->constraint])->withRootChanges($php);
    }

    /**
     * The same request that may also update the holders of its refusal: one request, inline form.
     *
     * @param list<string> $holders
     */
    public function jointRequest(array $holders): SolveRequest
    {
        return $this->request()->withJoined($holders);
    }
}
