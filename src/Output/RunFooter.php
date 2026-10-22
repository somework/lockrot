<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\FlagTotals;
use Lockrot\Analyzer\PhpWindow;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\Report2Root;
use Lockrot\Analyzer\SecurityTotals;
use Lockrot\Analyzer\TargetFix;
use Lockrot\Data\Advisory\AdvisoryRepository;
use Lockrot\Security\Holder;
use Lockrot\Security\Severity;
use Lockrot\Solver\MoveCheck;
use Lockrot\Verdict\MoveText;
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

    /**
     * The `moves:` line of `run.move_check`: which Composer checked the moves, once per run with
     * the extensions it does not check. Null when the run checked no move.
     */
    public static function moveCheck(Report $report): ?string
    {
        $run = $report->moveCheck();
        if ($run === null) {
            return null;
        }
        $data = $run->jsonSerialize();
        switch ($data['baseline']) {
            case MoveCheck::RESOLVES:
                return 'moves: checked with the solver of Composer '.$data['composer'].' against the lock; ext-* requirements not checked';
            case MoveCheck::REFUSED:
                return "moves: not checked, because Composer's solver refuses the lock itself (see the moves_not_checked note)";
            default:
                return 'moves: not checked ('.MoveCheck::reasonWords((string) $data['reason']).')';
        }
    }

    /**
     * The `php:` line of `run.php_window`: the minors the lock installs on, then the update that
     * admits an excluded target (`target_fix`), or else the PHP that Composer checks the lock at
     * and the links that set the edge which matters. Null when the window is null.
     *
     * @param callable(string): string $name marks a package name or a command as the format marks code
     */
    public static function phpWindow(Report $report, callable $name): ?string
    {
        $window = $report->phpWindow();
        if ($window === null) {
            return null;
        }
        $data = $window->toArray();
        $known = array_flip($window->knownMinors());
        $with = $data['with_dev'];
        $without = $data['without_dev'];
        $clause = $with === null || $with['minors'] === $without['minors']
            ? 'the lock installs '.self::installsOn($without['minors'], $known)
            : 'without require-dev the lock installs '.self::installsOn($without['minors'], $known).', and with require-dev '.self::installsOn($with['minors'], $known);
        $target = $window->targetMinor();
        $fix = $data['target_fix'];
        if ($fix !== null && TargetFix::isRootCeiling($fix)) {
            return 'php: '.$clause.'; your target, '.$target.', is excluded: '.self::ceilingWords($fix['held_by']);
        }
        if (!$data['target']['inside']) {
            $clause .= ', so your target, '.$target.', is excluded';
        }
        if ($fix !== null) {
            return 'php: '.$clause.'; '.self::fixWords($fix, $target, $name);
        }
        $parts = [$clause];
        if ($data['platform_override'] !== null) {
            $parts[] = 'Composer checks it at '.$data['platform_override'].' from config.platform.php';
        }
        $edge = self::edgeWords($window, $report->includesDev(), $name);
        if ($edge !== null) {
            $parts[] = $edge;
        }

        return 'php: '.implode('; ', $parts);
    }

    /**
     * The setters of the lowest edge when the target lies below it, else those of the highest edge
     * of the set that the run analysed.
     *
     * @param callable(string): string $name
     */
    private static function edgeWords(PhpWindow $window, bool $includesDev, callable $name): ?string
    {
        $data = $window->toArray();
        $lowest = ($data['with_dev'] ?? $data['without_dev'])['lower_edge'];
        if ($lowest !== null && $window->targetBelowLowest()) {
            $setters = self::setters($lowest['set_by'], $name);

            return $setters === [] ? null : self::names($setters).(\count($setters) === 1 ? ' sets' : ' set').' the lowest PHP, '.$lowest['php'];
        }
        $edge = ($includesDev && $data['with_dev'] !== null ? $data['with_dev'] : $data['without_dev'])['upper_edge'];
        if ($edge === null) {
            return null;
        }
        $setters = self::setters($edge['set_by'], $name);

        return $setters === [] ? null : self::names($setters).(\count($setters) === 1 ? ' excludes ' : ' exclude ').$edge['excludes'];
    }

    /**
     * @param list<array{name: ?string, link: string, target: string, dev: bool}> $reqs
     * @param callable(string): string                                             $name
     *
     * @return list<string> each setter once
     */
    private static function setters(array $reqs, callable $name): array
    {
        $setters = [];
        foreach ($reqs as $req) {
            $setter = self::setter($req, $name);
            if (!\in_array($setter, $setters, true)) {
                $setters[] = $setter;
            }
        }

        return $setters;
    }

    /**
     * The `target_fix` clause (§7.0): Composer's answer to the update of the listed packages.
     *
     * @param array{packages: list<array{name: ?string}>, command: ?list<string>, check: string, reason: ?string, held_by: list<Holder>, platform_step: bool} $fix
     * @param callable(string): string                                                                                                               $name
     */
    private static function fixWords(array $fix, string $target, callable $name): string
    {
        $count = \count(array_unique(array_column($fix['packages'], 'name')));
        $them = $count === 1 ? 'the package that excludes it' : 'the '.$count.' packages that exclude it';
        switch ($fix['check']) {
            case MoveCheck::RESOLVES:
                $command = $name((string) ShellCommand::posix((array) $fix['command']));

                return $fix['platform_step']
                    ? 'set config.platform.php to your PHP '.$target." release, then Composer's solver resolves: ".$command
                    : "Composer's solver at PHP ".$target.' resolves: '.$command;
            case MoveCheck::REFUSED:
                $holds = array_map(static fn (Holder $hold): string => ($hold->source() === Holder::ROOT ? 'composer.json' : $name((string) $hold->package()).' '.$hold->version()).' '.MoveText::linkWords($hold, $name), $fix['held_by']);

                return "Composer's solver at PHP ".$target.' refuses the update of '.$them.($holds === [] ? '' : ': '.implode(' and ', $holds));
            default:
                return 'the update of '.$them.' is not checked ('.($fix['reason'] === null ? 'the run checked no move' : MoveCheck::reasonWords($fix['reason'])).')';
        }
    }

    /**
     * "require.php allows only ~8.1.0", "conflict.php excludes >=8.4", joined with "and".
     *
     * @param list<Holder> $holds
     */
    private static function ceilingWords(array $holds): string
    {
        return implode(' and ', array_map(static fn (Holder $hold): string => $hold->link().'.'.$hold->target().($hold->link() === Holder::CONFLICT ? ' excludes ' : ' allows only ').$hold->constraint(), $holds));
    }

    /**
     * @param list<string>       $minors
     * @param array<string, int> $known known minor => its place, oldest first
     */
    private static function installsOn(array $minors, array $known): string
    {
        if ($minors === []) {
            return 'on no PHP';
        }
        $runs = [];
        $previous = null;
        foreach ($minors as $minor) {
            $index = $known[$minor];
            if ($previous !== null && $index === $previous + 1) {
                $runs[\count($runs) - 1][] = $minor;
            } else {
                $runs[] = [$minor];
            }
            $previous = $index;
        }
        $parts = array_map(static fn (array $run): string => \count($run) === 1 ? $run[0] : $run[0].' to '.$run[\count($run) - 1], $runs);
        $last = array_pop($parts);

        return 'on PHP '.($parts === [] ? $last : implode(', ', $parts).' and '.$last);
    }

    /**
     * A package name, or the root's link: `require.php`, `require-dev.php`, `conflict.php`, `require.php-64bit`.
     *
     * @param array{name: ?string, link: string, target: string, dev: bool} $req
     * @param callable(string): string                                       $name
     */
    private static function setter(array $req, callable $name): string
    {
        if ($req['name'] !== null) {
            return $name($req['name']);
        }
        if ($req['link'] === PhpWindow::LINK_CONFLICT) {
            return 'conflict.'.$req['target'];
        }

        return ($req['dev'] ? 'require-dev.' : 'require.').$req['target'];
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
