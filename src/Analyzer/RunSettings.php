<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Signal\Thresholds;
use Lockrot\Verdict\Verdict;

/**
 * What a run was told to do, so its report can say so.
 *
 * Every verdict in a report depends on settings the report did not record. `stale` rather than
 * `silent` is decided by the thresholds; "released before the target PHP's GA date" is decided by
 * the target. Until this existed, `--format=json` mentioned the target PHP in exactly one place —
 * inside the data of an S5 signal — so a run where S5 never fired left no trace of what it was
 * aiming at, and the thresholds left none at all. Two people comparing two reports could not tell
 * whether they differ because the locks differ or because the settings did.
 *
 * `flagged_verdicts` is not a setting but the vocabulary the run used, and it is here for the same
 * reason: a reader deciding what counts as a finding should not have to know the severity ladder by
 * heart or guess it from the counts.
 *
 * The lock is named, never located: a report is something people publish, and an absolute path
 * carries the account it ran under and often the client's directory name.
 */
final class RunSettings
{
    private ?string $targetPhp;
    private ?string $lockFile;
    private ?string $failOn;
    private ?Thresholds $thresholds;

    public function __construct(?string $targetPhp, ?string $lockPath, ?string $failOn, ?Thresholds $thresholds)
    {
        $this->targetPhp = $targetPhp;
        $this->lockFile = $lockPath === null ? null : basename($lockPath);
        $this->failOn = $failOn;
        $this->thresholds = $thresholds;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'target_php' => $this->targetPhp,
            'lock_file' => $this->lockFile,
            'fail_on' => $this->failOn,
            'thresholds' => $this->thresholds === null ? null : [
                'release-warn-years' => $this->thresholds->releaseWarnYears(),
                'release-high-years' => $this->thresholds->releaseHighYears(),
                'push-warn-years' => $this->thresholds->pushWarnYears(),
                'push-high-years' => $this->thresholds->pushHighYears(),
            ],
            'flagged_verdicts' => array_values(array_filter(Verdict::all(), [Verdict::class, 'flagged'])),
        ];
    }
}
