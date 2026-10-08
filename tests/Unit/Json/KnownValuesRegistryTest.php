<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Json;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Libyears;
use Lockrot\Analyzer\RunNote;
use Lockrot\Config\Gate;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryIgnoreMatch;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Repository\MetadataFailure;
use Lockrot\Json\Schemas;
use Lockrot\Legacy\PriorityBasis013;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Security\Fix;
use Lockrot\Signal\AbandonedIgnored;
use Lockrot\Signal\AgeMeasure;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\Rule\PinnedRule;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each open set of a numbered schema from 2 on lists in `x-known-values` exactly the values that
 * lockrot writes there: the list of the class that writes the value, or the values that
 * {@see ScoreModel::toArray()} writes at that place. The registry has one row per place that the
 * schema walk finds. A place that a later pull request fills holds a literal and names that pull
 * request. The `-1` files are frozen and {@see \Lockrot\Tests\Unit\Verdict\ClosedSetsTest} reads them.
 */
final class KnownValuesRegistryTest extends TestCase
{
    private const LATER = 'a later pull request writes it';

    /**
     * Every place that holds `x-known-values`, as a JSON pointer under the schema root, in any
     * numbered file from 2 on.
     *
     * @return array<string, array{list<int|string>, string}> the known values and who writes them
     */
    private static function registry(): array
    {
        $roles = [Score::LEAD, Score::CORROBORATING];
        $fixReasons = [Fix::RELEASES_UNKNOWN, Fix::AFFECTED_RANGE_UNKNOWN, Fix::NOT_FROM_COMPOSER_REPOSITORY, Fix::NO_RELEASE_OUTSIDE_RANGE];
        $misses = [PhpFloor::NEEDS_NEWER, PhpFloor::STOPS_BEFORE, PhpFloor::SKIPS, PhpFloor::UNSATISFIABLE];
        $floors = [PhpFloor::PROJECT, PhpFloor::TARGET];
        $moveKinds = ['replace', 'find-alternative', 'tag', 'require', 'update', 'raise-php', 'test', 'blocked', 'no-tag', 'no-move', 'no-fix', 'no-single-fix'];
        $moveReasons = ['package_quiet', 'no_higher_release', 'higher_undated', 'releases_unknown', 'metadata_not_read', 'local_package'];
        $assumes = ['clears_hold', 'composer_resolves', 'no_new_advisories'];
        $thresholds = ['release-warn-years', 'release-high-years', 'push-warn-years', 'push-high-years'];

        return [
            '#/definitions/forgeId' => [RepoRef::FORGES, 'RepoRef'],
            '#/definitions/s3/properties/forge' => [RepoRef::FORGES, 'RepoRef'],
            '#/definitions/s4/properties/forge' => [RepoRef::FORGES, 'RepoRef'],
            '#/definitions/activityEvent' => [array_values(array_unique(array_map(static fn (string $forge): string => (new RepoRef($forge, 'example.test', 'a/b'))->event(), RepoRef::FORGES))), 'RepoRef::event()'],
            '#/definitions/metadataFailureReason' => [MetadataFailure::REASONS, 'MetadataFailure'],
            '#/definitions/repositoryActivityNotCheckedReason' => [RunNote::REPOSITORY_ACTIVITY_NOT_CHECKED_REASONS, 'RunNote'],
            '#/definitions/advisoriesNotCheckedReason' => [RunNote::ADVISORIES_NOT_CHECKED_REASONS, 'RunNote'],
            '#/definitions/noteCode' => [RunNote::CODES, 'RunNote'],
            '#/definitions/noteAdvisoriesDisabledByPolicy/properties/policy_key' => [['policy', 'policy.advisories', 'policy.advisories.audit', 'COMPOSER_POLICY'], 'RunNote::advisoriesDisabledByPolicy()'],
            '#/definitions/originKind' => [PackageOrigin::KINDS, 'PackageOrigin'],
            '#/definitions/originRegistry/oneOf/0' => [PackageOrigin::REGISTRIES, 'PackageOrigin'],
            '#/definitions/signalId' => [Signal::IDS, 'Signal'],
            '#/definitions/fixKind' => [ScoreModel::FIX_KINDS, 'ScoreModel'],
            '#/definitions/s6/properties/reason' => [[PinnedRule::REASON_BRANCH_SNAPSHOT, PinnedRule::REASON_NO_STABLE_RELEASE], 'PinnedRule'],
            '#/definitions/s6/properties/tag_relation/oneOf/0' => [['older', 'newer', 'same'], 'PinnedRule'],
            '#/definitions/s8/properties/floor_source/oneOf/0' => [$floors, 'PhpFloor'],
            '#/definitions/s9fix/properties/reason/oneOf/0' => [$fixReasons, 'Fix'],
            '#/definitions/finding/properties/libyears_unmeasured/oneOf/0' => [Libyears::REASONS, 'Libyears'],
            '#/definitions/headline/anyOf/2/properties/value' => [[FlagSet::ARCHIVED, 'marked', PinnedRule::REASON_BRANCH_SNAPSHOT, PinnedRule::REASON_NO_STABLE_RELEASE], 'Finding'],
            '#/definitions/replacement/oneOf/0/properties/named_by' => [[AbandonedIgnored::MARKED_BY_REPOSITORY, AbandonedIgnored::MARKED_BY_LOCK], 'AbandonedIgnored'],
            '#/definitions/allowlist/oneOf/0/properties/reason_id/oneOf/0' => [BuiltinAllowlist::reasonIds(), 'BuiltinAllowlist'],
            '#/definitions/ignoredAdvisory/properties/by' => [[AdvisoryIgnoreMatch::BY_POLICY, AdvisoryIgnoreMatch::BY_AUDIT, AdvisoryIgnoreMatch::BY_AUDIT_SEVERITY], 'AdvisoryIgnoreMatch'],
            '#/definitions/ignoredAdvisory/properties/matched' => [[AdvisoryIgnoreMatch::ID, AdvisoryIgnoreMatch::CVE, AdvisoryIgnoreMatch::REMOTE_ID, AdvisoryIgnoreMatch::PACKAGE, AdvisoryIgnoreMatch::SEVERITY], 'AdvisoryIgnoreMatch'],
            '#/definitions/securityVulnerable/properties/check' => [['complete', 'partial'], 'FindingDetails'],
            '#/definitions/securityVulnerable/properties/unchecked_reason/oneOf/0' => [[AdvisoryCoverage::INSTALL_TIME_BUDGET, AdvisoryCoverage::LOOKUP_FAILED], 'AdvisoryCoverage'],
            '#/definitions/securityUnchecked/properties/check' => [['partial', 'not_run'], 'FindingDetails'],
            '#/definitions/securityUnchecked/properties/unchecked_reason/oneOf/0' => [[AdvisoryCoverage::OFFLINE, AdvisoryCoverage::COMPOSER_TOO_OLD, AdvisoryCoverage::INSTALL_TIME_BUDGET, AdvisoryCoverage::LOOKUP_FAILED, AdvisoryCoverage::NOT_FROM_COMPOSER_REPOSITORY, AdvisoryCoverage::UNPARSEABLE_VERSION, AdvisoryCoverage::NO_FEED], 'AdvisoryCoverage'],
            '#/definitions/rootSecurity/properties/check' => [['complete', 'partial', 'not_run'], 'Report2Root'],
            '#/definitions/checkMissing/properties/check' => [['repository_activity', 'release_dates', 'releases'], 'NotCheckedRule'],
            '#/definitions/checkMissing/properties/reason' => [[NotCheckedRule::NO_TOKEN, NotCheckedRule::RATE_BUDGET, NotCheckedRule::BUDGET, NotCheckedRule::RATE_LIMIT, NotCheckedRule::FETCH_FAILED, NotCheckedRule::OFFLINE, AgeMeasure::UNDATED_RELEASES, Fix::RELEASES_UNKNOWN], 'NotCheckedRule'],
            '#/definitions/checkSkipped/properties/check' => [['repository_activity', 'release_metadata', 'advisories', 'release_branch'], 'FindingDetails'],
            '#/definitions/checkSkipped/properties/reason' => [[AgeMeasure::ALLOWLISTED, AgeMeasure::NO_REPOSITORY, AdvisoryCoverage::NOT_FROM_COMPOSER_REPOSITORY, NotCheckedRule::OFFLINE, NotCheckedRule::RATE_LIMIT, NotCheckedRule::FETCH_FAILED, NotCheckedRule::RATE_BUDGET, NotCheckedRule::BUDGET, NotCheckedRule::NO_TOKEN, 'unavailable', 'not_found', PinnedRule::REASON_BRANCH_SNAPSHOT, AdvisoryCoverage::UNPARSEABLE_VERSION], 'FindingDetails'],
            '#/definitions/run/properties/mode' => [Gate::MODES, 'Gate'],
            '#/definitions/rootGate/properties/tripped_by/items' => [Gate::TRIPS, 'Gate'],
            '#/definitions/findingGate/properties/exempt_by/oneOf/0' => [Gate::EXEMPTIONS, 'Gate'],
            '#/definitions/gateValue/properties/kind' => [['grade', 'flag', 'vulnerable', 'unchecked'], 'RunSettings writes grade, flag and unchecked, and the vulnerable gate value is a later pull request'],
            '#/definitions/findingGate/properties/by/items/properties/kind' => [['grade', 'flag', 'vulnerable', 'unchecked'], self::LATER],
            '#/definitions/gateFact/properties/kind' => [['flag', 'advisory', 'signal'], self::LATER],
            '#/definitions/termMaintenance/properties/role' => [$roles, 'Score'],
            '#/definitions/termMaintenance/properties/divisor' => [[1, 4], 'ScoreModel'],
            '#/definitions/termSecurity/properties/role' => [['security'], 'Finding'],
            '#/definitions/termSecurity/properties/multiplier' => [[1, 2], 'ScoreModel'],
            '#/definitions/ifCounted/properties/role' => [$roles, 'Score'],
            '#/definitions/withoutRow/properties/revealed/items/properties/role' => [array_merge($roles, ['accepted']), 'ScoreBasis'],
            '#/definitions/findingFlag/properties/role' => [array_merge($roles, ['security', 'accepted']), 'Finding'],
            '#/definitions/modifier/properties/reason' => [[Score::TRANSITIVE, Score::UNREACHED, 'dev'], 'Score'],
            '#/definitions/modifier/properties/applies_to' => [['maintenance', 'total'], 'ScoreBasis'],
            '#/definitions/modifier/properties/divide_by' => [[2], 'ScoreModel'],
            '#/definitions/partMaintenance/properties/status' => [['counted', 'none', 'accepted', 'not_judged'], 'ScoreBasis'],
            '#/definitions/partSecurity/properties/status' => [['counted', 'clear', 'unchecked'], 'ScoreBasis'],
            '#/definitions/scoreGraded/properties/decided_by' => [['maintenance', 'security', 'combination', 'either'], 'ScoreBasis'],
            '#/definitions/scoreRule/properties/id' => [self::model(['rules', '*', 'id']), 'ScoreModel'],
            '#/definitions/scoreRule/properties/of' => [self::model(['rules', '*', 'id']), 'ScoreModel'],
            '#/definitions/scoreRule/properties/kind' => [self::model(['rules', '*', 'kind']), 'ScoreModel'],
            '#/definitions/scoreRule/properties/applies_to' => [self::model(['rules', '*', 'applies_to']), 'ScoreModel'],
            '#/definitions/scoreRule/properties/why' => [self::model(['rules', '*', 'why']), 'ScoreModel'],
            '#/definitions/scoreRule/properties/order' => [self::model(['rules', '*', 'order']), 'ScoreModel'],
            '#/definitions/scoreRule/properties/table' => [self::model(['rules', '*', 'table']), 'ScoreModel'],
            '#/definitions/scoreRule/properties/tie_break/items/properties/key' => [self::model(['rules', '*', 'tie_break', '*', 'key']), 'ScoreModel'],
            '#/definitions/scoreRule/properties/tie_break/items/properties/dir' => [self::model(['rules', '*', 'tie_break', '*', 'dir']), 'ScoreModel'],
            '#/definitions/scoreRule/properties/tie_break/items/properties/collation/oneOf/0' => [self::model(['rules', '*', 'tie_break', '*', 'collation']), 'ScoreModel'],
            '#/definitions/scoreRule/properties/when/items' => [self::model(['rules', '*', 'when', '*']), 'ScoreModel'],
            '#/definitions/scoreRule/properties/op' => [self::model(['rules', '*', 'op']), 'ScoreModel'],
            '#/definitions/scoreRule/properties/covers' => [self::model(['rules', '*', 'covers']), 'ScoreModel'],
            '#/definitions/scoreRule/properties/accept_group' => [self::model(['rules', '*', 'accept_group']), 'ScoreModel'],
            '#/definitions/scoreRule/properties/accept_covers' => [self::model(['rules', '*', 'accept_covers']), 'ScoreModel'],
            '#/definitions/scoreModel/properties/parts/items/properties/combine' => [self::model(['parts', '*', 'combine']), 'ScoreModel'],
            '#/definitions/scoreModel/properties/flags/items/properties/thresholds/items' => [$thresholds, 'ScoreModel'],
            '#/definitions/scoreModel/properties/flags/items/properties/basis/properties/points' => [self::model(['flags', '*', 'basis', 'points']), 'ScoreModel'],
            '#/definitions/scoreModel/properties/flags/items/properties/points_from/oneOf/0' => [self::model(['flags', '*', 'points_from']), 'ScoreModel'],
            '#/definitions/scoreModel/properties/severities/items/properties/basis/properties/points' => [self::model(['severities', '*', 'basis', 'points']), 'ScoreModel'],
            '#/definitions/scoreModel/properties/zero_verdicts/items/properties/when' => [self::model(['zero_verdicts', '*', 'when']), 'ScoreModel'],
            '#/definitions/scoreModel/properties/rounding' => [self::model(['rounding']), 'ScoreModel'],
            '#/definitions/scoreModel/properties/bar_overflow' => [self::model(['bar_overflow']), 'ScoreModel'],
            '#/definitions/scoreModel/properties/exclusive_groups/items/properties/id' => [self::model(['exclusive_groups', '*', 'id']), 'ScoreModel'],
            '#/definitions/scoreModel/properties/exclusive_groups/items/properties/holds_unless/oneOf/0/properties/threshold' => [$thresholds, 'ScoreModel'],
            '#/definitions/scoreModel/properties/exclusive_groups/items/properties/holds_unless/oneOf/0/properties/below' => [$thresholds, 'ScoreModel'],
            '#/definitions/scoreModel/properties/sort/items/properties/key' => [self::model(['sort', '*', 'key']), 'ScoreModel'],
            '#/definitions/scoreModel/properties/sort/items/properties/dir' => [self::model(['sort', '*', 'dir']), 'ScoreModel'],
            '#/definitions/scoreModel/properties/sort/items/properties/collation/oneOf/0' => [self::model(['sort', '*', 'collation']), 'ScoreModel'],
            '#/definitions/priorityStep/properties/reason' => [PriorityBasis013::STEPS, 'PriorityBasis013'],
            '#/definitions/repositoryMetadata/properties/branches/items/properties/php_blocked_by/oneOf/0' => [$floors, 'PhpFloor'],
            '#/definitions/repositoryMetadata/properties/branches/items/properties/misses_target_php/oneOf/0' => [$misses, 'PhpFloor'],
            '#/definitions/repositoryMetadata/properties/branches/items/properties/misses_project_php/oneOf/0' => [$misses, 'PhpFloor'],
            '#/definitions/clearedFact/properties/basis' => [['branch_releasing', 'released_after_ga', 'outside_range', 'tags_only'], self::LATER],
            '#/definitions/unverifiedFact/properties/reason' => [['undated', 'no_release_data'], self::LATER],
            '#/definitions/addedFact/properties/basis' => [['released_before_ga', 'branch_not_releasing'], self::LATER],
            '#/definitions/factBaseline/oneOf/0/properties/state' => [['known', 'covered', 'new', 're_rated', 'fix_lost'], self::LATER],
            '#/definitions/findingBaseline/oneOf/0/properties/worsened_by/items/properties/why' => [['new', 're_rated', 'fix_lost'], self::LATER],
            '#/definitions/holder/oneOf/0/properties/next_step/oneOf/0/properties/kind' => [$moveKinds, self::LATER],
            '#/definitions/move/properties/kind' => [$moveKinds, self::LATER],
            '#/definitions/alsoMove/properties/kind' => [$moveKinds, self::LATER],
            '#/definitions/move/properties/reason/oneOf/0' => [$moveReasons, self::LATER],
            '#/definitions/alsoMove/properties/reason/oneOf/0' => [$moveReasons, self::LATER],
            '#/definitions/ifApplied/oneOf/0/properties/assumes/items' => [$assumes, self::LATER],
            '#/definitions/branchFixes/properties/if_applied/oneOf/0/properties/assumes/items' => [$assumes, self::LATER],
            '#/definitions/securityVulnerable/properties/partial/oneOf/0/properties/if_applied/properties/assumes/items' => [$assumes, self::LATER],
            '#/definitions/latest/oneOf/0/properties/relation/oneOf/0' => [['older', 'newer', 'same'], self::LATER],
            '#/definitions/throughEntry/properties/newest_requires_unmeasured/oneOf/0' => [['not_from_composer_repository', 'releases_unknown', 'installed_unlisted', 'through_other_package', 'no_stable_release'], self::LATER],
        ];
    }

