<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Signal\AgeMeasure;
use Lockrot\Signal\AgeReading;
use Lockrot\Signal\PackageFacts;

/**
 * report-2's `finding.age`: the four readings of {@see AgeMeasure}, the ones that S2, S4 and S8
 * judge. No score, gate, sort or baseline reads it.
 *
 * @internal
 */
final class Age implements \JsonSerializable
{
    private AgeReading $installed;
    private AgeReading $release;
    private AgeReading $branchRelease;
    private AgeReading $push;

    public function __construct(AgeReading $installed, AgeReading $release, AgeReading $branchRelease, AgeReading $push)
    {
        $this->installed = $installed;
        $this->release = $release;
        $this->branchRelease = $branchRelease;
        $this->push = $push;
    }

    /** @param PackageFacts $facts with the activity lookup, whose reason the push reading takes */
    public static function of(AgeMeasure $measure, PackageFacts $facts): self
    {
        $status = $facts->metadataStatus();

        return new self($measure->installed($facts), $measure->release($facts, $status), $measure->branchRelease($facts, $status), $measure->push($facts));
    }

    public function installed(): AgeReading
    {
        return $this->installed;
    }

    public function release(): AgeReading
    {
        return $this->release;
    }

    public function branchRelease(): AgeReading
    {
        return $this->branchRelease;
    }

    public function push(): AgeReading
    {
        return $this->push;
    }

    /** @return array<string, ?AgeReading|?string> */
    public function jsonSerialize(): array
    {
        $out = [];
        foreach (['installed' => $this->installed, 'release' => $this->release, 'branch_release' => $this->branchRelease, 'push' => $this->push] as $key => $reading) {
            $out[$key] = $reading->isMeasured() ? $reading : null;
            $out[$key.'_unmeasured'] = $reading->unmeasured();
        }

        return $out;
    }
}
