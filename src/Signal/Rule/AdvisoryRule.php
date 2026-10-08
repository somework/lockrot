<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Legacy\AdvisoryFacts013;
use Lockrot\Security\Fix;
use Lockrot\Security\Holder;
use Lockrot\Security\Severity;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\ScoreModel;

/**
 * S9: the counted security advisories on the installed version, each with its fix from the release
 * scan (docs/verdicts.md#security-advisories). The summary and {@see legacy()} keep report-1's
 * reading until its readers move.
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
        $legacy = self::legacy($facts);
        $rows = self::rows($facts);
        $worst = Severity::worstOf(array_map(static fn (Advisory $advisory): string => Severity::fromComposer($advisory->severity())->bucket(), $advisories));
        $coverage = $facts->advisoryCoverage();

        return new Signal(Signal::S9, \in_array($worst, [Severity::CRITICAL, Severity::HIGH], true) ? Signal::LEVEL_HIGH : Signal::LEVEL_WARN, $legacy[0], [
            'advisories' => $rows,
            'releases_read' => $legacy[1]->releasesRead(),
            'complete' => $coverage !== null && $coverage->reason() === null,
        ]);
    }

    /**
     * report-1's S9 facts and summary: per advisory the highest tag outside its range, on the
     * installed branch or in the package.
     *
     * @return array{string, AdvisoryFacts013}
     */
    public static function legacy(PackageFacts $facts): array
    {
        $advisories = self::worstFirst($facts->advisories());
        $count = \count($advisories);
        if ($count === 0) {
            return ['', AdvisoryFacts013::none()];
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

        [$onBranch, $inPackage, $read] = self::fixCandidates($facts);
        $rows = [];
        $fixedBy = [];
        foreach ($advisories as $advisory) {
            $row = $advisory->toArray();
            $row['fixed_by'] = null;
            $row['fixed_on_branch'] = false;
            if ($onBranch !== null && $advisory->affects($onBranch['normalized']) === false) {
                $row['fixed_by'] = $onBranch['pretty'];
                $row['fixed_on_branch'] = true;
            } elseif ($inPackage !== null && $advisory->affects($inPackage['normalized']) === false) {
                $row['fixed_by'] = $inPackage['pretty'];
            }
            if ($row['fixed_by'] !== null) {
                $fixedBy[$row['fixed_by']] = ($fixedBy[$row['fixed_by']] ?? 0) + 1;
            }
            $rows[] = $row;
        }
        if ($fixedBy !== []) {
            $summary .= '; '.self::fixedClause($fixedBy, $count);
        }

        return [$summary, new AdvisoryFacts013($rows, $read)];
    }

    /**
     * One row per counted advisory, in the tie-break order of the deciding advisory: points
     * descending, then the severity display order, then the id. The finding marks the deciding
     * row from its score ({@see \Lockrot\Verdict\Finding::toArray()}).
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(PackageFacts $facts): array
    {
        $fixes = $facts->fixes();
        $byId = [];
        foreach ($facts->advisories() as $advisory) {
            $fix = $fixes === null ? self::unknownFix($facts, $advisory) : $fixes->forAdvisory($advisory->id());
            $byId[$advisory->id()] = [$advisory, $fix];
        }
        $rows = [];
        foreach ($byId as $id => [$advisory, $fix]) {
            $bucket = Severity::fromComposer($advisory->severity())->bucket();
            $raw = $advisory->toArray();
            $rows[] = [
                'id' => (string) $id,
                'cve' => $advisory->cve(),
                'title' => self::title($advisory->cve(), $advisory->title()),
                'link' => $advisory->link(),
                'reported_at' => $raw['reported_at'],
                'severity' => $bucket,
                'severity_published' => $advisory->severity(),
                'affected_versions' => $raw['affected_versions'],
                'counted' => true,
                'points' => ScoreModel::advisoryPoints($bucket, $fix->kind()),
                'fix' => self::fix($fix),
                'baseline' => null,
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return $b['points'] <=> $a['points']
                ?: array_search($a['severity'], Severity::DISPLAY_ORDER, true) <=> array_search($b['severity'], Severity::DISPLAY_ORDER, true)
                ?: strcmp($a['id'], $b['id']);
        });

        return $rows;
    }

    /** A fix the release scan did not judge: the scan runs only in a run that counts an advisory. */
    private static function unknownFix(PackageFacts $facts, Advisory $advisory): Fix
    {
        if ($advisory->affectedRange() === null) {
            return Fix::unknown(Fix::AFFECTED_RANGE_UNKNOWN);
        }

        return Fix::unknown($facts->package()->isFromComposerRepository() ? Fix::RELEASES_UNKNOWN : Fix::NOT_FROM_COMPOSER_REPOSITORY);
    }

    /** @return array<string, mixed> */
    private static function fix(Fix $fix): array
    {
        return [
            'kind' => $fix->kind(),
            'to_branch' => $fix->toBranch(),
            'version' => $fix->version(),
            'newest' => $fix->newest(),
            'on_installed_branch' => $fix->onInstalledBranch(),
            'php' => $fix->php(),
            'held_by' => array_map([self::class, 'heldBy'], $fix->heldBy()),
            'reason' => $fix->reason(),
        ];
    }

    /**
     * A `held_by[]` entry. A root link names no package: the holder is composer.json. `holder`
     * stays null until the report fills it from the holder's own finding.
     *
     * @return array<string, mixed>
     */
    public static function heldBy(Holder $holder): array
    {
        $root = $holder->source() === Holder::ROOT;

        return [
            'source' => $holder->source(),
            'package' => $root ? null : $holder->package(),
            'version' => $root ? null : $holder->version(),
            'link' => $holder->link(),
            'constraint' => $holder->constraint(),
            'holder' => null,
        ];
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
     * @param array<string, int> $fixedBy
     */
    private static function fixedClause(array $fixedBy, int $count): string
    {
        if (\count($fixedBy) === 1 && array_sum($fixedBy) === $count) {
            return 'fixed by '.array_key_first($fixedBy);
        }
        $parts = [];
        foreach ($fixedBy as $version => $n) {
            $parts[] = \sprintf('%d fixed by %s', $n, $version);
        }

        return implode(', ', $parts);
    }

    /**
     * The releases that a fix is looked for in: the highest stable tag on the installed version's
     * branch (none for a branch snapshot or an unlisted branch) and the package's highest stable tag.
     * Either counts only above the installed version: an older tag that an advisory range spares is
     * not a fix. For a branch snapshot, the package's highest stable tag counts as it is. The third
     * element is false when nothing was compared: no metadata, or an installed version that no
     * parser reads.
     *
     * @return array{?array{normalized: string, pretty: string}, ?array{normalized: string, pretty: string}, bool}
     */
    private static function fixCandidates(PackageFacts $facts): array
    {
        $metadata = $facts->metadata();
        if ($metadata === null) {
            return [null, null, false];
        }
        $version = $facts->package()->version();
        $installed = null;
        if (VersionParser::parseStability($version) !== 'dev') {
            try {
                $installed = (new VersionParser())->normalize($version);
            } catch (\UnexpectedValueException $e) {
                return [null, null, false];
            }
        }
        $byBranch = $metadata->latestStableByBranch();
        $branch = ReleaseBranch::of($version);
        $onBranch = $branch !== null && isset($byBranch[$branch]) ? $byBranch[$branch]['highest'] : null;
        $inPackage = null;
        foreach ($byBranch as $release) {
            if ($inPackage === null || Comparator::greaterThan($release['highest']['normalized'], $inPackage['normalized'])) {
                $inPackage = $release['highest'];
            }
        }

        return [self::above($onBranch, $installed), self::above($inPackage, $installed), true];
    }

    /**
     * @param ?array{normalized: string, pretty: string, at: ?\DateTimeImmutable} $tag
     * @param ?string                                                             $installed normalized, null for a branch snapshot that no tag is compared with
     *
     * @return ?array{normalized: string, pretty: string}
     */
    private static function above(?array $tag, ?string $installed): ?array
    {
        if ($tag === null || ($installed !== null && !Comparator::greaterThan($tag['normalized'], $installed))) {
            return null;
        }

        return ['normalized' => $tag['normalized'], 'pretty' => $tag['pretty']];
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
