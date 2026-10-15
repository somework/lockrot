<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\Contract\CaseRecording;
use Lockrot\Tests\Support\Contract\Fields;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * lockrot-report renders the answer, the Why lines, Checks, Release branches, the age line and
 * Security of each case from the case's finding, run and branch rows (SPEC-0.14.md §7.9.8, §7.9.13). The model's
 * goldens (`cases[].golden`) render those fields of the model's case. Where lockrot's recording of
 * the hydrated case has the same fields, the page writes the model's golden. Each field list holds
 * the paths that the model's renderer of that line reads.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class HydratedCasesMatchTheModelTest extends TestCase
{
    private const CONTRACT = __DIR__.'/../fixtures/contract/';

    /** The fields that each line reads: dotted keys of the finding, `run.` and `details.`; `{a,b}` reads each. */
    private const LINES = [
        'answer' => [
            'activity.host', 'allowlist.{by,flag_ids,reason,reason_by,reason_id}', 'branch', 'depth_unmeasured', 'direct', 'direct_dependents',
            'from_composer_repository', 'maintenance_judged', 'named_in', 'package', 'verdict', 'version', 'checks_missing[].{blocks,reason}',
            'checks_skipped[].{check,reason}', 'flags[].degree.{liveness_complete,reasons}', 'flags[].headline.{source,value}',
            'flags[].{id,role}', 'installed_php.{requires,target_runs}', 'metadata.status', 'next_step.adds[].{basis,id}',
            'next_step.also[].clears[].{id,kind}', 'next_step.also[].{clears_all,constraint,kind,to_branch}',
            'next_step.also[].leaves[].{id,kind}', 'next_step.also[].php_check.{project_allows,requires,target_runs}',
            'next_step.also[].unverified[].id', 'next_step.clears[].{id,kind}',
            'next_step.{clears_all,composer_moves_because,constraint,crosses_major,kind,newest,reason,source,to_branch}',
            'next_step.held_by[].{constraint,package,source,version}', 'next_step.held_by[].holder.{flag_ids,lead,verdict}',
            'next_step.latest.{is_installed,relation,version}', 'next_step.leaves[].{id,kind}',
            'next_step.php_check.{project_allows,raise_size,raise_to,requires,target_runs}', 'next_step.replacement.finding.{flag_ids,verdict}',
            'next_step.replacement.package', 'next_step.through[].{newest_requires,package}', 'next_step.through[].newest_stable.version',
            'next_step.unverified[].id', 'origin.{kind,local}', 'score.accepted[].flag', 'score.accepted[].if_counted.{at_least,total,verdict}',
            'score.accepted[].if_counted.modifiers[].{after,before,divide_by,reason}', 'score.decided_by',
            'score.modifiers[].{after,applies_to,before,reason}', 'score.parts.maintenance.alone.verdict', 'score.parts.maintenance.status',
            'score.parts.security.alone.verdict', 'score.parts.security.of', 'score.terms[].{fix_kind,flag,multiplier,part,severity}',
            'security.{ignored_count,status,unchecked_reason,worst}', 'security.installed_branch_fixes.{fix_kind,fixed,of,unknown}',
            'security.partial.clears[].{id,kind}', 'security.partial.if_applied.{assumes,at_most,total,verdict}',
            'security.partial.{unverified,version}', 'signals[].data.advisories[].{cve,deciding,id,title}',
            'signals[].data.{branch,event,forge,marked_by,newest_branch,newest_php,newest_version,newest_years,reachable_branch,reachable_version,reachable_years,reason,replacement,target_php,version,written_for_php,years}',
            'signals[].id', 'unchecked_accepted[].accepted_by.reason', 'unchecked_accepted[].{check,reason}',
            'run.{include_dev,project_php_lowest,target_php}',
        ],
        'why' => [
            'abandoned_ignored.by', 'abandoned_ignored.rules[].{constraints,pattern,reason}', 'activity.host', 'allowlist.by',
            'checks_missing[].{blocks,reason}', 'checks_skipped[].{check,reason}', 'from_composer_repository', 'lead', 'maintenance_judged',
            'reach', 'verdict', 'metadata.status', 'next_step.adds[].id', 'next_step.if_applied.{assumes,at_most,total,verdict}',
            'next_step.unverified[].{id,kind}', 'origin.kind', 'score.accepted[].flag', 'score.accepted[].if_counted.{at_least,total,verdict}',
            'score.accepted[].if_counted.modifiers[].{after,before,divide_by,reason}', 'score.band.{floor,next,to_next}',
            'score.{decided_by,exact,rounded_down,total}', 'score.modifiers[].{after,applies_to,before,divide_by,reason}',
            'score.parts.maintenance.alone.verdict', 'score.parts.maintenance.status', 'score.parts.security.alone.verdict',
            'score.parts.security.{of,tied}', 'score.terms[].{advisory,fix_kind,flag,multiplier,part,points,role,severity,weight}',
            'score.without[].{at_least,lead,total,verdict}', 'score.without[].remove.{id,kind}', 'score.without[].revealed[].{flag,role}',
            'security.partial.clears[].{id,kind}', 'security.partial.if_applied.{assumes,at_most,total,verdict}', 'security.partial.unverified',
            'security.worst', 'signals[].data.advisories[].{cve,id}', 'signals[].data.advisories[].fix.to_branch', 'signals[].id',
            'unchecked_accepted[].accepted_by.reason', 'unchecked_accepted[].{check,reason}', 'run.target_php',
        ],
        'checks' => [
            'abandoned_ignored', 'from_composer_repository', 'checks_missing[].{blocks,reason}', 'checks_skipped[].{blocks,check,reason}',
            'origin.kind', 'signals[].id', 'unchecked_accepted[].{check,reason}', 'run.signal_ids',
        ],
        'branches' => [
            'details.metadata.branches[].{branch,highest,highest_admits_project_php,highest_admits_target_php,highest_misses_project_php,highest_php,installed,released_years}',
            'age.branch_release.{version,years}', 'installed_php.{requires,target_runs}', 'package', 'version',
        ],
        'age' => [
            'activity.event', 'age.installed.{at,dated_by,years}', 'age.installed_unmeasured', 'age.branch_release.{at,dated_by,version,years}',
            'age.release.{at,dated_by,version,years}', 'age.push.{at,years}', 'branch', 'version',
        ],
        'security_row' => [
            'details.metadata.branches[].{branch,highest}', 'details.metadata.branches[].fixes.{fix_kind,fixed,lowest,of}',
            'details.metadata.branches[].fixes.held_by[].{package,source}',
            'details.metadata.branches[].fixes.if_applied.{assumes,at_most,total,verdict}', 'origin.kind',
            'security.{check,fix_kind,ignored_count,records,status,unchecked_reason}', 'security.counts.{critical,high,low,medium,unrated}',
            'signals[].data.advisories[].fix.kind', 'signals[].id',
        ],
    ];

    /**
     * The paths of a case's line that differ from the model's, each list with its reason. Real data
     * cannot give lockrot the model's fields there, or the model approximates them, as the reason
     * names. Every other path of the line must be equal.
     *
     * @var array<string, array<string, list<array{list<string>, string}>>>
     */
    private const DIFFERENCES = [
        'F' => ['age' => [[['age.installed_unmeasured'], self::INSTALLED_COMMIT]]],
        'replace-graded' => ['age' => [[['age.installed_unmeasured'], self::INSTALLED_COMMIT]]],
        'affected-range-unknown‡' => ['age' => [[['age.installed_unmeasured'], self::INSTALLED_COMMIT]]],
        'psr7' => [
            'security_row' => [
                [['details.metadata.branches[].fixes.lowest'], self::LOWEST],
            ],
        ],
        'xmlseclibs' => [
            'security_row' => [
                [['details.metadata.branches[].fixes.lowest'], self::LOWEST],
                [['details.metadata.branches[].fixes.fix_kind'], self::BY_POSITION],
            ],
        ],
        'B-also‡' => [
            'security_row' => [
                [['details.metadata.branches[].fixes.lowest'], self::LOWEST],
                [['details.metadata.branches[].fixes.if_applied.at_most', 'details.metadata.branches[].fixes.if_applied.total', 'details.metadata.branches[].fixes.if_applied.verdict'], self::IF_APPLIED],
            ],
        ],
        'tie‡' => [
            'answer' => [
                [['flags[].degree.liveness_complete', 'flags[].degree.reasons', 'flags[].headline.source', 'flags[].headline.value', 'flags[].id', 'flags[].role', 'next_step.also[].leaves[].id', 'next_step.also[].leaves[].kind', 'next_step.unverified[].id', 'score.terms[].fix_kind', 'score.terms[].flag', 'score.terms[].multiplier', 'score.terms[].part', 'score.terms[].severity', 'signals[].data.advisories[].cve', 'signals[].data.advisories[].deciding', 'signals[].data.advisories[].id', 'signals[].data.advisories[].title', 'signals[].data.branch', 'signals[].data.event', 'signals[].data.forge', 'signals[].data.marked_by', 'signals[].data.newest_branch', 'signals[].data.newest_php', 'signals[].data.newest_version', 'signals[].data.newest_years', 'signals[].data.reachable_branch', 'signals[].data.reachable_version', 'signals[].data.reachable_years', 'signals[].data.reason', 'signals[].data.replacement', 'signals[].data.target_php', 'signals[].data.version', 'signals[].data.written_for_php', 'signals[].data.years', 'signals[].id'], self::TIE],
            ],
            'why' => [
                [['next_step.if_applied.at_most', 'next_step.if_applied.total', 'next_step.if_applied.verdict', 'next_step.unverified[].id', 'next_step.unverified[].kind', 'score.exact', 'score.total', 'score.terms[].advisory', 'score.terms[].fix_kind', 'score.terms[].flag', 'score.terms[].multiplier', 'score.terms[].part', 'score.terms[].points', 'score.terms[].role', 'score.terms[].severity', 'score.terms[].weight', 'score.without[].at_least', 'score.without[].lead', 'score.without[].total', 'score.without[].verdict', 'score.without[].remove.id', 'score.without[].remove.kind', 'score.without[].revealed[].flag', 'score.without[].revealed[].role', 'signals[].data.advisories[].cve', 'signals[].data.advisories[].id', 'signals[].data.advisories[].fix.to_branch', 'signals[].id'], self::TIE],
            ],
            'checks' => [
                [['signals[].id'], self::TIE],
            ],
            'security_row' => [
                [['signals[].data.advisories[].fix.kind', 'signals[].id'], self::TIE],
            ],
        ],
        'C-bitbucket‡' => [
            'answer' => [
                [['flags[].degree.reasons', 'flags[].headline.value', 'signals[].data.advisories[].cve', 'signals[].data.advisories[].deciding', 'signals[].data.advisories[].id', 'signals[].data.advisories[].title', 'signals[].data.branch', 'signals[].data.event', 'signals[].data.forge', 'signals[].data.marked_by', 'signals[].data.newest_branch', 'signals[].data.newest_php', 'signals[].data.newest_version', 'signals[].data.newest_years', 'signals[].data.reachable_branch', 'signals[].data.reachable_version', 'signals[].data.reachable_years', 'signals[].data.reason', 'signals[].data.replacement', 'signals[].data.target_php', 'signals[].data.version', 'signals[].data.written_for_php', 'signals[].data.years', 'signals[].id'], self::BITBUCKET],
            ],
            'why' => [
                [['signals[].data.advisories[].cve', 'signals[].data.advisories[].id', 'signals[].data.advisories[].fix.to_branch', 'signals[].id'], self::BITBUCKET],
            ],
            'checks' => [
                [['signals[].id'], self::BITBUCKET],
            ],
            'security_row' => [
                [['signals[].data.advisories[].fix.kind', 'signals[].id'], self::BITBUCKET],
            ],
        ],
    ];

    private const INSTALLED_COMMIT = 'The model holds the date of a shared commit only for a branch\'s highest tag, so the hydrated lock gives the installed tag no date. lockrot then reads it as undated, not as dated by a commit its tags share.';

    private const BITBUCKET = 'The model marks the repository archived on Bitbucket (S3). Bitbucket\'s repository API has no archived field, so lockrot cannot raise S3 for a Bitbucket repository, and each later signal moves up one place.';

    private const TIE = 'The model removes S5 from the case to make the tie. The installed release requires php >=5.3.3 and is older than the PHP 8.0 release, so lockrot raises S5 (old-promise).';

    private const LOWEST = 'The model names a row\'s `fixes.lowest` only when an S9 fix names it. lockrot names the row\'s candidate (P2b).';

    private const BY_POSITION = 'The model classes a branch above the installed one `upgrade` by its position. No link of the hydrated lock holds the package to its installed major, so lockrot classes the row `update` (§5.3).';

    private const IF_APPLIED = 'The score after a move to the row follows its `fixes.lowest`. The model judges the row\'s newest tag as an upper bound, and lockrot judges its candidate exactly.';

    /** The paths of a line that no case with inputs fills, each with its reason. */
    private const UNFILLED = [
        'score.accepted[].if_counted.modifiers[].after' => self::ONLY_PSR_LOG,
        'score.accepted[].if_counted.modifiers[].before' => self::ONLY_PSR_LOG,
        'score.accepted[].if_counted.modifiers[].divide_by' => self::ONLY_PSR_LOG,
        'score.accepted[].if_counted.modifiers[].reason' => self::ONLY_PSR_LOG,
        'age.release.dated_by' => 'No case of the model dates its newest release by a monorepo parent.',
        'security.partial.unverified' => 'No case of the model leaves a fact of a partial fix unverified.',
        'unchecked_accepted[].accepted_by.reason' => self::ACCEPT_UNCHECKED,
        'unchecked_accepted[].check' => self::ACCEPT_UNCHECKED,
        'unchecked_accepted[].reason' => self::ACCEPT_UNCHECKED,
    ];

    private const ONLY_PSR_LOG = 'Only psr-log accepts a flag on a halved finding, and it carries no branch rows, so the projector gives it no inputs.';

    private const ACCEPT_UNCHECKED = 'The cases that accept an unchecked check need an accept-unchecked entry of the project, which the hydrator does not take (`unhydrated()`).';

    /** @return iterable<string, array{string, string}> */
    public static function caseLines(): iterable
    {
        $cases = JsonPath::arrayAt(JsonPath::decodeFile(CaseHydrator::CASES), ['cases']);
        self::assertNotSame([], $cases);
        foreach ($cases as $case) {
            self::assertIsArray($case);
            if (!\is_array($case['inputs'] ?? null)) {
                continue;
            }
            foreach (array_keys(self::LINES) as $line) {
                yield JsonPath::stringAt($case, ['id']).' '.$line => [JsonPath::stringAt($case, ['id']), $line];
            }
        }
    }

    /**
     * @dataProvider caseLines
     */
    #[DataProvider('caseLines')]
    public function testTheRecordingHasTheModelsFieldsOfEachLine(string $id, string $line): void
    {
        $case = CaseHydrator::case($id);
        $model = self::documentOf(JsonPath::arrayAt($case, ['finding']), JsonPath::arrayAt($case, ['run']), self::branchesOf($case['details'] ?? null));
        $name = CaseRecording::fileName($id);
        $report = JsonPath::decodeFile(self::CONTRACT.'report-2/'.$name.'.json');
        $explain = JsonPath::decodeFile(self::CONTRACT.'explain-2/'.$name.'.json');
        $lockrot = self::documentOf(self::findingOf($report, JsonPath::stringAt($case, ['finding', 'package'])), JsonPath::arrayAt($report, ['run']), self::branchesOf($explain));
        $listed = [];
        foreach (self::DIFFERENCES[$id][$line] ?? [] as [$paths]) {
            foreach ($paths as $path) {
                self::assertNotSame(Fields::only($model, [$path]), Fields::only($lockrot, [$path]), $id.' '.$line.': '.$path.' agrees with the model again: take it off DIFFERENCES');
            }
            $listed = array_merge($listed, $paths);
        }
        $kept = array_values(array_diff(self::paths(self::LINES[$line]), $listed));

        self::assertSame(Fields::only($model, $kept), Fields::only($lockrot, $kept), $id.' '.$line.': the recording differs from the model in the fields that the line reads');
    }

    /** Each listed path is one that its line reads, and each list has a reason. */
    public function testEveryListedDifferenceIsALineWithAReason(): void
    {
        foreach (self::DIFFERENCES as $id => $lines) {
            self::assertIsArray(CaseHydrator::case((string) $id)['inputs'] ?? null, $id.' has no inputs, so no recording');
            foreach ($lines as $line => $groups) {
                self::assertArrayHasKey($line, self::LINES, $id);
                foreach ($groups as [$paths, $reason]) {
                    self::assertSame([], array_values(array_diff($paths, self::paths(self::LINES[$line]))), $id.' '.$line.': the line reads no such path');
                    self::assertNotSame('', $reason, $id.' '.$line);
                }
            }
        }
    }

    /** Each path of a line is filled by the model's or lockrot's fields of some case, or listed in UNFILLED. */
    public function testEveryPathOfALineIsFilledByACase(): void
    {
        $filled = [];
        foreach (self::caseLines() as [$id, $line]) {
            $case = CaseHydrator::case($id);
            $model = self::documentOf(JsonPath::arrayAt($case, ['finding']), JsonPath::arrayAt($case, ['run']), self::branchesOf($case['details'] ?? null));
            foreach (self::paths(self::LINES[$line]) as $path) {
                $filled[$path] = ($filled[$path] ?? false) || self::holdsAValue(Fields::only($model, [$path]));
            }
        }
        self::assertNotSame([], $filled);

        $unfilled = array_keys(array_filter($filled, static fn (bool $is): bool => !$is));
        sort($unfilled);
        $listed = array_keys(self::UNFILLED);
        sort($listed);
        self::assertSame($listed, $unfilled, 'list each path that no case fills in UNFILLED, and take each filled path off it');
    }

    /** @param mixed $node */
    private static function holdsAValue($node): bool
    {
        if (!\is_array($node)) {
            return $node !== null;
        }
        foreach ($node as $value) {
            if (self::holdsAValue($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A recording of a case with related packages holds a finding for each of them.
     *
     * @param array<mixed, mixed> $report
     *
     * @return array<mixed, mixed>
     */
    private static function findingOf(array $report, string $package): array
    {
        foreach (JsonPath::arrayAt($report, ['findings']) as $finding) {
            if (\is_array($finding) && ($finding['package'] ?? null) === $package) {
                return $finding;
            }
        }
        self::fail($package.' has no finding in the recording');
    }

    /**
     * @param mixed $details an explain-2 document or a case's `details`
     *
     * @return list<mixed>
     */
    private static function branchesOf($details): array
    {
        $metadata = \is_array($details) ? ($details['metadata'] ?? null) : null;

        return \is_array($metadata) && \is_array($metadata['branches'] ?? null) ? array_values($metadata['branches']) : [];
    }

    /**
     * @param array<mixed, mixed> $finding
     * @param array<mixed, mixed> $run
     * @param list<mixed>         $branches
     *
     * @return array<mixed, mixed>
     */
    private static function documentOf(array $finding, array $run, array $branches): array
    {
        return $finding + ['run' => $run, 'details' => ['metadata' => ['branches' => $branches]]];
    }

    /**
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private static function paths(array $patterns): array
    {
        $paths = [];
        foreach ($patterns as $pattern) {
            if (preg_match('/^(.*)\{([^}]+)\}$/', $pattern, $match) !== 1) {
                $paths[] = $pattern;
                continue;
            }
            foreach (explode(',', $match[2]) as $key) {
                $paths[] = $match[1].$key;
            }
        }

        return $paths;
    }
}