    /** @return iterable<string, array{string, int}> */
    public static function numberedSchemas(): iterable
    {
        foreach ([Schemas::REPORT, Schemas::EXPLAIN, Schemas::BASELINE, Schemas::CONFIG] as $document) {
            foreach (Schemas::numbers($document) as $number) {
                if ($number >= 2) {
                    yield $document.'-'.$number => [$document, $number];
                }
            }
        }
    }

    /**
     * @dataProvider numberedSchemas
     */
    #[DataProvider('numberedSchemas')]
    public function testEachKnownValuesListIsTheListLockrotWrites(string $document, int $number): void
    {
        $registry = self::registry();
        $found = self::knownValues(JsonPath::decodeFile(Schemas::path($document, $number)), '#');

        self::assertSame([], array_values(array_diff(array_keys($found), array_keys($registry))), 'a place the registry does not name');
        foreach ($found as $pointer => $known) {
            [$expected, $writer] = $registry[$pointer];
            self::assertSame(self::sorted($expected), self::sorted($known), $document.'-'.$number.' '.$pointer.' ('.$writer.')');
        }
    }

    public function testEveryRegistryRowIsAPlaceSomeSchemaHolds(): void
    {
        $found = [];
        foreach (self::numberedSchemas() as [$document, $number]) {
            $found += self::knownValues(JsonPath::decodeFile(Schemas::path($document, $number)), '#');
        }

        self::assertSame([], array_values(array_diff(array_keys(self::registry()), array_keys($found))));
    }

