<?php

declare(strict_types=1);

namespace Lockrot\Score;

use Lockrot\Security\Severity;

/**
 * One counted advisory as the score engine reads it.
 *
 * @internal
 */
final class CountedAdvisory
{
    private string $id;
    private string $severity;
    private string $fixKind;

    /**
     * @param string $severity a Composer word or a bucket ({@see Severity::fromComposer()})
     * @param string $fixKind  one of {@see \Lockrot\Verdict\ScoreModel::FIX_KINDS}
     */
    public function __construct(string $id, string $severity, string $fixKind)
    {
        $this->id = $id;
        $this->severity = $severity;
        $this->fixKind = $fixKind;
    }

    /** The same advisory with the severity bucket of its Composer word. */
    public function bucketed(): self
    {
        return new self($this->id, Severity::fromComposer($this->severity)->bucket(), $this->fixKind);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    public function fixKind(): string
    {
        return $this->fixKind;
    }
}
