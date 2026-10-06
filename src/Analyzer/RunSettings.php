<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Config\Gate;
use Lockrot\Signal\Thresholds;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Verdict;

/**
 * What a run was told to do, so its report can say so: docs/schema.md#what-the-run-was-told.
 * The report names the lock by file name only: an absolute path carries the account name and often
 * a client's directory name, and people publish reports.
 *
 * @internal
 */
final class RunSettings
{
    private ?string $project;
    private ?string $rootPackage;
    private ?string $targetPhp;
    private ?string $lockFile;
    private ?FailOn $failOn;
    private ?Thresholds $thresholds;
    private ?string $projectPhp;
    private bool $strictNetwork;
    private string $mode;

    /**
     * @param string $mode one of {@see Gate::MODES}
     *
     * @throws \InvalidArgumentException for a mode not in {@see Gate::MODES}
     */
    public function __construct(?string $project, ?string $rootPackage, ?string $targetPhp, ?string $lockPath, ?FailOn $failOn, ?Thresholds $thresholds, ?string $projectPhp = null, bool $strictNetwork = false, string $mode = Gate::MODE_CHECK)
    {
        if (!\in_array($mode, Gate::MODES, true)) {
            throw new \InvalidArgumentException(\sprintf('no run in mode "%s"; the modes are %s', $mode, implode(', ', Gate::MODES)));
        }
        $this->project = $project;
        $this->rootPackage = $rootPackage;
        $this->targetPhp = $targetPhp;
        $this->lockFile = $lockPath === null ? null : basename($lockPath);
        $this->failOn = $failOn;
        $this->thresholds = $thresholds;
        $this->projectPhp = $projectPhp;
        $this->strictNetwork = $strictNetwork;
        $this->mode = $mode;
    }

    /** Null only where nothing told the run, which is only ever a test. */
    public function failOn(): ?FailOn
    {
        return $this->failOn;
    }

    public function strictNetwork(): bool
    {
        return $this->strictNetwork;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'project' => $this->project,
            'root_package' => $this->rootPackage,
            'target_php' => $this->targetPhp,
            'project_php' => $this->projectPhp,
            'lock_file' => $this->lockFile,
            'fail_on' => $this->failOn === null ? null : $this->failOn->value(),
            'fail_on_kind' => $this->failOn === null ? null : $this->failOn->kind(),
            'strict_network' => $this->strictNetwork,
            'mode' => $this->mode,
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
