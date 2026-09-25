<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Baseline\BaselineComparison;
use Lockrot\Config\LockrotConfig;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;

/**
 * Everything a formatter needs about the run itself rather than about the report: which file was
 * analysed, which verdict the run would fail on, and which lockrot produced it.
 *
 * `json` ignores it; `github` and `sarif` need it to point their annotations at composer.lock and
 * to decide which findings are reported as errors rather than warnings; `table` needs the width of
 * the terminal it is about to be printed on.
 *
 * @internal
 */
final class FormatContext
{
    public const LEVEL_ERROR = 'error';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_NOTE = 'note';

    /** The width `table` renders into when nothing could be detected. */
    public const DEFAULT_WIDTH = 120;

    /**
     * Narrower than this and the list stops being a list, so the value is treated as a failed
     * detection rather than an instruction. {@see TerminalWidth::detect()} applies the same floor to
     * what it reads from the environment; this one guards every other way a width can arrive.
     */
    public const MIN_WIDTH = 40;

    private ?string $lockPath;
    private FailOn $failOn;
    private string $toolVersion;
    private int $terminalWidth;

    private function __construct(?string $lockPath, string $failOn, string $toolVersion, int $terminalWidth)
    {
        $this->lockPath = $lockPath;
        $this->failOn = FailOn::fromString($failOn);
        $this->toolVersion = $toolVersion;
        $this->terminalWidth = max(self::MIN_WIDTH, $terminalWidth);
    }

    /**
     * @param null|string $lockPath      absolute path of the analysed composer.lock, null when unknown
     * @param string      $failOn        the resolved fail-on — a verdict, a priority or LockrotConfig::FAIL_ON_NONE
     * @param int         $terminalWidth columns available for `table`, clamped to MIN_WIDTH
     */
    public static function create(?string $lockPath, string $failOn, string $toolVersion = Version::STRING, int $terminalWidth = self::DEFAULT_WIDTH): self
    {
        return new self($lockPath, $failOn, $toolVersion, $terminalWidth);
    }

    /** The context for a run with nothing to say: no lock path, no fail-on threshold, default width. */
    public static function unknown(): self
    {
        return new self(null, LockrotConfig::FAIL_ON_NONE, Version::STRING, self::DEFAULT_WIDTH);
    }

    public function lockPath(): ?string
    {
        return $this->lockPath;
    }

    public function failOn(): string
    {
        return $this->failOn->value();
    }

    public function toolVersion(): string
    {
        return $this->toolVersion;
    }

    /** Columns the `table` format may use, never below MIN_WIDTH. */
    public function terminalWidth(): int
    {
        return $this->terminalWidth;
    }

    /**
     * The annotation severity a finding is reported at, shared by every format that has one, so the
     * colour a reviewer sees matches the exit code the same run produces: a finding that on its own
     * would make `composer lockrot` exit 1 is an error — at or above the fail-on verdict, or at or
     * above the fail-on priority when the threshold is one — anything else flagged is a warning,
     * and the rows that only appear under --all are notes.
     *
     * The match is with the fail-on threshold, not with the exit code in every case: --strict-network
     * exits 1 on an unreachable repository or repository host even when nothing is flagged (see
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
        if ($this->failOn->reaches($finding)) {
            return self::LEVEL_ERROR;
        }

        return Verdict::flagged($finding->verdict()) ? self::LEVEL_WARNING : self::LEVEL_NOTE;
    }
}
