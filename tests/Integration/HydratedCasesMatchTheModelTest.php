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
 * lockrot-report renders the answer, the Why lines, Checks, Release branches and Security of each
 * case from the case's finding, run and branch rows (SPEC-0.14.md §7.9.8, §7.9.13). The model's
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
        'security_row' => [
            'details.metadata.branches[].{branch,highest}', 'details.metadata.branches[].fixes.{fix_kind,fixed,lowest,of}',
            'details.metadata.branches[].fixes.held_by[].{package,source}',
            'details.metadata.branches[].fixes.if_applied.{assumes,at_most,total,verdict}', 'origin.kind',
            'security.{check,fix_kind,ignored_count,records,status,unchecked_reason}', 'security.counts.{critical,high,low,medium,unrated}',
            'signals[].data.advisories[].fix.kind', 'signals[].id',
        ],
    ];

    /**
     * The lines of a case whose fields differ from the model's, each with the reason that real data
     * cannot give lockrot the model's fields.
     *
     * @var array<string, array<string, string>>
     */
    private const DIFFERENCES = [
        'C-bitbucket‡' => [
            'answer' => self::BITBUCKET,
            'why' => self::BITBUCKET,
            'checks' => self::BITBUCKET,
            'security_row' => self::BITBUCKET,
        ],
        'tie‡' => [
            'answer' => self::TIE,
            'why' => self::TIE,
            'checks' => self::TIE,
            'security_row' => self::TIE,
        ],
        'psr7' => ['security_row' => self::NEWEST_TAG_ONLY],
        'B-also‡' => ['security_row' => self::NEWEST_TAG_ONLY],
        'xmlseclibs' => ['security_row' => self::NEWEST_TAG_ONLY.' The model also classes the 4.x row `upgrade` because it lies above the installed branch. In the mautic-v4 lock, only lightsaml/lightsaml requires the package, with `~2.0|~3.0|~4.0`. That constraint admits 4.x, so lockrot classes the row `update` (§5.3).'],
    ];

    private const BITBUCKET = 'The model marks the repository archived on Bitbucket (S3). Bitbucket\'s repository API has no archived field, so lockrot cannot raise S3 for a Bitbucket repository.';

    private const TIE = 'The model removes S5 from the case to make the tie. The installed release requires php >=5.3.3 and is older than the PHP 8.0 release, so lockrot raises S5 (old-promise).';

    private const NEWEST_TAG_ONLY = 'The model knows only the newest tag of each branch, so `fixes.lowest` is null and `if_applied` is an upper bound (`at_most`). lockrot reads the fixing release of each branch and names it, and its `if_applied` is exact.';

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
        $model = self::fieldsOf(JsonPath::arrayAt($case, ['finding']), JsonPath::arrayAt($case, ['run']), self::branchesOf($case['details'] ?? null), $line);
        $name = CaseRecording::fileName($id);
        $report = JsonPath::decodeFile(self::CONTRACT.'report-2/'.$name.'.json');
        $explain = JsonPath::decodeFile(self::CONTRACT.'explain-2/'.$name.'.json');
        $lockrot = self::fieldsOf(JsonPath::arrayAt($report, ['findings', 0]), JsonPath::arrayAt($report, ['run']), self::branchesOf($explain), $line);

        if (isset(self::DIFFERENCES[$id][$line])) {
            self::assertNotSame($model, $lockrot, $id.' '.$line.': the fields agree with the model again: take the line off DIFFERENCES');

            return;
        }
        self::assertSame($model, $lockrot, $id.' '.$line.': the recording differs from the model in the fields that the line reads');
    }

    /** Each reason names a field that the line reads. */
    public function testEveryListedDifferenceIsALineWithAReason(): void
    {
        foreach (self::DIFFERENCES as $id => $lines) {
            self::assertIsArray(CaseHydrator::case((string) $id)['inputs'] ?? null, $id.' has no inputs, so no recording');
            foreach ($lines as $line => $reason) {
                self::assertArrayHasKey($line, self::LINES, $id);
                self::assertNotSame('', $reason, $id.' '.$line);
            }
        }
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
    private static function fieldsOf(array $finding, array $run, array $branches, string $line): array
    {
        $document = $finding + ['run' => $run, 'details' => ['metadata' => ['branches' => $branches]]];

        return Fields::only($document, self::paths(self::LINES[$line]));
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
