<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Verdict;

/**
 * Builds a {@see Finding} for a test. The defaults: `vendor/pkg` 1.0.0, ok, no
 * signals, a chain of the package alone, no data date, flags from the signals or the verdict ({@see build()}).
 * A finding built {@see withoutFlags()} throws on grade(), lead(), isGraded() and score(). Every
 * optional argument keeps the constructor's own default. Each with*() returns a new builder.
 */
final class FindingBuilder
{
    private string $package = 'vendor/pkg';
    private string $version = '1.0.0';
    private string $verdict = Verdict::OK;
    /** @var list<Signal> */
    private array $signals = [];
    /** @var list<string> */
    private array $chain = ['vendor/pkg'];
    private ?string $allowlistReason = null;
    private ?\DateTimeImmutable $dataDate = null;
    private ?string $note = null;
    private bool $dev = false;
    /** @var list<string> */
    private array $directDependents = [];
    private ?LibyearsMeasurement $libyears = null;
    private ?PackageOrigin $origin = null;
    private ?string $replacementNamedBy = null;
    private ?FlagSet $flags = null;
    private bool $maintenanceJudged = true;
    private bool $unscored = false;

    /**
     * The signals that raise each report-1 verdict word as its flag, for a finding a test builds
     * by its verdict alone: its lead is then that word, as the analyzer's would be.
     */
    private const RAISED_BY = [
        Verdict::ABANDONED => [[Signal::S1, Signal::LEVEL_HIGH]],
        Verdict::SILENT => [[Signal::S2, Signal::LEVEL_HIGH], [Signal::S4, Signal::LEVEL_HIGH]],
        Verdict::PINNED => [[Signal::S6, Signal::LEVEL_WARN]],
        Verdict::LEFT_BEHIND => [[Signal::S8, Signal::LEVEL_WARN]],
        Verdict::OLD_PROMISE => [[Signal::S5, Signal::LEVEL_WARN]],
        Verdict::STALE => [[Signal::S2, Signal::LEVEL_WARN]],
    ];

    public function withPackage(string $package): self
    {
        $clone = clone $this;
        $clone->package = $package;

        return $clone;
    }

    public function withVersion(string $version): self
    {
        $clone = clone $this;
        $clone->version = $version;

        return $clone;
    }

    public function withVerdict(string $verdict): self
    {
        $clone = clone $this;
        $clone->verdict = $verdict;

        return $clone;
    }

    /** @param list<Signal> $signals */
    public function withSignals(array $signals): self
    {
        $clone = clone $this;
        $clone->signals = $signals;

        return $clone;
    }

    /** @param list<string> $chain */
    public function withChain(array $chain): self
    {
        $clone = clone $this;
        $clone->chain = $chain;

        return $clone;
    }

    public function withAllowlistReason(?string $allowlistReason): self
    {
        $clone = clone $this;
        $clone->allowlistReason = $allowlistReason;

        return $clone;
    }

    public function withDataDate(?\DateTimeImmutable $dataDate): self
    {
        $clone = clone $this;
        $clone->dataDate = $dataDate;

        return $clone;
    }

    public function withNote(?string $note): self
    {
        $clone = clone $this;
        $clone->note = $note;

        return $clone;
    }

    public function withDev(bool $dev): self
    {
        $clone = clone $this;
        $clone->dev = $dev;

        return $clone;
    }

    /** @param list<string> $directDependents */
    public function withDirectDependents(array $directDependents): self
    {
        $clone = clone $this;
        $clone->directDependents = $directDependents;

        return $clone;
    }

    public function withLibyears(?LibyearsMeasurement $libyears): self
    {
        $clone = clone $this;
        $clone->libyears = $libyears;

        return $clone;
    }

    public function withOrigin(?PackageOrigin $origin): self
    {
        $clone = clone $this;
        $clone->origin = $origin;

        return $clone;
    }

    public function withReplacementNamedBy(?string $replacementNamedBy): self
    {
        $clone = clone $this;
        $clone->replacementNamedBy = $replacementNamedBy;

        return $clone;
    }

    public function withFlags(?FlagSet $flags, bool $maintenanceJudged = true): self
    {
        $clone = clone $this;
        $clone->flags = $flags;
        $clone->maintenanceJudged = $maintenanceJudged;

        return $clone;
    }

    /** A finding built without its flags, which has no score: what a caller that skips the analyzer gets. */
    public function withoutFlags(): self
    {
        $clone = clone $this;
        $clone->unscored = true;

        return $clone;
    }

    /**
     * Without {@see withFlags()}, the flags come from the signals, else from a report-1 verdict
     * word ({@see RAISED_BY}). An allowlist reason accepts the whole package, no advisory counts and
     * the verdict `unknown` means the metadata was not read.
     */
    public function build(): Finding
    {
        $finding = new Finding($this->package, $this->version, $this->verdict, $this->signals, $this->chain, $this->allowlistReason, $this->dataDate, $this->note, $this->dev, $this->directDependents, $this->libyears, $this->origin, $this->replacementNamedBy);
        if ($this->flags !== null) {
            return $finding->withFlags($this->flags, $this->maintenanceJudged)->withDetails(self::detailsOf($finding, $this->maintenanceJudged));
        }
        if ($this->unscored) {
            return $finding;
        }
        $entry = $this->allowlistReason === null ? null : new AllowlistEntry($this->package, null, $this->allowlistReason, null, AllowlistEntry::BY_PROJECT);
        $flags = FlagSet::fromSignals($this->signals, $entry, []);
        if ($flags->fired() === [] && isset(self::RAISED_BY[$this->verdict])) {
            $flags = FlagSet::fromSignals(array_map(static fn (array $raise): Signal => new Signal($raise[0], $raise[1], 'set by the verdict'), self::RAISED_BY[$this->verdict]), $entry, []);
        }

        return $finding->withFlags($flags, $this->verdict !== Verdict::UNKNOWN)->withDetails(self::detailsOf($finding, $this->verdict !== Verdict::UNKNOWN));
    }

    /**
     * The details of a finding whose advisory lookup answered and whose release scan found no
     * branch: the metadata is read exactly when maintenance is judged.
     */
    public static function detailsOf(Finding $finding, bool $maintenanceJudged): FindingDetails
    {
        $status = $maintenanceJudged ? 'read' : (!$finding->isFromComposerRepository() ? 'not_from_composer_repository' : ($finding->note() === 'not found in the repository' ? 'not_found' : 'unavailable'));
        $skipped = [];
        if ($status !== 'read') {
            $skipped[] = ['check' => 'release_metadata', 'reason' => $status, 'blocks' => [Signal::S2, Signal::S8]];
        } elseif (ReleaseBranch::of($finding->version()) === null) {
            $skipped[] = ['check' => 'release_branch', 'reason' => 'branch_snapshot', 'blocks' => [Signal::S8]];
        }
        $reason = $finding->allowlistReason();
        $entry = $reason === null ? null : new AllowlistEntry($finding->package(), null, $reason, null, AllowlistEntry::BY_PROJECT);

        return new FindingDetails($status, $status === 'unavailable' ? 'fetch_failed' : null, null, $skipped, ['requires' => null, 'target_runs' => null, 'project_allows' => null], $entry, 'complete', null, [], null);
    }
}
