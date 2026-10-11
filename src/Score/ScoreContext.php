<?php

declare(strict_types=1);

namespace Lockrot\Score;

/**
 * What the score basis words from the checks that ran, beside the counted flags.
 *
 * @internal
 */
final class ScoreContext
{
    private bool $maintenanceJudged;
    private bool $advisoriesComplete;
    private bool $livenessComplete;
    private bool $s3Unread;
    private bool $s8Unread;

    /**
     * @param bool $maintenanceJudged  lockrot read the release metadata
     * @param bool $advisoriesComplete every advisory feed answered
     * @param bool $livenessComplete   lockrot read S2 and S4
     * @param bool $s3Unread           no S3 answer
     * @param bool $s8Unread           S8 cannot date a branch that a tag can land on
     */
    public function __construct(bool $maintenanceJudged, bool $advisoriesComplete, bool $livenessComplete, bool $s3Unread, bool $s8Unread)
    {
        $this->maintenanceJudged = $maintenanceJudged;
        $this->advisoriesComplete = $advisoriesComplete;
        $this->livenessComplete = $livenessComplete;
        $this->s3Unread = $s3Unread;
        $this->s8Unread = $s8Unread;
    }

    public function maintenanceJudged(): bool
    {
        return $this->maintenanceJudged;
    }

    public function advisoriesComplete(): bool
    {
        return $this->advisoriesComplete;
    }

    public function livenessComplete(): bool
    {
        return $this->livenessComplete;
    }

    public function s3Unread(): bool
    {
        return $this->s3Unread;
    }

    public function s8Unread(): bool
    {
        return $this->s8Unread;
    }
}
