<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Score\IfApplied;

/**
 * `security.partial`: what `composer update` installs on the installed branch when it clears some,
 * not all, counted advisories and the move is not that update. It is judged as the `update` move it
 * is, its flag clears included.
 *
 * @internal
 */
final class SecurityPartial implements \JsonSerializable
{
    private string $version;
    /** @var list<MoveFact> */
    private array $clears;
    /** @var list<MoveFact> */
    private array $unverified;
    private IfApplied $ifApplied;

    /**
     * @param list<MoveFact> $clears     the flags it settles, then the advisories it clears
     * @param list<MoveFact> $unverified
     */
    public function __construct(string $version, array $clears, array $unverified, IfApplied $ifApplied)
    {
        $this->version = $version;
        $this->clears = $clears;
        $this->unverified = $unverified;
        $this->ifApplied = $ifApplied->asRow();
    }

    public function version(): string
    {
        return $this->version;
    }

    public function ifApplied(): IfApplied
    {
        return $this->ifApplied;
    }

    /** @return array{version: string, clears: list<MoveFact>, unverified: list<MoveFact>, clears_all: bool, if_applied: IfApplied} */
    public function jsonSerialize(): array
    {
        return ['version' => $this->version, 'clears' => $this->clears, 'unverified' => $this->unverified, 'clears_all' => false, 'if_applied' => $this->ifApplied];
    }
}
