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
 * S9: security advisories that affect the installed version — what `composer audit` reports,
 * carried on the finding so the report can say what lockrot alone knows: whether a fix is coming.
 * The signal never decides a verdict; {@see \Lockrot\Verdict\Finding} raises the priority when the
 * verdict says nobody will publish the fix.
 *
 * Each advisory is checked against two releases the repository already lists: the highest stable
 * tag on the installed version's branch, and the highest stable tag of the package. One that
 * neither affects is already fixed — by the branch's tag when that is enough (a `composer update`
 * inside the constraint gets it), else by the package's (a new major). "No fix expected" is then
 * said only of the advisories nothing listed fixes; the finding decides which of the two releases
 * counts, since a left-behind branch will not see the package's fix land on it.
 *
 * @internal
 */
final class AdvisoryRule implements SignalRule
{
    /** Advisories named in the summary before the rest are counted. */
    public const NAMED = 3;

    /** Packagist's severity scale, worst first; an advisory without one sorts last. */
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

        [$onBranch, $inPackage] = $this->fixCandidates($facts);
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

        return new Signal(Signal::S9, Signal::LEVEL_WARN, $summary, ['advisories' => $rows]);
    }

    /**
     * `fixed by 6.3.0` when every advisory is, else one count per fixing release, the branch's
     * first: `1 fixed by v3.4.47, 3 fixed by v8.1.7`.
     *
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
     * The two releases a fix is looked for in: the highest stable tag on the installed version's
     * branch (none for a branch snapshot, or a branch the repository does not list) and the
     * package's highest stable tag across branches. When the two are one release the second is
     * checked to no effect: what the first did not fix, the same tag does not fix either.
     *
     * Either counts only above the installed version. The repository can list nothing newer than
     * what is installed — a lock written against a tag since deleted, a pre-release ahead of every
     * stable tag — and an advisory whose range spares an older tag is not fixed by going back to
     * it. A branch snapshot is above no tag and below none; the package's highest tag stands for it
     * as it is.
     *
     * @return array{?array{normalized: string, pretty: string}, ?array{normalized: string, pretty: string}}
     */
    private function fixCandidates(PackageFacts $facts): array
    {
        $metadata = $facts->metadata();
        if ($metadata === null) {
            return [null, null];
        }
        $version = $facts->package()->version();
        $installed = null;
        if (VersionParser::parseStability($version) !== 'dev') {
            try {
                $installed = (new VersionParser())->normalize($version);
            } catch (\UnexpectedValueException $e) {
                return [null, null];
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

        return [self::above($onBranch, $installed), self::above($inPackage, $installed)];
    }

    /**
     * The tag as a fix candidate, null when there is none or it is not above the installed version.
     *
     * @param ?array{normalized: string, pretty: string, at: ?\DateTimeImmutable} $tag
     * @param ?string                                                             $installed normalized; null for a branch snapshot, which no tag is compared with
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
     * Severity first, worst on top, then the repository's own order (newest report first on
     * Packagist) — so the three names the line carries are the three that matter most.
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
