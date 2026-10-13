<?php

declare(strict_types=1);

namespace Lockrot\Baseline;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunNote;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Score\Standing;
use Lockrot\Score\Term;
use Lockrot\Verdict\Finding;

/**
 * A run's findings compared with the committed baseline-2 file: docs/baseline.md, "Reading a
 * baseline". A graded finding without an entry is `new`. A score-0 finding without an entry stands
 * nowhere, so its standing is null.
 *
 * @internal
 */
final class BaselineComparison
{
    public const KNOWN = Standing::KNOWN;
    /** Named with a suffix because `new` alone reads as the keyword at every call site. */
    public const NEW_FINDING = Standing::NEW_PACKAGE;
    public const WORSENED = Standing::WORSENED;

    private const STALE_NAMES_IN_NOTE = 5;

    private string $path;
    /** @var array<string, Standing> package name => standing, for every finding that stands against the file */
    private array $standings;
    /** @var list<string> baselined package names absent from the analysed lock, sorted */
    private array $stale;
    /** @var list<RunNote> */
    private array $notes;

    /**
     * @param array<string, Standing> $standings
     * @param list<string>            $stale
     * @param list<RunNote>           $notes
     */
    private function __construct(string $path, array $standings, array $stale, array $notes)
    {
        $this->path = $path;
        $this->standings = $standings;
        $this->stale = $stale;
        $this->notes = $notes;
    }

    /**
     * The fix comparison runs only when the run's target PHP and fix model equal the file's, else a
     * note says why it did not.
     *
     * @param list<string> $presentPackages every package name in the lock, `ok` ones included: a
     *                                      baselined `ok` package is present, not stale
     */
    public static function compare(Baseline $baseline, Report $report, string $displayPath, array $presentPackages): self
    {
        $run = $report->run();
        $target = $run === null ? PhpReleaseDates::minorOf(\PHP_VERSION) : $run->targetPhp();
        $source = $run === null ? RunSettings::SOURCE_RUNTIME : $run->targetPhpSource();
        $notes = [];
        if ($baseline->targetPhp() !== $target) {
            $notes[] = RunNote::baselineTargetDiffers($baseline->targetPhp(), $baseline->targetPhpSource(), $target, $source);
        }
        if ($baseline->fixModel() !== RunSettings::FIX_MODEL) {
            $notes[] = RunNote::baselineFixModelDiffers($baseline->fixModel(), RunSettings::FIX_MODEL);
        }
        $compareFixes = $notes === [];
        $standings = [];
        foreach ($report->findings() as $finding) {
            $standing = self::compareFinding($baseline, $finding, $compareFixes);
            if ($standing !== null) {
                $standings[$finding->package()] = $standing;
            }
        }

        $present = array_fill_keys($presentPackages, true);
        // No sort: Baseline::packages() returns name order and the filter keeps it.
        $stale = [];
        foreach ($baseline->packages() as $package) {
            if (!isset($present[$package])) {
                $stale[] = $package;
            }
        }

        return new self($displayPath, $standings, $stale, $notes);
    }

    private static function compareFinding(Baseline $baseline, Finding $finding, bool $compareFixes): ?Standing
    {
        $counted = array_map(static fn (Term $term): string => $term->flag(), $finding->score()->maintenanceTerms());
        $advisories = $finding->flags()->advisories();
        $entry = $baseline->entryFor($finding->package());
        if ($entry !== null) {
            return Standing::against($entry, $baseline->targetPhp(), $baseline->fixModel(), $counted, $advisories, $compareFixes);
        }

        return $finding->isGraded() ? Standing::newPackage($counted, $advisories) : null;
    }

    public function standingOf(string $package): ?Standing
    {
        return $this->standings[$package] ?? null;
    }

    public function isKnown(string $package): bool
    {
        return $this->statusOf($package) === self::KNOWN;
    }

    /** Null when the finding does not stand against the file. */
    public function statusOf(string $package): ?string
    {
        $standing = $this->standingOf($package);

        return $standing === null ? null : $standing->status();
    }

    /** The recorded lead: the first flag of the entry, null without an entry or for an advisory-only one. */
    public function previousVerdictOf(string $package): ?string
    {
        $standing = $this->standingOf($package);

        return $standing === null ? null : $standing->recordedLead();
    }

    public function knownCount(): int
    {
        return $this->countOf(self::KNOWN);
    }

    public function newCount(): int
    {
        return $this->countOf(self::NEW_FINDING);
    }

    public function worsenedCount(): int
    {
        return $this->countOf(self::WORSENED);
    }

    private function countOf(string $status): int
    {
        return \count(array_filter($this->standings, static fn (Standing $standing): bool => $standing->status() === $status));
    }

    /** @return list<string> */
    public function stale(): array
    {
        return $this->stale;
    }

    /** @return list<RunNote> `baseline_target_differs` and `baseline_fix_model_differs`, when they apply */
    public function notes(): array
    {
        return $this->notes;
    }

    public function summaryLine(): string
    {
        return \sprintf(
            'baseline: %d known · %d new · %d worsened · %d stale (%s)',
            $this->knownCount(),
            $this->newCount(),
            $this->worsenedCount(),
            \count($this->stale),
            $this->path
        );
    }

    public function staleNote(): ?string
    {
        $total = \count($this->stale);
        if ($total === 0) {
            return null;
        }

        $names = \array_slice($this->stale, 0, self::STALE_NAMES_IN_NOTE);
        $listed = implode(', ', $names);
        if ($total > \count($names)) {
            $listed .= \sprintf(' … and %d more', $total - \count($names));
        }

        return \sprintf(
            'baseline lists %d %s no longer in composer.lock: %s',
            $total,
            $total === 1 ? 'package' : 'packages',
            $listed
        );
    }

    /** @return array{path: string, known: int, new: int, worsened: int, stale: list<string>} */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'known' => $this->knownCount(),
            'new' => $this->newCount(),
            'worsened' => $this->worsenedCount(),
            'stale' => $this->stale,
        ];
    }
}
