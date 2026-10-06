<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Baseline\BaselineComparison;
use Lockrot\Filesystem\Path;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
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
     * @param null|string $lockPath         absolute path of the analysed lock
     * @param string      $failOn           one of {@see FailOn::allowed()}
     * @param int         $terminalWidth    columns for `table`, raised to MIN_WIDTH when lower
     * @param null|string $projectDirectory absolute path that {@see lockName()} is relative to
     */
    public static function create(?string $lockPath, string $failOn, string $toolVersion = Version::STRING, int $terminalWidth = self::DEFAULT_WIDTH, ?string $projectDirectory = null): self
    {
        return self::forFailOn($lockPath, FailOn::fromString($failOn), $toolVersion, $terminalWidth, $projectDirectory);
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

    /** The directory that {@see lockName()} is relative to, spelled as given. */
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

    /**
     * The level of a finding in every format that marks one:
     * docs/ci.md#how-each-format-marks-a-finding. GithubFormatter spells `note` as `notice`.
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
