<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\FlagTotals;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\Report2Root;
use Lockrot\Analyzer\SecurityTotals;
use Lockrot\Data\Advisory\AdvisoryRepository;
use Lockrot\Security\Severity;
use Lockrot\Verdict\ScoreModel;
use Lockrot\Verdict\ShellCommand;

/**
 * The run footer lines of the text formats.
 * Each line renders root fields of report-2 and `run`; a line with no content is null. $name
 * writes a package name: markdown puts it in a code span.
 *
 * @internal
 */
final class RunFooter
{
    private const FIX_WORDS = ['update' => 'composer update', 'upgrade' => 'upgrade', 'raise-php' => 'php floor raise', 'unknown' => 'fix unknown', 'blocked' => 'blocked', 'none' => 'no fix'];
    private const LOOKUP_WORDS = ['partial' => 'lookup partial', 'not_run' => 'not checked'];
    private const REPOSITORY_WORDS = ['transport' => 'network error', 'invalid_response' => 'unreadable answer'];
    private const NAMES_SHOWN = 3;

    /** `critical 3 · high 2 · low 2`: the grades with a finding, in verdict order. */
    public static function gradeCounts(Report $report): string
    {
        $counts = $report->byGrade();
        $parts = [];
        foreach (ScoreModel::GRADES as $grade) {
            if ($counts[$grade] > 0) {
                $parts[] = $grade.' '.$counts[$grade];
            }
        }

        return implode(' · ', $parts);
    }

    /** `7 packages checked · 7 flagged: critical 3 · …`. */
    public static function counts(Report $report): string
    {
        $flagged = \count($report->graded());

        return self::nOf($report->packagesChecked(), 'package checked', 'packages checked')
            .' · '.($flagged === 0 ? 'nothing flagged' : $flagged.' flagged: '.self::gradeCounts($report));
    }

    /** `flags: abandoned 1 · … · 4 packages carry more than one`. */
    public static function flags(Report $report): ?string
    {
        $totals = FlagTotals::of($report->sorted());
        $parts = [];
        foreach (ScoreModel::FLAG_ORDER as $flag) {
            if ($totals->carrying($flag) > 0) {
                $parts[] = $flag.' '.$totals->carrying($flag);
            }
        }
        if ($parts === []) {
            return null;
        }
        $multi = \count(array_filter($report->graded(), static fn ($finding): bool => \count($finding->flagIds()) >= 2));
        if ($multi > 0) {
            $parts[] = $multi.' '.($multi === 1 ? 'package carries' : 'packages carry').' more than one';
        }

        return 'flags: '.implode(' · ', $parts);
    }

    /**
     * The `depth:` line of root `rot_depth`.
     *
     * @param callable(string): string $name
     */
    public static function depth(Report $report, callable $name): ?string
    {
        $depth = $report->rotDepth();
        $flagged = $depth->flagged();
        if ($flagged === 0) {
            return null;
        }
        $lockOnly = $depth->unmeasuredNoComposerJson();
        if ($lockOnly > 0) {
            return 'depth: composer.json not read, so depth is not measured for '.self::nOf($lockOnly, 'flagged package', 'flagged packages');
        }
        $unnamed = $depth->notInComposerJson();
        $parts = ['depth: '.($unnamed === 0
            ? 'every flagged package is named in composer.json'
            : $unnamed.' of '.self::nOf($flagged, 'flagged package', 'flagged packages').' '.($unnamed === 1 ? 'is' : 'are').' named by no line of composer.json')];
        foreach ($depth->byDepth() as $hops => $count) {
            if ($count > 0) {
                $parts[] = ($hops === 0 ? 'direct' : self::nOf($hops, 'hop', 'hops')).' '.$count;
            }
        }
        if ($depth->unreached() > 0) {
            $parts[] = 'unreached '.$depth->unreached();
        }
        if ($report->includesDev()) {
            $parts[] = $depth->development().' in packages-dev';
        }
        $deepest = $depth->deepest();
        if ($deepest !== []) {
            $names = array_map(static fn ($finding): string => $name($finding->package()), $deepest);
            $parts[] = 'deepest: '.self::names($names).', '.self::nOf((int) $depth->maxDepth(), 'hop', 'hops').' ('.implode(' › ', array_map($name, $deepest[0]->chain())).')';
        }
        $notWalked = $depth->requireDevNotWalked();
        if ($notWalked > 0) {
            $parts[] = $notWalked.' more '.($notWalked === 1 ? 'is' : 'are').' named only in require-dev, which this run did not walk (pass --dev)';
        }

        return implode(' · ', $parts);
    }

    /** The `security:` line; $devPart adds the packages-dev clause of the table. */
    public static function security(Report $report, bool $devPart): ?string
    {
        $security = SecurityTotals::of($report->sorted());
        $vulnerable = $security->vulnerablePackages();
        $lookup = self::LOOKUP_WORDS[$security->check()] ?? null;
        if ($vulnerable === 0 && $lookup === null) {
            return null;
        }
        $parts = [];
        if ($vulnerable === 0) {
            $parts[] = 'no vulnerable package';
        } else {
            $severities = [];
            foreach (Severity::DISPLAY_ORDER as $severity) {
                if ($security->advisoriesOf($severity) > 0) {
                    $severities[] = $security->advisoriesOf($severity).' '.$severity;
                }
            }
            $parts[] = self::nOf($vulnerable, 'vulnerable package', 'vulnerable packages');
            $parts[] = self::nOf($security->countedAdvisories(), 'advisory', 'advisories').': '.implode(', ', $severities);
        }
        if ($lookup !== null) {
            $parts[] = 'advisories '.$lookup;
        }
        if ($devPart && !$report->includesDev()) {
            $parts[] = 'packages-dev not checked (pass --dev)';
        }

        return 'security: '.implode(' · ', $parts);
    }

