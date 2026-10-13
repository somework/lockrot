<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Config\Gate;
use Lockrot\Filesystem\Path;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\SecurityStanding;
use Lockrot\Version;

/** @internal */
final class FormatContext
{
    public const LEVEL_ERROR = 'error';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_NOTE = 'note';

    public const DEFAULT_WIDTH = 120;

    /**
     * Below this width the list stops being a list. {@see TerminalWidth::detect()} applies the same
     * floor to a width that it reads from the environment.
     */
    public const MIN_WIDTH = 40;

    public const DEFAULT_LOCK_NAME = 'composer.lock';

    private ?string $lockPath;
    private string $lockName;
    private ?string $lockDirectory;
    private FailOn $failOn;
    private string $toolVersion;
    private int $terminalWidth;
    private ?int $markdownMaxBytes = null;

    private function __construct(?string $lockPath, FailOn $failOn, string $toolVersion, int $terminalWidth, ?string $projectDirectory)
    {
        $this->lockPath = $lockPath;
        $this->lockName = self::DEFAULT_LOCK_NAME;
        $this->lockDirectory = null;
        if ($lockPath !== null) {
            $relative = $projectDirectory === null ? null : Path::relativeTo($lockPath, $projectDirectory);
            $this->lockName = $relative ?? basename($lockPath);
            $this->lockDirectory = $relative === null ? \dirname($lockPath) : $projectDirectory;
        }
        $this->failOn = $failOn;
        $this->toolVersion = $toolVersion;
        $this->terminalWidth = max(self::MIN_WIDTH, $terminalWidth);
    }

    /**
     * @param null|string $lockPath         absolute path of the analysed lock, null when unknown
     * @param string      $failOn           one of {@see FailOn::allowed()}
     * @param int         $terminalWidth    columns for `table`, raised to MIN_WIDTH when lower
     * @param null|string $projectDirectory absolute path of the directory lockrot runs in, null when unknown
     */
    public static function create(?string $lockPath, string $failOn, string $toolVersion = Version::STRING, int $terminalWidth = self::DEFAULT_WIDTH, ?string $projectDirectory = null): self
    {
        return self::forFailOn($lockPath, FailOn::parse($failOn), $toolVersion, $terminalWidth, $projectDirectory);
    }

    /**
     * {@see create()} with the threshold already parsed, so the run's gate and the formats share
     * one instance.
     */
    public static function forFailOn(?string $lockPath, FailOn $failOn, string $toolVersion = Version::STRING, int $terminalWidth = self::DEFAULT_WIDTH, ?string $projectDirectory = null): self
    {
        return new self($lockPath, $failOn, $toolVersion, $terminalWidth, $projectDirectory);
    }

    public static function unknown(): self
    {
        return new self(null, FailOn::none(), Version::STRING, self::DEFAULT_WIDTH, null);
    }

    public function lockPath(): ?string
    {
        return $this->lockPath;
    }

    /** The lock's name as an annotation shows it: docs/ci.md#where-annotations-point. */
    public function lockName(): string
    {
        return $this->lockName;
    }

    /** The directory that {@see lockName()} is relative to, spelled as given. Null when no lock is known. */
    public function lockDirectory(): ?string
    {
        return $this->lockDirectory;
    }

    public function failOn(): string
    {
        return $this->failOn->value();
    }

    public function toolVersion(): string
    {
        return $this->toolVersion;
    }

    public function terminalWidth(): int
    {
        return $this->terminalWidth;
    }

    /** A copy whose markdown documents keep within $bytes (`--markdown-max-bytes`, files only). */
    public function withMarkdownMaxBytes(?int $bytes): self
    {
        $copy = clone $this;
        $copy->markdownMaxBytes = $bytes;

        return $copy;
    }

    /** The byte budget of a markdown document, null for none. */
    public function markdownMaxBytes(): ?int
    {
        return $this->markdownMaxBytes;
    }

    /**
     * The findings that get a record, in the report's order: the graded ones, every one that fails
     * the gate (I15b), and with `unchecked` active every one with a missing check. Under `--all`,
     * every finding.
     *
     * @return list<Finding>
     */
    public function recordFindings(Report $report, bool $showAll): array
    {
        if ($showAll) {
            return $report->sorted();
        }
        $gate = $this->gateOf($report);
        $unchecked = $this->failOn->has(FailOn::KIND_UNCHECKED);
        $out = [];
        foreach ($report->sorted() as $finding) {
            $standing = $gate->standingOf($finding->package());
            if ($finding->isGraded() || ($standing !== null && $standing->fails()) || ($unchecked && $finding->checksMissing() !== [])) {
                $out[] = $finding;
            }
        }

        return $out;
    }

    /**
     * Whether the finding gets its own `lockrot/unchecked` record: `unchecked` is active, or the
     * format lists every finding, and the finding carries a missing check and no counted fact.
     */
    public function isUncheckedRecord(Finding $finding, bool $showAll = false): bool
    {
        return ($showAll || $this->failOn->has(FailOn::KIND_UNCHECKED))
            && $finding->flags()->countedMaintenance() === []
            && $finding->flags()->advisories() === []
            && $finding->checksMissing() !== [];
    }

    /**
     * The level of the run-level `advisories not checked` record, null when every lookup ran. It is
     * `error` only when a finding fails the `unchecked` value with S10 for its advisories: the gate
     * then fails on that gap. A skip, an accepted gap or a gap that the baseline knows is a
     * `warning` (SPEC §6.2).
     */
    public function advisoriesNotCheckedLevel(Report $report): ?string
    {
        $gate = $this->gateOf($report);
        $gap = false;
        foreach ($report->findings() as $finding) {
            if ($finding->securityStanding()->status() !== SecurityStanding::UNCHECKED) {
                continue;
            }
            $gap = true;
            $standing = $gate->standingOf($finding->package());
            if ($standing === null || !$standing->failsValue(FailOn::UNCHECKED)) {
                continue;
            }
            foreach ($finding->checksMissing() as $check) {
                if ($check->check() === 'advisories') {
                    return self::LEVEL_ERROR;
                }
            }
        }

        return $gap ? self::LEVEL_WARNING : null;
    }

    /**
     * The run's gate, which a report with run settings decides itself. A report without them, which
     * only a test or a caller outside the command builds, is judged on this context's fail-on.
     */
    public function gateOf(Report $report): Gate
    {
        return $report->gate() ?? Gate::ofFindings($report, $this->failOn);
    }

    /**
     * The run settings the report was told. A report without them is judged on this context's
     * fail-on, against the running PHP.
     */
    public function runOf(Report $report): RunSettings
    {
        return $report->run() ?? new RunSettings(null, null, null, RunSettings::SOURCE_RUNTIME, $this->lockPath, $this->failOn->value() === FailOn::NONE ? null : $this->failOn, RunSettings::SOURCE_OPTION, null);
    }
}
