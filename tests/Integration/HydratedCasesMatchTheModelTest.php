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
 * lockrot-report renders the blocks of the detail sheet, the Why lines and Checks of each case from
 * the case's finding, run and branch rows (SPEC-0.14.md §7.9.8, §7.9.13). The model's goldens
 * (`cases[].golden`, §7.9.14) render those fields of the model's case. Where lockrot's recording of
 * the hydrated case has the same fields, the page writes the model's golden. Each field list holds
 * the paths that the model's renderer of that golden reads.
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

    /**
     * The fields that each block golden reads (SPEC-0.14.md §7.9.14): dotted keys of the finding, `run.` and `details.`.
     * `{a,b}` reads each.
     */
    private const LINES = [
        'verdict_line' => [
            'verdict', 'score.total', 'score.modifiers[].{after,applies_to,before}', 'score.parts.maintenance.alone.verdict',
            'score.parts.security.alone.verdict', 'run.graded_verdicts', 'activity.{event,forge,host}', 'age.push.years', 'age.release.years',
            'branch', 'replacement', 'version', 'details.metadata.branches[].{branch,highest,installed}', 'details.metadata.branches[].fixes.fixed',
            'flags[].degree.{liveness_complete,reasons}', 'flags[].headline.{source,value}', 'flags[].{id,role}', 'next_step.{kind,to_branch}',
            'next_step.replacement.{package,url}', 'score.decided_by', 'score.parts.security.of', 'score.terms[].{flag,part,severity}',
            'security.{status,worst}', 'security.installed_branch_fixes.{fix_kind,fixed,of,unknown}',
            'signals[].data.advisories[].{cve,deciding,id,title}',
            'signals[].data.{branch,event,marked_by,newest_branch,newest_php,newest_version,newest_years,reachable_branch,reachable_version,reachable_years,reason,replacement,target_php,version,written_for_php,years}',
            'signals[].id', 'run.score_model.severities[].{counts_as,id}', 'run.target_php',
        ],
        'readings' => [
            'activity.{event,forge,host}', 'age.push.years', 'age.release.years', 'branch', 'replacement', 'version',
            'details.metadata.branches[].{branch,highest,installed}', 'details.metadata.branches[].fixes.fixed',
            'flags[].degree.{liveness_complete,reasons}', 'flags[].headline.{source,value}', 'flags[].{id,role}', 'next_step.{kind,to_branch}',
            'next_step.replacement.{package,url}', 'score.decided_by', 'score.parts.security.of', 'score.terms[].{flag,part,severity}',
            'security.{status,worst}', 'security.installed_branch_fixes.{fix_kind,fixed,of,unknown}',
            'signals[].data.advisories[].{cve,deciding,id,title}',
            'signals[].data.{branch,event,marked_by,newest_branch,newest_php,newest_version,newest_years,reachable_branch,reachable_version,reachable_years,reason,replacement,target_php,version,written_for_php,years}',
            'signals[].id', 'run.score_model.severities[].{counts_as,id}', 'run.target_php',
        ],
        'qualifiers' => [
            'activity.host', 'allowlist.{by,expires,flag_ids,pattern,reason,reason_by,reason_id}', 'checks_missing[].{blocks,check,reason}',
            'checks_skipped[].{blocks,check,reason}', 'depth_unmeasured', 'dev', 'direct_dependents', 'flags[].id', 'from_composer_repository',
            'gate.{exempt_by,fails,reaches_fail_on}', 'gate.by[].{kind,value}', 'gate.by[].facts[].{id,kind}', 'installed_php.{requires,target_runs}',
            'maintenance_judged', 'metadata.status', 'named_in', 'next_step.{kind,reason,source}', 'origin.{kind,local}', 'package', 'reach',
            'score.modifiers[].{after,applies_to,before,divide_by,reason}', 'score.parts.maintenance.status', 'score.terms[].flag',
            'signals[].data.advisories[].{cve,id}', 'signals[].id', 'unchecked_accepted[].accepted_by.reason', 'unchecked_accepted[].{check,reason}',
            'verdict', 'version', 'run.gates[].{kind,threshold,value}', 'run.{include_dev,signal_ids,target_php}',
        ],
        'moves' => [
            'next_step.{clears_all,commands,composer_moves_because,constraint,crosses_major,kind,newest,reason,source,to_branch,version}',
            'next_step.adds[].{basis,id}', 'next_step.clears[].{id,kind}', 'next_step.leaves[].{id,kind}', 'next_step.unverified[].{id,kind}',
            'next_step.if_applied.{assumes,at_most,total,verdict}', 'next_step.latest.{is_installed,relation,version}',
            'next_step.php_check.{project_allows,raise_size,raise_to,requires,target_runs}', 'next_step.replacement.{package,url}',
            'next_step.replacement.finding.{flag_ids,verdict}', 'next_step.through[].{newest_requires,package}',
            'next_step.through[].newest_stable.version',
            'next_step.also[].{clears_all,commands,composer_moves_because,constraint,crosses_major,kind,newest,reason,source,to_branch,version}',
            'next_step.also[].adds[].{basis,id}', 'next_step.also[].clears[].{id,kind}', 'next_step.also[].leaves[].{id,kind}',
            'next_step.also[].unverified[].{id,kind}', 'next_step.also[].if_applied.{assumes,at_most,total,verdict}',
            'next_step.also[].latest.{is_installed,relation,version}',
            'next_step.also[].php_check.{project_allows,raise_size,raise_to,requires,target_runs}', 'next_step.also[].replacement.{package,url}',
            'next_step.also[].replacement.finding.{flag_ids,verdict}', 'next_step.also[].through[].{newest_requires,package}',
            'next_step.also[].through[].newest_stable.version', 'next_step.check.{form,reason,result}', 'next_step.also[].check.{form,reason,result}',
            'depth_unmeasured', 'direct', 'direct_dependents', 'flags[].id',
            'installed_php.{requires,target_runs}', 'metadata.status', 'origin.kind', 'package', 'replacement', 'score.terms[].flag',
            'signals[].data.advisories[].id', 'version', 'run.{include_dev,project_php_lowest,target_php}',
        ],
        'held_by' => [
            'next_step.held_by[].{constraint,package,source,version}', 'next_step.held_by[].holder.{flag_ids,verdict}',
            'next_step.held_by[].holder.next_step.{constraint,kind,to_branch}', 'next_step.also[].held_by[].{constraint,package,source,version}',
            'next_step.also[].held_by[].holder.{flag_ids,verdict}', 'next_step.also[].held_by[].holder.next_step.{constraint,kind,to_branch}',
            'run.graded_verdicts',
        ],
        'security' => [
            'details.metadata.branches[].{branch,installed}', 'details.metadata.branches[].fixes.{fix_kind,fixed,lowest,of}',
            'details.metadata.branches[].fixes.held_by[].{package,source}',
            'details.metadata.branches[].fixes.if_applied.{assumes,at_most,total,verdict}', 'next_step.held_by[].{package,source}',
            'next_step.to_branch', 'origin.kind', 'package', 'score.parts.security.of',
            'security.{check,fix_kind,ignored_count,records,status,unchecked_reason}', 'security.counts.{critical,high,low,medium,unrated}',
            'security.ignored[].{by,cve,id,reason,severity}', 'security.partial.clears[].{id,kind}',
            'security.partial.if_applied.{assumes,at_most,total,verdict}', 'security.partial.{unverified,version}',
            'signals[].data.advisories[].{affected_versions,cve,deciding,id,severity}',
            'signals[].data.advisories[].fix.{kind,on_installed_branch,to_branch,version}', 'signals[].id', 'version',
            'run.score_model.severities[].{counts_as,id}',
        ],
        'branches_lead' => [
            'age.branch_release.{version,years}', 'branch', 'details.metadata.branches[].{branch,installed}', 'next_step.to_branch', 'signals[].id',
            'signals[].data.{newest_branch,newest_version,newest_years,reachable_branch,reachable_version,reachable_years}', 'version',
        ],
        'branch_rows' => [
            'age.branch_release.{dated_by,version,years}', 'next_step.to_branch',
            'details.metadata.branches[].{branch,dated_by,highest,highest_admits_project_php,highest_admits_target_php,highest_misses_project_php,highest_php,installed,released_years}',
        ],
        'age_rows' => [
            'activity.event', 'age.installed.{at,dated_by,years}',
            'age.{branch_release_unmeasured,installed_unmeasured,push_unmeasured,release_unmeasured}',
            'age.branch_release.{at,dated_by,version,years}', 'age.release.{at,dated_by,version,years}', 'age.push.{at,years}', 'branch',
            'details.metadata.branches[].{branch,newest_dated}', 'origin.kind', 'version',
        ],
        'why' => [
            'abandoned_ignored.by', 'abandoned_ignored.rules[].{constraints,pattern,reason}', 'activity.host', 'allowlist.by',
            'checks_missing[].{blocks,reason}', 'checks_skipped[].{check,reason}', 'from_composer_repository', 'lead', 'maintenance_judged',
            'reach', 'verdict', 'metadata.status', 'origin.kind', 'score.accepted[].flag', 'score.accepted[].if_counted.{at_least,total,verdict}',
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
        'php_line' => ['run.php_window', 'run.target_php'],
    ];

    /**
     * The paths of a case's line that differ from the model's, each list with its reason. Real data
     * cannot give lockrot the model's fields there, or the model approximates them, as the reason
     * names. Every other path of the line must be equal.
     *
     * @var array<string, array<string, list<array{list<string>, string}>>>
     */
    private const DIFFERENCES = [
        'F' => [
            'age_rows' => [
                [['age.installed_unmeasured'], self::INSTALLED_COMMIT],
            ],
        ],
        'psr7' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'unreached-move' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'many-dependents' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'pagerfanta' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'replace-graded' => [
            'age_rows' => [
                [['age.installed_unmeasured'], self::INSTALLED_COMMIT],
            ],
        ],
        'B-also‡' => [
            'moves' => [
                [['next_step.also[].version', 'next_step.also[].commands'], self::MOVE_VERSION],
            ],
            'security' => [
                [['details.metadata.branches[].fixes.if_applied.at_most', 'details.metadata.branches[].fixes.if_applied.total', 'details.metadata.branches[].fixes.if_applied.verdict'], self::IF_APPLIED],
            ],
        ],
        'long-range‡' => [
            'age_rows' => [
                [['age.installed_unmeasured'], self::INSTALLED_COMMIT],
            ],
        ],
        'affected-range-unknown‡' => [
            'age_rows' => [
                [['age.installed_unmeasured'], self::INSTALLED_COMMIT],
            ],
        ],
        'A-php-star‡' => [
            'held_by' => [
                [['next_step.held_by[].holder.next_step.constraint', 'next_step.held_by[].holder.next_step.to_branch'], self::PHP_STAR],
            ],
        ],
        'tie‡' => [
            'verdict_line' => [
                [['score.total', 'flags[].degree.liveness_complete', 'flags[].degree.reasons', 'flags[].headline.source', 'flags[].headline.value', 'flags[].id', 'flags[].role', 'score.terms[].flag', 'score.terms[].part', 'score.terms[].severity', 'signals[].data.advisories[].cve', 'signals[].data.advisories[].deciding', 'signals[].data.advisories[].id', 'signals[].data.advisories[].title', 'signals[].data.branch', 'signals[].data.event', 'signals[].data.marked_by', 'signals[].data.newest_branch', 'signals[].data.newest_php', 'signals[].data.newest_version', 'signals[].data.newest_years', 'signals[].data.reachable_branch', 'signals[].data.reachable_version', 'signals[].data.reachable_years', 'signals[].data.reason', 'signals[].data.replacement', 'signals[].data.target_php', 'signals[].data.version', 'signals[].data.written_for_php', 'signals[].data.years', 'signals[].id'], self::TIE],
            ],
            'readings' => [
                [['flags[].degree.liveness_complete', 'flags[].degree.reasons', 'flags[].headline.source', 'flags[].headline.value', 'flags[].id', 'flags[].role', 'score.terms[].flag', 'score.terms[].part', 'score.terms[].severity', 'signals[].data.advisories[].cve', 'signals[].data.advisories[].deciding', 'signals[].data.advisories[].id', 'signals[].data.advisories[].title', 'signals[].data.branch', 'signals[].data.event', 'signals[].data.marked_by', 'signals[].data.newest_branch', 'signals[].data.newest_php', 'signals[].data.newest_version', 'signals[].data.newest_years', 'signals[].data.reachable_branch', 'signals[].data.reachable_version', 'signals[].data.reachable_years', 'signals[].data.reason', 'signals[].data.replacement', 'signals[].data.target_php', 'signals[].data.version', 'signals[].data.written_for_php', 'signals[].data.years', 'signals[].id'], self::TIE],
            ],
            'qualifiers' => [
                [['flags[].id', 'score.terms[].flag', 'signals[].data.advisories[].cve', 'signals[].data.advisories[].id', 'signals[].id'], self::TIE],
            ],
            'moves' => [
                [['next_step.version', 'next_step.commands', 'next_step.unverified[].id', 'next_step.unverified[].kind', 'next_step.if_applied.at_most', 'next_step.if_applied.total', 'next_step.if_applied.verdict', 'next_step.also[].leaves[].id', 'next_step.also[].leaves[].kind', 'flags[].id', 'score.terms[].flag', 'signals[].data.advisories[].id'], self::TIE],
            ],
            'security' => [
                [['signals[].data.advisories[].affected_versions', 'signals[].data.advisories[].cve', 'signals[].data.advisories[].deciding', 'signals[].data.advisories[].id', 'signals[].data.advisories[].severity', 'signals[].data.advisories[].fix.kind', 'signals[].data.advisories[].fix.on_installed_branch', 'signals[].data.advisories[].fix.to_branch', 'signals[].data.advisories[].fix.version', 'signals[].id'], self::TIE],
            ],
            'branches_lead' => [
                [['signals[].id', 'signals[].data.newest_branch', 'signals[].data.newest_version', 'signals[].data.newest_years', 'signals[].data.reachable_branch', 'signals[].data.reachable_version', 'signals[].data.reachable_years'], self::TIE],
            ],
            'why' => [
                [['score.exact', 'score.total', 'score.terms[].advisory', 'score.terms[].fix_kind', 'score.terms[].flag', 'score.terms[].multiplier', 'score.terms[].part', 'score.terms[].points', 'score.terms[].role', 'score.terms[].severity', 'score.terms[].weight', 'score.without[].at_least', 'score.without[].lead', 'score.without[].total', 'score.without[].verdict', 'score.without[].remove.id', 'score.without[].remove.kind', 'score.without[].revealed[].flag', 'score.without[].revealed[].role', 'signals[].data.advisories[].cve', 'signals[].data.advisories[].id', 'signals[].data.advisories[].fix.to_branch', 'signals[].id'], self::TIE],
            ],
            'checks' => [
                [['signals[].id'], self::TIE],
            ],
        ],
        'B-offline‡' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'holder-vuln‡' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'partial-op‡' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'unreached-dev‡' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'C-bitbucket‡' => [
            'verdict_line' => [
                [['flags[].degree.reasons', 'flags[].headline.value', 'signals[].data.advisories[].cve', 'signals[].data.advisories[].deciding', 'signals[].data.advisories[].id', 'signals[].data.advisories[].title', 'signals[].data.branch', 'signals[].data.event', 'signals[].data.marked_by', 'signals[].data.newest_branch', 'signals[].data.newest_php', 'signals[].data.newest_version', 'signals[].data.newest_years', 'signals[].data.reachable_branch', 'signals[].data.reachable_version', 'signals[].data.reachable_years', 'signals[].data.reason', 'signals[].data.replacement', 'signals[].data.target_php', 'signals[].data.version', 'signals[].data.written_for_php', 'signals[].data.years', 'signals[].id'], self::BITBUCKET],
            ],
            'readings' => [
                [['flags[].degree.reasons', 'flags[].headline.value', 'signals[].data.advisories[].cve', 'signals[].data.advisories[].deciding', 'signals[].data.advisories[].id', 'signals[].data.advisories[].title', 'signals[].data.branch', 'signals[].data.event', 'signals[].data.marked_by', 'signals[].data.newest_branch', 'signals[].data.newest_php', 'signals[].data.newest_version', 'signals[].data.newest_years', 'signals[].data.reachable_branch', 'signals[].data.reachable_version', 'signals[].data.reachable_years', 'signals[].data.reason', 'signals[].data.replacement', 'signals[].data.target_php', 'signals[].data.version', 'signals[].data.written_for_php', 'signals[].data.years', 'signals[].id'], self::BITBUCKET],
            ],
            'qualifiers' => [
                [['signals[].data.advisories[].cve', 'signals[].data.advisories[].id', 'signals[].id'], self::BITBUCKET],
            ],
            'moves' => [
                [['signals[].data.advisories[].id'], self::BITBUCKET],
            ],
            'security' => [
                [['signals[].data.advisories[].affected_versions', 'signals[].data.advisories[].cve', 'signals[].data.advisories[].deciding', 'signals[].data.advisories[].id', 'signals[].data.advisories[].severity', 'signals[].data.advisories[].fix.kind', 'signals[].data.advisories[].fix.on_installed_branch', 'signals[].data.advisories[].fix.to_branch', 'signals[].data.advisories[].fix.version', 'signals[].id'], self::BITBUCKET],
            ],
            'branches_lead' => [
                [['signals[].id', 'signals[].data.newest_branch', 'signals[].data.newest_version', 'signals[].data.newest_years', 'signals[].data.reachable_branch', 'signals[].data.reachable_version', 'signals[].data.reachable_years'], self::BITBUCKET],
            ],
            'why' => [
                [['signals[].data.advisories[].cve', 'signals[].data.advisories[].id', 'signals[].data.advisories[].fix.to_branch', 'signals[].id'], self::BITBUCKET],
            ],
            'checks' => [
                [['signals[].id'], self::BITBUCKET],
            ],
        ],
        'B-offline-high‡' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'require-dev-name‡' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'capped-require-php' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'adds-capped-raise-php' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'adds-capped-require‡' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'php-edge-lower‡' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'platform-step‡' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'platform-step-inside‡' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'php-edge-equal‡' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'php-edge-upper‡' => [
            'moves' => [
                [['next_step.version', 'next_step.commands'], self::MOVE_VERSION],
            ],
        ],
        'routing-self‡' => [
            'held_by' => [
                [['next_step.held_by[].constraint', 'next_step.held_by[].package', 'next_step.held_by[].version', 'next_step.held_by[].holder.flag_ids', 'next_step.held_by[].holder.verdict', 'next_step.held_by[].holder.next_step.constraint', 'next_step.held_by[].holder.next_step.kind', 'next_step.held_by[].holder.next_step.to_branch'], self::MODEL_R7_PENDING],
            ],
            'security' => [
                [['details.metadata.branches[].fixes.held_by[].package', 'next_step.held_by[].package'], self::MODEL_R7_PENDING],
            ],
        ],
        'mc-joint‡' => [
            'security' => [
                [['details.metadata.branches[].fixes.fix_kind', 'security.fix_kind', 'signals[].data.advisories[].fix.kind'], self::MODEL_R7_PENDING],
            ],
            'why' => [
                [['score.terms[].fix_kind'], self::MODEL_R7_PENDING],
            ],
        ],
    ];

    private const MOVE_VERSION = 'The model leaves `version` null on a move whose constraint comes from S8. lockrot names the lowest release that the constraint admits, and the why-not step of a move that does not resolve names it. The move card prints `newest` and reads `version` only when `newest` is null.';

    // HARDEN-SRC removes every MODEL_R7_PENDING entry when it projects the MODEL-R7 shas.
    private const MODEL_R7_PENDING = 'The model value is wrong for the case, and MODEL-R7 corrects it. lockrot follows SPEC-0.14.md.';

    private const PHP_STAR = 'The case sets the project\'s require.php to `*` (‡). The model keeps the holder\'s own step of case A, and lockrot judges the holder under the case\'s require.php, which admits its newest line.';

    private const INSTALLED_COMMIT = 'The model holds the date of a shared commit only for a branch\'s highest tag, so the hydrated lock gives the installed tag no date. lockrot then reads it as undated, not as dated by a commit its tags share.';

    private const BITBUCKET = 'The model marks the repository archived on Bitbucket (S3). Bitbucket\'s repository API has no archived field, so lockrot cannot raise S3 for a Bitbucket repository, and each later signal moves up one place.';

    private const TIE = 'The model removes S5 from the case to make the tie. The installed release requires php >=5.3.3 and is older than the PHP 8.0 release, so lockrot raises S5 (old-promise).';

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
        'next_step.also[].reason' => 'No also[] move of a case with inputs is a no-move.',
        'next_step.also[].adds[].basis' => self::ALSO_ADDS,
        'next_step.also[].adds[].id' => self::ALSO_ADDS,
        'next_step.also[].latest.is_installed' => self::ALSO_LATEST,
        'next_step.also[].latest.relation' => self::ALSO_LATEST,
        'next_step.also[].latest.version' => self::ALSO_LATEST,
        'next_step.also[].php_check.raise_size' => self::ALSO_RAISE,
        'next_step.also[].php_check.raise_to' => self::ALSO_RAISE,
        'next_step.also[].replacement.package' => self::ALSO_REPLACE,
        'next_step.also[].replacement.url' => self::ALSO_REPLACE,
        'next_step.also[].replacement.finding.flag_ids' => self::ALSO_REPLACE,
        'next_step.also[].replacement.finding.verdict' => self::ALSO_REPLACE,
        'next_step.also[].through[].newest_requires' => self::ALSO_THROUGH,
        'next_step.also[].through[].package' => self::ALSO_THROUGH,
        'next_step.also[].through[].newest_stable.version' => self::ALSO_THROUGH,
        'next_step.also[].held_by[].package' => self::ALSO_HELD,
        'next_step.also[].held_by[].version' => self::ALSO_HELD,
        'next_step.also[].held_by[].holder.flag_ids' => self::ALSO_HELD,
        'next_step.also[].held_by[].holder.verdict' => self::ALSO_HELD,
        'next_step.also[].held_by[].holder.next_step.constraint' => self::ALSO_HELD,
        'next_step.also[].held_by[].holder.next_step.kind' => self::ALSO_HELD,
        'next_step.also[].held_by[].holder.next_step.to_branch' => self::ALSO_HELD,
        'next_step.also[].check.form' => self::ALSO_CHECK,
        'next_step.also[].check.reason' => self::ALSO_CHECK,
        'next_step.also[].check.result' => self::ALSO_CHECK,
    ];

    private const ONLY_PSR_LOG = 'Only psr-log accepts a flag on a halved finding, and it carries no branch rows, so the projector gives it no inputs.';

    private const ALSO_ADDS = 'No also[] move of a case with inputs adds a flag.';

    private const ALSO_LATEST = 'No also[] move of a case with inputs is a tag, test or blocked move.';

    private const ALSO_RAISE = 'No also[] move of a case with inputs raises require.php.';

    private const ALSO_REPLACE = 'No also[] move of a case with inputs is a replace move.';

    private const ALSO_THROUGH = 'An also[] move carries no through[] (I14f).';

    private const ALSO_HELD = 'No also[] move of a case with inputs is held by a package.';

    private const ALSO_CHECK = 'No case that checks its moves (train 7d, `move-check‡`) has an also[] move.';

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
        $commands = array_values(array_intersect($listed, ['next_step.commands', 'next_step.also[].commands']));
        self::assertSame(Fields::only($model, $commands), Fields::only(self::withoutWhyNot($lockrot), $commands), $id.' '.$line.': a listed commands differs in more than the trailing why-not step');
    }

    /**
     * @param array<mixed, mixed> $document
     *
     * @return array<mixed, mixed> the document without the trailing `composer why-not` step of the package at each move's own `version`
     */
    private static function withoutWhyNot(array $document): array
    {
        $strip = static function (array $move) use ($document): array {
            $commands = JsonPath::arrayAt($move, ['commands']);
            if ($commands !== [] && end($commands) === ['composer', 'why-not', $document['package'], $move['version'], '--locked']) {
                array_pop($commands);
                $move['commands'] = $commands;
            }

            return $move;
        };
        if (\is_array($document['next_step'] ?? null)) {
            $document['next_step'] = $strip($document['next_step']);
            $document['next_step']['also'] = array_map($strip, JsonPath::arrayAt($document, ['next_step', 'also']));
        }

        return $document;
    }

    /** The notes of a train 7d case quote the move check (SPEC-0.14.md §6.2), so the page shows the model's notes. */
    public function testATrain7dRecordingHasTheModelsRunNotes(): void
    {
        $cases = 0;
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(CaseHydrator::CASES), ['cases']) as $case) {
            self::assertIsArray($case);
            if (!\is_array($case['inputs'] ?? null) || !\is_array($case['inputs']['solver_world'] ?? null)) {
                continue;
            }
            ++$cases;
            $id = JsonPath::stringAt($case, ['id']);
            $report = JsonPath::decodeFile(self::CONTRACT.'report-2/'.CaseRecording::fileName($id).'.json');

            self::assertSame(self::codesAndData(JsonPath::has($case, ['run_notes']) ? JsonPath::arrayAt($case, ['run_notes']) : []), self::codesAndData(JsonPath::arrayAt($report, ['note_details'])), $id.': the recording\'s notes against the model\'s');
        }
        self::assertGreaterThan(0, $cases);
    }

    /**
     * @param array<mixed, mixed> $notes
     *
     * @return list<array{mixed, mixed}>
     */
    private static function codesAndData(array $notes): array
    {
        $out = [];
        foreach ($notes as $note) {
            self::assertIsArray($note);
            $out[] = [$note['code'] ?? null, $note['data'] ?? null];
        }

        return $out;
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
