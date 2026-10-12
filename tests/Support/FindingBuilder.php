<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Data\Forge\ActivityLookup;
use Lockrot\Data\Forge\LocatedRepository;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Forge\RepositoryActivity;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Legacy\AdvisoryFacts013;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\AgeMeasure;
use Lockrot\Signal\AgeReading;
use Lockrot\Signal\Data\ArchivedData;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\Data\LeftBehindData;
use Lockrot\Signal\Data\NoPushData;
use Lockrot\Signal\Data\NoReleaseData;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Age;
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
    private ?AdvisoryFacts013 $advisoryFacts013 = null;
    private ?FlagSet $flags = null;
    private bool $maintenanceJudged = true;
    private bool $unscored = false;
    /** @var ?list<string> */
    private ?array $namedIn = null;
    private bool $composerJsonRead = true;

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

    /** report-1's S9 facts, which the priority and the no-fix clause read. */
    public function withAdvisoryFacts013(AdvisoryFacts013 $facts): self
    {
        $clone = clone $this;
        $clone->advisoryFacts013 = $facts;

        return $clone;
    }

    /**
     * The composer.json sections that name the package. Without a call, a direct finding is named in
     * `require` (`require-dev` for a packages-dev one), and any other in none.
     *
     * @param list<string> $namedIn
     */
    public function withNamedIn(array $namedIn): self
    {
        $clone = clone $this;
        $clone->namedIn = $namedIn;

        return $clone;
    }

    public function withComposerJsonRead(bool $read): self
    {
        $clone = clone $this;
        $clone->composerJsonRead = $read;

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
        $namedIn = $this->namedIn ?? (\count($this->chain) === 1 ? [$this->dev ? ProjectConfig::REQUIRE_DEV : ProjectConfig::REQUIRE] : []);
        $finding = (new Finding($this->package, $this->version, $this->verdict, $this->signals, $this->chain, $this->allowlistReason, $this->dataDate, $this->note, $this->dev, $this->directDependents, $this->libyears, $this->origin, $this->replacementNamedBy, $this->advisoryFacts013))
            ->withComposerJson($this->composerJsonRead, $namedIn);
        if ($this->flags !== null) {
            return $finding->withFlags($this->flags, $this->maintenanceJudged)->withDetails(self::detailsOf($finding, $this->maintenanceJudged));
        }
        if ($this->unscored) {
            return $finding;
        }
        $entry = $this->allowlistReason === null ? null : new AllowlistEntry($this->package, null, $this->allowlistReason, null, AllowlistEntry::BY_PROJECT);
        $flags = FlagSet::fromSignals($this->signals, $entry, []);
        if ($flags->fired() === [] && isset(self::RAISED_BY[$this->verdict])) {
            $flags = FlagSet::fromSignals(array_map(static fn (array $raise): Signal => new Signal($raise[1], 'set by the verdict', SignalDataStub::of($raise[0], [])), self::RAISED_BY[$this->verdict]), $entry, []);
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
            $skipped[] = new Check('release_metadata', $status, [Signal::S2, Signal::S8]);
        } elseif (ReleaseBranch::of($finding->version()) === null) {
            $skipped[] = new Check('release_branch', 'branch_snapshot', [Signal::S8]);
        }
        $reason = $finding->allowlistReason();
        $entry = $reason === null ? null : new AllowlistEntry($finding->package(), null, $reason, null, AllowlistEntry::BY_PROJECT);

        $activity = self::activityOf($finding);

        return new FindingDetails($status, $status === 'unavailable' ? 'fetch_failed' : null, null, $skipped, ['requires' => null, 'target_runs' => null, 'project_allows' => null], $entry, 'complete', null, [], null, $activity, self::ageOf($finding, $status, $activity));
    }

    /**
     * The age readings that the finding's S2, S4 and S8 quote, at the signal's level. A reading that
     * no signal quotes is unmeasured, for the reason that the metadata status or the activity gives.
     */
    public static function ageOf(Finding $finding, string $metadataStatus, ActivityLookup $activity): Age
    {
        $levels = [];
        foreach ($finding->signals() as $signal) {
            $levels[$signal->id()] = $signal->level();
        }
        $measured = static fn (string $at, ?float $years, ?string $version, ?string $datedBy, ?string $level, string $kind): AgeReading => AgeReading::measured(new \DateTimeImmutable($at), (int) round(($years ?? 0.0) * 10), $years ?? 0.0, $version, $datedBy, $level, $kind);
        $metadataReason = $metadataStatus === 'read' ? null : $metadataStatus;
        $s2 = $finding->signalData(NoReleaseData::class);
        $s4 = $finding->signalData(NoPushData::class);
        $s8 = $finding->signalData(LeftBehindData::class);
        $snapshot = ReleaseBranch::of($finding->version()) === null;

        return new Age(
            AgeReading::unmeasuredBecause($snapshot ? AgeMeasure::BRANCH_SNAPSHOT : AgeMeasure::UNDATED),
            $s2 === null ? AgeReading::unmeasuredBecause($metadataReason ?? AgeMeasure::UNDATED_RELEASES) : $measured($s2->lastRelease(), $s2->years(), $s2->lastVersion(), $s2->datedBy(), $levels[Signal::S2], AgeReading::RELEASE),
            $s8 === null ? AgeReading::unmeasuredBecause($metadataReason ?? ($snapshot ? AgeMeasure::BRANCH_SNAPSHOT : AgeMeasure::NO_BRANCH_ROW)) : $measured($s8->branchLastRelease(), $s8->years(), $s8->branchLastVersion(), $s8->datedBy(), $levels[Signal::S8], AgeReading::RELEASE),
            $s4 === null ? AgeReading::unmeasuredBecause($activity->reason() ?? AgeMeasure::UNDATED) : $measured($s4->lastPush(), $s4->years(), null, null, $levels[Signal::S4], AgeReading::PUSH)
        );
    }

    /** No reading taken, for details whose test reads no age. */
    public static function unmeasuredAge(): Age
    {
        return new Age(AgeReading::unmeasuredBecause(AgeMeasure::UNDATED), AgeReading::unmeasuredBecause(AgeMeasure::UNDATED_RELEASES), AgeReading::unmeasuredBecause(AgeMeasure::NO_BRANCH_ROW), AgeReading::unmeasuredBecause(AgeMeasure::NO_REPOSITORY));
    }

    /** The lookup of a package with no repository URL, for details whose test reads no activity. */
    public static function unread(): ActivityLookup
    {
        return ActivityLookup::notRead(ActivityLookup::NO_REPOSITORY, LocatedRepository::none(null));
    }

    /** A read on github.com, as the skips of {@see detailsOf()} imply: archived exactly when S3 fired, pushed when S4 dates it. */
    public static function activityOf(Finding $finding): ActivityLookup
    {
        $s4 = $finding->signalData(NoPushData::class);
        $pushedAt = $s4 === null ? null : new \DateTimeImmutable($s4->lastPush());
        $read = new RepositoryActivity(new RepoRef(RepoRef::GITHUB, 'github.com', $finding->package()), $finding->signalData(ArchivedData::class) !== null, $pushedAt, $finding->dataDate() ?? new \DateTimeImmutable('2026-09-14T00:00:00+00:00'));

        return ActivityLookup::read($read);
    }
}