    /** `fix, by package: composer update 2 · upgrade 1 · …`, in fix-kind order. */
    public static function fixes(Report $report): ?string
    {
        $security = SecurityTotals::of($report->sorted());
        $parts = [];
        foreach (ScoreModel::FIX_KINDS as $kind) {
            if ($security->packagesFixedBy($kind) > 0) {
                $parts[] = self::FIX_WORDS[$kind].' '.$security->packagesFixedBy($kind);
            }
        }

        return $parts === [] ? null : 'fix, by package: '.implode(' · ', $parts);
    }

    /** `security.update_now_command`, rendered for a POSIX shell; null when the report has none. */
    public static function updateNow(Report $report): ?string
    {
        $command = $report->updateNowCommand();

        return $command === null ? null : ShellCommand::posix($command);
    }

    /**
     * `pulled in by: …`: the first names of root `exposure`, then `… and N more`.
     *
     * @param callable(string): string $name
     */
    public static function exposure(Report $report, callable $name): ?string
    {
        $exposure = $report->exposure();
        if ($exposure === []) {
            return null;
        }
        $parts = [];
        foreach (\array_slice($exposure, 0, Report::EXPOSURE_NAMES, true) as $parent => $count) {
            $parts[] = $name((string) $parent).' '.$count;
        }
        if (\count($exposure) > Report::EXPOSURE_NAMES) {
            $parts[] = \sprintf('… and %d more', \count($exposure) - Report::EXPOSURE_NAMES);
        }

        return 'pulled in by: '.implode(' · ', $parts);
    }

    /** The `advisories:` line of `run.advisory_lookup`, on every run. */
    public static function advisories(Report $report): string
    {
        $lookup = $report->advisoryLookup();
        $text = 'advisories: '.self::nOf($lookup->packagesAsked(), 'package asked', 'packages asked');
        $notAsked = $lookup->packagesNotAsked();
        if ($notAsked > 0) {
            $why = 'packages from a Composer repository only (advisory-lookup)';
            if ($lookup->scope() !== 'composer-repositories') {
                $why = '';
                foreach ($lookup->repositories() as $repository) {
                    if ($repository->reason() !== null) {
                        $why = self::repositoryWords($repository->reason());
                        break;
                    }
                }
            }
            $text .= ', '.number_format($notAsked).' not asked'.($why === '' ? '' : ' ('.$why.')');
        }
        foreach ($lookup->repositories() as $repository) {
            $text .= ' · '.self::repository($repository);
        }
        if ($lookup->otherRepositories() > 0) {
            $text .= ' · '.$lookup->otherRepositories().' other repositories publish no advisories';
        }

        return $text;
    }

    /** A repository's clause of the `advisories:` line: its name and what it answered. */
    public static function repository(AdvisoryRepository $repository): string
    {
        return ($repository->registry() ?? $repository->composerRepository()).' '.self::outcome($repository);
    }

    private static function outcome(AdvisoryRepository $repository): string
    {
        $reason = self::repositoryWords((string) $repository->reason());
        switch ($repository->outcome()) {
            case AdvisoryRepository::ANSWERED:
                return 'answered ('.number_format((int) $repository->records()).' on record for '.self::nOf((int) $repository->packagesWithRecords(), 'package', 'packages').')';
            case AdvisoryRepository::NOT_ASKED:
                return 'not asked ('.$reason.')';
            case AdvisoryRepository::FAILED:
                return 'lookup failed ('.$reason.': '.$repository->message().')';
            case AdvisoryRepository::NO_FEED:
                return 'publishes no advisories';
            default:
                return $repository->outcome();
        }
    }

    private static function repositoryWords(string $reason): string
    {
        return self::REPOSITORY_WORDS[$reason] ?? CauseWords::of($reason);
    }

    /** `Data as of {date} ({sources}) · score model {id}`: the table's line, without its closing sentence. */
    public static function dataAsOf(Report $report): string
    {
        return 'Data as of '.self::dataDate($report).' ('.$report->dataSourcesClause().') · score model '.ScoreModel::ID;
    }

    /** Root `data_date` as a date, the run's date when no finding carries one. */
    private static function dataDate(Report $report): string
    {
        $date = Report2Root::dataDate($report->sorted());

        return $date === null ? $report->generatedAt()->format('Y-m-d') : substr($date, 0, 10);
    }

    /** @param non-empty-list<string> $names */
    private static function names(array $names): string
    {
        if (\count($names) > self::NAMES_SHOWN) {
            return implode(', ', \array_slice($names, 0, self::NAMES_SHOWN)).' and '.(\count($names) - self::NAMES_SHOWN).' more';
        }
        $count = \count($names);

        return $count === 1 ? $names[0] : implode(', ', \array_slice($names, 0, $count - 1)).' and '.$names[$count - 1];
    }

    private static function nOf(int $count, string $one, string $many): string
    {
        return number_format($count).' '.($count === 1 ? $one : $many);
    }
}
