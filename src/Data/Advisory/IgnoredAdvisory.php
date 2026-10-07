<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

/**
 * An advisory that affects the installed version and that Composer's audit ignore lists keep out
 * of S9.
 *
 * @internal
 */
final class IgnoredAdvisory
{
    private Advisory $advisory;
    private AdvisoryIgnoreMatch $match;

    public function __construct(Advisory $advisory, AdvisoryIgnoreMatch $match)
    {
        $this->advisory = $advisory;
        $this->match = $match;
    }

    public function advisory(): Advisory
    {
        return $this->advisory;
    }

    public function match(): AdvisoryIgnoreMatch
    {
        return $this->match;
    }
}
