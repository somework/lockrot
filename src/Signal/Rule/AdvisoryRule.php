<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Composer\Semver\VersionParser;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Security\Fix;
use Lockrot\Security\Severity;
use Lockrot\Signal\Data\AdvisoryData;
use Lockrot\Signal\Data\AdvisoryRow;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\ScoreModel;

/**
 * S9: the counted security advisories on the installed version, each with its fix from the release
 * scan (docs/verdicts.md#security-advisories).
 *
 * @internal
 */
final class AdvisoryRule implements SignalRule
{
    /** Advisories named in the summary before the rest are counted. */
    public const NAMED = 3;

    /** Packagist's severity scale, worst first. An advisory without one sorts last. */
    private const SEVERITY_RANK = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

    public function evaluate(PackageFacts $facts): ?Signal
    {
        $advisories = $facts->advisories();
        if ($advisories === []) {
            return null;
        }
        [$summary, $releasesRead] = self::summary($facts);
        $rows = self::rows($facts);
        $worst = Severity::worstOf(array_map(static fn (Advisory $advisory): string => Severity::fromComposer($advisory->severity())->bucket(), $advisories));
        $coverage = $facts->advisoryCoverage();

        return new Signal(\in_array($worst, [Severity::CRITICAL, Severity::HIGH], true) ? Signal::LEVEL_HIGH : Signal::LEVEL_WARN, $summary, new AdvisoryData(
            $rows,
            $releasesRead,
            $coverage !== null && $coverage->reason() === null
        ));
    }

    /**
     * S9's summary, with each advisory's first fixing release ({@see Fix::version()}), and whether
     * the releases were read.
     *
     * @return array{string, bool}
     */
    private static function summary(PackageFacts $facts): array
    {
        $advisories = self::worstFirst($facts->advisories());
        $count = \count($advisories);
        if ($count === 0) {
            return ['', false];
        }
        $labels = array_map(static fn (Advisory $advisory): string => $advisory->label(), \array_slice($advisories, 0, self::NAMED));
        $named = implode(', ', $labels);
        if ($count > self::NAMED) {
            $named .= \sprintf(' and %d more', $count - self::NAMED);
        }
        $summary = \sprintf(
            '%d security %s %s (%s)',
            $count,
            $count === 1 ? 'advisory affects' : 'advisories affect',
            $facts->package()->version(),
            $named
        );

        $fixedIn = [];
        foreach ($advisories as $advisory) {
            $version = self::fixOf($facts, $advisory)->version();
            if ($version !== null) {
                $fixedIn[$version] = ($fixedIn[$version] ?? 0) + 1;
            }
        }
        if ($fixedIn !== []) {
            $summary .= '; '.self::fixedClause($fixedIn, $count);
        }

        return [$summary, self::releasesRead($facts)];
    }

    /**
     * One row per counted advisory, in the tie-break order of the deciding advisory: points
     * descending, then the severity display order, then the id. The finding marks the deciding
     * row from its score ({@see \Lockrot\Verdict\Finding::toArray()}).
     *
     * @return list<AdvisoryRow>
     */
    private static function rows(PackageFacts $facts): array
    {
        $byId = [];
        foreach ($facts->advisories() as $advisory) {
            $byId[$advisory->id()] = [$advisory, self::fixOf($facts, $advisory)];
        }
        $rows = [];
        foreach ($byId as $id => [$advisory, $fix]) {
            $bucket = Severity::fromComposer($advisory->severity())->bucket();
            $rows[] = new AdvisoryRow(
                (string) $id,
                $advisory->cve(),
                self::title($advisory->cve(), $advisory->title()),
                $advisory->link(),
                $advisory->reportedAtText(),
                $bucket,
                $advisory->severity(),
                $advisory->affectedVersionsText(),
                ScoreModel::advisoryPoints($bucket, $fix->kind()),
                $fix
            );
        }
        usort($rows, static function (AdvisoryRow $a, AdvisoryRow $b): int {
            return $b->points() <=> $a->points()
                ?: array_search($a->severity(), Severity::DISPLAY_ORDER, true) <=> array_search($b->severity(), Severity::DISPLAY_ORDER, true)
                ?: strcmp($a->id(), $b->id());
        });

        return $rows;
    }

    private static function fixOf(PackageFacts $facts, Advisory $advisory): Fix
    {
        $fixes = $facts->fixes();

        return $fixes === null ? self::unknownFix($facts, $advisory) : $fixes->forAdvisory($advisory->id());
    }

    /** A fix the release scan did not judge: the scan runs only in a run that counts an advisory. */
    private static function unknownFix(PackageFacts $facts, Advisory $advisory): Fix
    {
        if ($advisory->affectedRange() === null) {
            return Fix::unknown(Fix::AFFECTED_RANGE_UNKNOWN);
        }

        return Fix::unknown($facts->package()->isFromComposerRepository() ? Fix::RELEASES_UNKNOWN : Fix::NOT_FROM_COMPOSER_REPOSITORY);
    }

    /** Trimmed, runs of blanks collapsed, a leading `<cve>: ` removed, so `{cve} {title}` never prints the CVE twice. */
    public static function title(?string $cve, ?string $title): ?string
    {
        if ($title === null) {
            return null;
        }
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($cve !== null && strpos($title, $cve.': ') === 0) {
            return substr($title, \strlen($cve) + 2);
        }

        return $title;
    }

    /**
     * @param array<string, int> $fixedIn the advisory count by fixing release, in the order of the
     *                                   first advisory it fixes. A release names its `fix.to_branch` too.
     */
    private static function fixedClause(array $fixedIn, int $count): string
    {
        if (\count($fixedIn) === 1 && array_sum($fixedIn) === $count) {
            return 'fixed in '.array_key_first($fixedIn);
        }
        $parts = [];
        foreach ($fixedIn as $version => $n) {
            $parts[] = \sprintf('%d fixed in %s', $n, $version);
        }

        return implode(', ', $parts);
    }

    /**
     * Whether the releases were compared with the advisory ranges: false without metadata, or for
     * an installed version that no parser reads.
     */
    private static function releasesRead(PackageFacts $facts): bool
    {
        if ($facts->metadata() === null) {
            return false;
        }
        try {
            (new VersionParser())->normalize($facts->package()->version());
        } catch (\UnexpectedValueException $e) {
            return false;
        }

        return true;
    }

    /**
     * Worst severity first, then the repository's own order (newest report first on Packagist),
     * so the three names that the summary carries are the three that matter most.
     *
     * @param list<Advisory> $advisories
     *
     * @return list<Advisory>
     */
    private static function worstFirst(array $advisories): array
    {
        $order = array_keys($advisories);
        usort($order, static function (int $a, int $b) use ($advisories): int {
            $rankA = self::SEVERITY_RANK[$advisories[$a]->severity() ?? ''] ?? \count(self::SEVERITY_RANK);
            $rankB = self::SEVERITY_RANK[$advisories[$b]->severity() ?? ''] ?? \count(self::SEVERITY_RANK);

            return $rankA <=> $rankB ?: $a <=> $b;
        });

        return array_map(static fn (int $i): Advisory => $advisories[$i], $order);
    }
}
