<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;

/**
 * S9: the security advisories that affect the installed version, each with the release that fixes
 * it (docs/verdicts.md#security-advisories). The signal never decides a verdict.
 * {@see \Lockrot\Verdict\Finding} decides which of the two fixing releases counts and raises the
 * priority.
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
        $count = \count($advisories);
        $advisories = self::worstFirst($advisories);
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

        [$onBranch, $inPackage, $read] = $this->fixCandidates($facts);
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

        return new Signal(Signal::S9, Signal::LEVEL_WARN, $summary, ['advisories' => $rows, 'releases_read' => $read]);
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
    private function fixCandidates(PackageFacts $facts): array
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