    public function testTheRulesUsedMapKnowsEveryRuleIdInTheModelsOrder(): void
    {
        $report = JsonPath::decodeFile(Schemas::path(Schemas::REPORT, 2));

        $keys = JsonPath::arrayAt($report, ['definitions', 'run', 'properties', 'score_rules_used'])['x-known-keys'] ?? null;

        self::assertSame(self::model(['rules', '*', 'id']), $keys);
    }

    public function testTheWalkFindsKnownValuesAtEveryDepth(): void
    {
        $schema = ['definitions' => ['a' => ['x-known-values' => ['x']], 'b' => ['oneOf' => [['x-known-values' => [1]], ['type' => 'null']]]]];

        self::assertSame(['#/definitions/a' => ['x'], '#/definitions/b/oneOf/0' => [1]], self::knownValues($schema, '#'));
    }

    /**
     * The distinct values that {@see ScoreModel::toArray()} writes at a path, `*` for each item, in
     * the order of first appearance.
     *
     * @param list<string> $path
     *
     * @return list<int|string>
     */
    private static function model(array $path): array
    {
        $nodes = [ScoreModel::toArray()];
        foreach ($path as $key) {
            $next = [];
            foreach ($nodes as $node) {
                if (!\is_array($node)) {
                    continue;
                }
                if ($key === '*') {
                    $next = array_merge($next, array_values($node));
                } elseif (\array_key_exists($key, $node)) {
                    $next[] = $node[$key];
                }
            }
            $nodes = $next;
        }
        $values = [];
        foreach ($nodes as $value) {
            if ((\is_string($value) || \is_int($value)) && !\in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param mixed $node
     *
     * @return array<string, list<mixed>> by JSON pointer
     */
    private static function knownValues($node, string $pointer): array
    {
        if (!\is_array($node)) {
            return [];
        }
        $found = \is_array($node['x-known-values'] ?? null) ? [$pointer => array_values($node['x-known-values'])] : [];
        foreach ($node as $key => $child) {
            $found += self::knownValues($child, $pointer.'/'.$key);
        }

        return $found;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<mixed>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
