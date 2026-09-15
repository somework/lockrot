<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\LockrotConfig;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;

/**
 * Everything a formatter needs about the run itself rather than about the report: which file was
 * analysed, which verdict the run would fail on, and which lockrot produced it.
 *
 * `table` and `json` ignore it; `github` and `sarif` need it to point their annotations at
 * composer.lock and to decide which findings are reported as errors rather than warnings.
 */
final class FormatContext
{
    public const LEVEL_ERROR = 'error';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_NOTE = 'note';

    private ?string $lockPath;
    private string $failOn;
    private string $toolVersion;

    private function __construct(?string $lockPath, string $failOn, string $toolVersion)
    {
        $this->lockPath = $lockPath;
        $this->failOn = $failOn;
        $this->toolVersion = $toolVersion;
    }

    /**
     * @param null|string $lockPath absolute path of the analysed composer.lock, null when unknown
     * @param string      $failOn   the resolved fail-on, LockrotConfig::FAIL_ON_NONE when none
     */
    public static function create(?string $lockPath, string $failOn, string $toolVersion = Version::STRING): self
    {
        return new self($lockPath, $failOn, $toolVersion);
    }

    /** The context for a run with nothing to say: no lock path, no fail-on threshold. */
    public static function unknown(): self
    {
        return new self(null, LockrotConfig::FAIL_ON_NONE, Version::STRING);
    }

    public function lockPath(): ?string
    {
        return $this->lockPath;
    }

    public function failOn(): string
    {
        return $this->failOn;
    }

    public function toolVersion(): string
    {
        return $this->toolVersion;
    }

    /**
     * The annotation severity a finding is reported at, shared by every format that has one, so the
     * colour a reviewer sees matches the exit code the same run produces: a finding that on its own
     * would make `composer lockrot` exit 1 is an error, anything else flagged is a warning, and the
     * rows that only appear under --all are notes.
     *
     * The match is with the fail-on threshold, not with the exit code in every case: --strict-network
     * exits 1 on an unreachable repository or GitHub even when nothing is flagged (see
     * Policy::exitCode()), and no individual finding is the cause of that, so none is marked error
     * for it. The reason is carried by the report notes instead, which both formats emit.
     *
     * SARIF spells the third level `note` (its own enum); the GitHub workflow command for it is
     * `notice`, which GithubFormatter translates.
     *
     * A baseline narrows the same rule rather than changing it: a finding the project has already
     * accepted cannot fail the run (see Policy::exitCode()), so it is reported at `note` too —
     * annotation severity keeps matching the exit code. New and worsened findings map as usual.
     *
     * @param null|BaselineComparison $baseline the run's comparison, from Report::baseline()
     */
    public function levelOf(Finding $finding, ?BaselineComparison $baseline = null): string
    {
        if ($baseline !== null && $baseline->isKnown($finding->package())) {
            return self::LEVEL_NOTE;
        }
        $verdict = $finding->verdict();
        if ($this->failOn !== LockrotConfig::FAIL_ON_NONE && Verdict::severity($verdict) >= Verdict::severity($this->failOn)) {
            return self::LEVEL_ERROR;
        }

        return Verdict::flagged($verdict) ? self::LEVEL_WARNING : self::LEVEL_NOTE;
    }
}
