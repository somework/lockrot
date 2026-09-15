<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Config\LockrotConfig;
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
     * colour a reviewer sees matches the exit code the same run produces: anything that would make
     * `composer lockrot` exit 1 is an error, anything else flagged is a warning, and the rows that
     * only appear under --all are notes.
     *
     * SARIF spells the third level `note` (its own enum); the GitHub workflow command for it is
     * `notice`, which GithubFormatter translates.
     */
    public function levelOf(string $verdict): string
    {
        if ($this->failOn !== LockrotConfig::FAIL_ON_NONE && Verdict::severity($verdict) >= Verdict::severity($this->failOn)) {
            return self::LEVEL_ERROR;
        }

        return Verdict::flagged($verdict) ? self::LEVEL_WARNING : self::LEVEL_NOTE;
    }
}
