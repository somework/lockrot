<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Analyzer\Libyears;
use Lockrot\Analyzer\RunNote;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Config\Gate;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Repository\MetadataFailure;
use Lockrot\Json\KnownValues;
use Lockrot\Json\Schemas;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\Rule\PinnedRule;
use Lockrot\Tests\Support\ClosedSets;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\NoFix;
use Lockrot\Verdict\Priority;
use Lockrot\Verdict\PriorityBasis;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * The closed sets that docs/compatibility.md freezes for 1.x, and their order. `--fail-on`, the
 * baseline and the report sort read that order. The lists here are literals, not the class
 * constants, so a changed value fails here. The schemas must list the same sets in the same order,
 * because a consumer that validates against them reads the order from there. An open set is an open string with the values lockrot writes in `x-known-values`, never
 * an enum. The last test guards the names that docs/compatibility.md reserves for extensions.
 */
final class ClosedSetsTest extends TestCase
{
    private const VERDICTS = ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'unknown', 'finished', 'ok'];
    private const FLAGGED = ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale'];
    private const PRIORITIES = ['critical', 'high', 'medium', 'low', 'none'];
    private const LEVELS = ['info', 'warn', 'high'];
    private const STANDINGS = ['known', 'new', 'worsened'];
    /** A signal id: lockrot's own `S<n>`, or a `<vendor>:<name>` one that does not come from lockrot. */
    private const SIGNAL_ID = '^(S[1-9][0-9]*|[a-z0-9][a-z0-9_.-]*:[a-z0-9][a-z0-9_.-]*)$';
    /** A format name: lockrot's own, or a `<vendor>:<name>` one. */
    private const FORMAT = '^([a-z][a-z0-9-]*|[a-z0-9][a-z0-9_.-]*:[a-z0-9][a-z0-9_.-]*)$';
    /** A run note's code: lockrot's own, or a `<vendor>:<name>` one. */
    private const NOTE_CODE = '^([a-z][a-z0-9_]*|[a-z0-9][a-z0-9_.-]*:[a-z0-9][a-z0-9_.-]*)$';
    /** The words both pages name a run note's five vocabularies by: its code, and the `forge_id` and reasons in its data. */
    private const NOTE_VOCABULARY = "a run note's `code`, and the `forge_id` and `reason` in its `data`";
    /** A lock entry's origin kind: lockrot's own, or a `<vendor>:<name>` one, as a note's code. */
    private const ORIGIN_KIND = self::NOTE_CODE;
    /** A registry lockrot names: a lower-case host name. */
    private const HOST = '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$';
    /** The words both pages name a finding's origin vocabularies by. */
    private const ORIGIN_VOCABULARY = "a finding's `origin.kind` and `origin.registry`";
    /**
     * A lower-case word. These fields hold one:
     * - an S10 check or reason, an S6 reason, an S8 floor source
     * - a branch's php_blocked_by and misses_*_php, a finding's libyears_unmeasured
     * - a priority step's and a no-fix advisory's reason, the run's mode and fail-on kind
     * - the gate's causes and exemptions, a run note's `forge_id` and reasons
     */
    private const WORD = '^[a-z][a-z0-9_]*$';
    private const ROOT = __DIR__.'/../../../';
    /**
     * The values report-1 and explain-1 list for three open sets whose PHP lists later releases
     * retire, frozen here so their rows outlive the constants: `run.fail_on_kind`
     * ({@see FailOn::KINDS}), a `priority_basis` step's `reason` ({@see PriorityBasis::STEPS}) and a
     * `no_fix_expected` item's `reason` ({@see NoFix::REASONS}). Every other `-1` row reads its live
     * constant while its file is still widened under its number.
     */
    private const FAIL_ON_KINDS_1 = ['none', 'verdict', 'priority', 'unchecked'];
    private const PRIORITY_STEPS_1 = ['transitive', 'unreached', 'dev', 'no_fix_expected'];
    private const NO_FIX_REASONS_1 = ['not_on_installed_branch', 'releases_unknown', 'affected_range_unknown', 'no_release_fixes'];
    /** A synthetic schema holding one integer set, the shape report-2's divisors and multipliers take. */
    private const INTEGER_SET = self::ROOT.'tests/fixtures/schema/closed-sets/integer-set.schema.json';

    public function testTheVerdictsAreFrozenInTheirOrder(): void
    {
        self::assertSame(self::VERDICTS, Verdict::all());
    }

    /** Covers every neighbour, `left-behind` between `pinned` and `old-promise` included. */
    public function testSeverityFallsStrictlyDownTheOrderAndOnlyFinishedAndOkTie(): void
    {
        $verdicts = Verdict::all();
        $last = \count($verdicts) - 1;
        for ($i = 0; $i < $last - 1; ++$i) {
            self::assertGreaterThan(
                Verdict::severity($verdicts[$i + 1]),
                Verdict::severity($verdicts[$i]),
                $verdicts[$i].' is more severe than '.$verdicts[$i + 1]
            );
        }
        self::assertSame(Verdict::severity('finished'), Verdict::severity('ok'), 'finished and ok share the lowest severity');
    }

    public function testTheFlaggedVerdictsAreTheFirstSix(): void
    {
        self::assertSame(self::FLAGGED, array_values(array_filter(Verdict::all(), [Verdict::class, 'flagged'])));
        self::assertSame(self::FLAGGED, (new RunSettings(null, null, null, null, null, null))->toArray()['flagged_verdicts']);
    }

    public function testThePrioritiesAreFrozenInTheirOrder(): void
    {
        self::assertSame(self::PRIORITIES, Priority::all());
        $priorities = Priority::all();
        for ($i = 0, $last = \count($priorities) - 1; $i < $last; ++$i) {
            self::assertGreaterThan(Priority::rank($priorities[$i + 1]), Priority::rank($priorities[$i]), $priorities[$i]);
        }
    }

    public function testTheLevelsAndStandingsAreFrozenInTheirOrder(): void
    {
        self::assertSame(self::LEVELS, ClosedSets::levels());
        self::assertSame(self::STANDINGS, ClosedSets::standings());
    }

    public function testTheReportSchemaSpellsTheSameClosedSetsInTheSameOrder(): void
    {
        $schema = self::schema(Schemas::REPORT, 1);

        self::assertSame(self::VERDICTS, JsonPath::arrayAt($schema, ['definitions', 'verdict', 'enum']));
        self::assertSame(self::PRIORITIES, JsonPath::arrayAt($schema, ['definitions', 'priority', 'enum']));
        self::assertSame(self::LEVELS, JsonPath::arrayAt($schema, ['definitions', 'level', 'enum']));
        self::assertSame(self::STANDINGS, JsonPath::arrayAt($schema, ['definitions', 'baselineStanding', 'oneOf', 0, 'properties', 'status', 'enum']));
        self::assertSame([1], JsonPath::arrayAt($schema, ['definitions', 'envelope', 'properties', 'schema', 'enum']), 'report-1 names its own number');
    }

    /**
     * A priority step's `from` and `to` are the priority order without `none`, in the same order:
     * the frozen set restricted, not a set of its own. The explain schema copies the three
     * definitions a finding's `priority_basis` and `no_fix_expected` use from the report's, verbatim.
     */
    public function testAStepMovesAlongThePriorityOrderWithoutNone(): void
    {
        $report = self::schema(Schemas::REPORT, 1);
        $explain = self::schema(Schemas::EXPLAIN, 1);
        $flagged = array_values(array_diff(Priority::all(), [Priority::NONE]));

        self::assertSame(['critical', 'high', 'medium', 'low'], $flagged);
        self::assertSame(['transitive', 'unreached', 'dev', 'no_fix_expected'], PriorityBasis::STEPS, 'in the order the steps apply');
        self::assertSame(['not_on_installed_branch', 'releases_unknown', 'affected_range_unknown', 'no_release_fixes'], NoFix::REASONS, 'in the order the first that applies is picked');
        foreach (['report' => $report, 'explain' => $explain] as $document => $schema) {
            self::assertSame($flagged, JsonPath::arrayAt($schema, ['definitions', 'flaggedPriority', 'enum']), $document);
            self::assertSame(['$ref' => '#/definitions/flaggedPriority'], JsonPath::arrayAt($schema, ['definitions', 'priorityStep', 'properties', 'from']), $document);
            self::assertSame(['$ref' => '#/definitions/flaggedPriority'], JsonPath::arrayAt($schema, ['definitions', 'priorityStep', 'properties', 'to']), $document);
            self::assertSame(['$ref' => '#/definitions/priority'], JsonPath::arrayAt($schema, ['definitions', 'finding', 'properties', 'priority_basis', 'properties', 'base']), $document);
        }
        foreach (['priorityStep', 'flaggedPriority', 'noFixAdvisory'] as $definition) {
            self::assertSame(JsonPath::arrayAt($report, ['definitions', $definition]), JsonPath::arrayAt($explain, ['definitions', $definition]), 'the explain schema spells '.$definition.' as the report does');
        }
    }

    public function testTheExplainSchemaSpellsTheSameVerdictsPrioritiesAndLevels(): void
    {
        $schema = self::schema(Schemas::EXPLAIN, 1);

        self::assertSame(self::VERDICTS, JsonPath::arrayAt($schema, ['definitions', 'verdict', 'enum']));
        self::assertSame(self::PRIORITIES, JsonPath::arrayAt($schema, ['definitions', 'priority', 'enum']));
        self::assertSame(self::LEVELS, JsonPath::arrayAt($schema, ['definitions', 'finding', 'properties', 'signals', 'items', 'properties', 'level', 'enum']));
    }

    public function testABaselineEntryHoldsExactlyTheFlaggedVerdicts(): void
    {
        self::assertSame(
            self::FLAGGED,
            JsonPath::arrayAt(self::schema(Schemas::BASELINE, 1), ['properties', 'findings', 'additionalProperties', 'properties', 'verdict', 'enum'])
        );
    }

    public function testNoClosedSetCarriesKnownValues(): void
    {
        $report = self::schema(Schemas::REPORT, 1);
        $explain = self::schema(Schemas::EXPLAIN, 1);
        $config = self::schema(Schemas::CONFIG, 1);
        $closed = [
            JsonPath::arrayAt($report, ['definitions', 'verdict']),
            JsonPath::arrayAt($report, ['definitions', 'priority']),
            JsonPath::arrayAt($report, ['definitions', 'flaggedPriority']),
            JsonPath::arrayAt($report, ['definitions', 'level']),
            JsonPath::arrayAt($report, ['definitions', 'baselineStanding', 'oneOf', 0, 'properties', 'status']),
            JsonPath::arrayAt($report, ['definitions', 'envelope', 'properties', 'schema']),
            JsonPath::arrayAt($explain, ['definitions', 'verdict']),
            JsonPath::arrayAt($explain, ['definitions', 'priority']),
            JsonPath::arrayAt($explain, ['definitions', 'flaggedPriority']),
            JsonPath::arrayAt($explain, ['definitions', 'finding', 'properties', 'signals', 'items', 'properties', 'level']),
            JsonPath::arrayAt($explain, ['properties', 'lockrot', 'properties', 'schema']),
            JsonPath::arrayAt(self::schema(Schemas::BASELINE, 1), ['properties', 'findings', 'additionalProperties', 'properties', 'verdict']),
            JsonPath::arrayAt($config, ['properties', 'fail-on']),
            JsonPath::arrayAt($config, ['properties', 'install-time']),
        ];
        foreach ($closed as $i => $node) {
            self::assertArrayHasKey('enum', $node, 'closed set '.$i);
            self::assertArrayNotHasKey(KnownValues::KEYWORD, $node, 'closed set '.$i);
        }
    }

    /**
     * Signal ids are an open set, but the ids lockrot ships are its own `S<n>`, and the schemas
     * list exactly those in `x-known-values`. The report schema names them three times: `signalId`,
     * one typed `anyOf` branch per id, and a last branch with a `not` over the same list. A signal
     * added to only one of the three validates against the others by accident. The last branch has
     * no `type` and no `properties`: the strict twin closes a typed object that lists its
     * properties, and closed inside the `not` it lets every signal through, untyped.
     */
    public function testTheSignalIdsAreLockrotsOwnAndTheSchemasListThem(): void
    {
        $ids = ClosedSets::signalIds();
        self::assertNotEmpty($ids);
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^S[1-9][0-9]*$/', $id);
        }

        $report = self::schema(Schemas::REPORT, 1);
        self::assertSame($ids, JsonPath::arrayAt($report, ['definitions', 'signalId', KnownValues::KEYWORD]));
        self::assertSame(['$ref' => '#/definitions/signalId'], JsonPath::arrayAt($report, ['definitions', 'signal', 'properties', 'id']));
        self::assertSame(['$ref' => '#/definitions/signalId'], JsonPath::arrayAt($report, ['definitions', 's10', 'properties', 'blocks', 'items']));
        self::assertSame(['$ref' => '#/definitions/signalId'], JsonPath::arrayAt($report, ['definitions', 's10', 'properties', 'unchecked', 'items', 'properties', 'blocks', 'items']));

        $anyOf = JsonPath::arrayAt($report, ['definitions', 'signal', 'anyOf']);
        $unknown = array_pop($anyOf);
        self::assertIsArray($unknown, 'the last branch');
        self::assertSame(['description', 'not'], array_keys($unknown), 'the last branch holds only its not');
        self::assertSame(['properties' => ['id' => ['enum' => $ids]]], $unknown['not']);

        $branches = [];
        foreach ($anyOf as $index => $branch) {
            self::assertIsArray($branch, 'anyOf branch '.$index);
            self::assertCount(1, JsonPath::arrayAt($branch, ['properties', 'id', 'enum']), 'anyOf branch '.$index.' names one signal');
            $id = JsonPath::stringAt($branch, ['properties', 'id', 'enum', 0]);
            $branches[] = $id;
            self::assertSame(
                '#/definitions/'.strtolower($id),
                JsonPath::stringAt($branch, ['properties', 'data', '$ref']),
                'anyOf branch '.$index.' types its own signal\'s data'
            );
        }
        self::assertSame($ids, $branches);

        $explain = self::schema(Schemas::EXPLAIN, 1);
        self::assertSame(JsonPath::arrayAt($report, ['definitions', 'signalId']), JsonPath::arrayAt($explain, ['definitions', 'signalId']), 'the explain schema spells signalId as the report does');
        self::assertSame(['$ref' => '#/definitions/signalId'], JsonPath::arrayAt($explain, ['definitions', 'finding', 'properties', 'signals', 'items', 'properties', 'id']));
    }

    /**
     * Every open set, by schema file (document and number) and JSON pointer: its node, its pattern
     * (null for an integer set), the values lockrot writes, and the words docs/compatibility.md and
     * docs/schema.md name it by. The one registry: a new set is added here and nowhere else.
     *
     * @return array<string, array{array<mixed, mixed>, ?string, list<string>|list<int>, string}>
     */
    private static function openSets(): array
    {
        $report = self::schema(Schemas::REPORT, 1);
        $explain = self::schema(Schemas::EXPLAIN, 1);
        $config = self::schema(Schemas::CONFIG, 1);
        $s10Entry = ['definitions', 's10', 'properties', 'unchecked', 'items', 'properties'];
        $branchRow = ['definitions', 'metadata', 'properties', 'branches', 'items', 'properties'];

        $open = [
            'report-1 #/definitions/signalId' => [JsonPath::arrayAt($report, ['definitions', 'signalId']), self::SIGNAL_ID, ClosedSets::signalIds(), 'signal ids'],
            'report-1 #/definitions/s10/properties/unchecked/items/properties/check' => [JsonPath::arrayAt($report, array_merge($s10Entry, ['check'])), self::WORD, ['repository_activity', 'release_dates'], "S10's `check` and `reason`"],
            'report-1 #/definitions/s10/properties/unchecked/items/properties/reason' => [
                JsonPath::arrayAt($report, array_merge($s10Entry, ['reason'])),
                self::WORD,
                [NotCheckedRule::NO_TOKEN, NotCheckedRule::RATE_BUDGET, NotCheckedRule::BUDGET, NotCheckedRule::RATE_LIMIT, NotCheckedRule::FETCH_FAILED, NotCheckedRule::OFFLINE, 'undated_releases'],
                "S10's `check` and `reason`",
            ],
            'report-1 #/definitions/s6/properties/reason' => [JsonPath::arrayAt($report, ['definitions', 's6', 'properties', 'reason']), self::WORD, [PinnedRule::REASON_BRANCH_SNAPSHOT, PinnedRule::REASON_NO_STABLE_RELEASE], "S6's `reason`"],
            'report-1 #/definitions/s8/properties/floor_source/oneOf/0' => [JsonPath::arrayAt($report, ['definitions', 's8', 'properties', 'floor_source', 'oneOf', 0]), self::WORD, [PhpFloor::PROJECT, PhpFloor::TARGET], "S8's `floor_source`"],
            'report-1 #/definitions/finding/properties/libyears_unmeasured/oneOf/0' => [JsonPath::arrayAt($report, ['definitions', 'finding', 'properties', 'libyears_unmeasured', 'oneOf', 0]), self::WORD, Libyears::REASONS, "a finding's `libyears_unmeasured`"],
            'report-1 #/definitions/priorityStep/properties/reason' => [JsonPath::arrayAt($report, ['definitions', 'priorityStep', 'properties', 'reason']), self::WORD, self::PRIORITY_STEPS_1, "a `priority_basis` step's `reason`"],
            'report-1 #/definitions/noFixAdvisory/properties/reason' => [JsonPath::arrayAt($report, ['definitions', 'noFixAdvisory', 'properties', 'reason']), self::WORD, self::NO_FIX_REASONS_1, "a `no_fix_expected` item's `reason`"],
            'report-1 #/definitions/run/properties/mode' => [JsonPath::arrayAt($report, ['definitions', 'run', 'properties', 'mode']), self::WORD, Gate::MODES, '`run.mode`'],
            'report-1 #/definitions/run/properties/fail_on_kind/oneOf/0' => [JsonPath::arrayAt($report, ['definitions', 'run', 'properties', 'fail_on_kind', 'oneOf', 0]), self::WORD, self::FAIL_ON_KINDS_1, '`run.fail_on_kind`'],
            'report-1 #/definitions/gate/properties/tripped_by/items' => [JsonPath::arrayAt($report, ['definitions', 'gate', 'properties', 'tripped_by', 'items']), self::WORD, Gate::TRIPS, '`gate.tripped_by`'],
            'report-1 #/definitions/findingGate/properties/exempt_by/oneOf/0' => [JsonPath::arrayAt($report, ['definitions', 'findingGate', 'properties', 'exempt_by', 'oneOf', 0]), self::WORD, Gate::EXEMPTIONS, "a finding's `gate.exempt_by`"],
            'explain-1 #/definitions/priorityStep/properties/reason' => [JsonPath::arrayAt($explain, ['definitions', 'priorityStep', 'properties', 'reason']), self::WORD, self::PRIORITY_STEPS_1, "a `priority_basis` step's `reason`"],
            'explain-1 #/definitions/noFixAdvisory/properties/reason' => [JsonPath::arrayAt($explain, ['definitions', 'noFixAdvisory', 'properties', 'reason']), self::WORD, self::NO_FIX_REASONS_1, "a `no_fix_expected` item's `reason`"],
            'explain-1 #/definitions/finding/properties/libyears_unmeasured/oneOf/0' => [JsonPath::arrayAt($explain, ['definitions', 'finding', 'properties', 'libyears_unmeasured', 'oneOf', 0]), self::WORD, Libyears::REASONS, "a finding's `libyears_unmeasured`"],
            'explain-1 #/definitions/metadata/properties/branches/items/properties/php_blocked_by/oneOf/0' => [JsonPath::arrayAt($explain, array_merge($branchRow, ['php_blocked_by', 'oneOf', 0])), self::WORD, [PhpFloor::PROJECT, PhpFloor::TARGET], "the explanation's `php_blocked_by`"],
            'explain-1 #/definitions/metadata/properties/branches/items/properties/misses_target_php/oneOf/0' => [JsonPath::arrayAt($explain, array_merge($branchRow, ['misses_target_php', 'oneOf', 0])), self::WORD, [PhpFloor::NEEDS_NEWER, PhpFloor::STOPS_BEFORE, PhpFloor::SKIPS, PhpFloor::UNSATISFIABLE], '`misses_target_php`'],
            'explain-1 #/definitions/metadata/properties/branches/items/properties/misses_project_php/oneOf/0' => [JsonPath::arrayAt($explain, array_merge($branchRow, ['misses_project_php', 'oneOf', 0])), self::WORD, [PhpFloor::NEEDS_NEWER, PhpFloor::STOPS_BEFORE, PhpFloor::SKIPS, PhpFloor::UNSATISFIABLE], '`misses_project_php`'],
            'explain-1 #/definitions/signalId' => [JsonPath::arrayAt($explain, ['definitions', 'signalId']), self::SIGNAL_ID, ClosedSets::signalIds(), 'signal ids'],
            'report-1 #/definitions/originKind' => [JsonPath::arrayAt($report, ['definitions', 'originKind']), self::ORIGIN_KIND, PackageOrigin::KINDS, self::ORIGIN_VOCABULARY],
            'report-1 #/definitions/originRegistry/oneOf/0' => [JsonPath::arrayAt($report, ['definitions', 'originRegistry', 'oneOf', 0]), self::HOST, PackageOrigin::REGISTRIES, self::ORIGIN_VOCABULARY],
            'explain-1 #/definitions/originKind' => [JsonPath::arrayAt($explain, ['definitions', 'originKind']), self::ORIGIN_KIND, PackageOrigin::KINDS, self::ORIGIN_VOCABULARY],
            'explain-1 #/definitions/originRegistry/oneOf/0' => [JsonPath::arrayAt($explain, ['definitions', 'originRegistry', 'oneOf', 0]), self::HOST, PackageOrigin::REGISTRIES, self::ORIGIN_VOCABULARY],
            'config-1 #/properties/format' => [JsonPath::arrayAt($config, ['properties', 'format']), self::FORMAT, LockrotConfig::FORMATS, "the configuration's `format`"],
        ];
        foreach (['report-1' => $report, 'explain-1' => $explain] as $document => $schema) {
            foreach (self::noteVocabularies() as $definition => [$pattern, $known]) {
                $open[$document.' #/definitions/'.$definition] = [JsonPath::arrayAt($schema, ['definitions', $definition]), $pattern, $known, self::NOTE_VOCABULARY];
            }
        }

        return $open;
    }

    /**
     * The vocabularies of a run note's `code` and `data`, each declared once per schema under
     * `definitions` and referenced from every place that uses it, with the PHP lists they hold.
     *
     * @return array<string, array{string, list<string>}>
     */
    private static function noteVocabularies(): array
    {
        return [
            'noteCode' => [self::NOTE_CODE, RunNote::CODES],
            'forgeId' => [self::WORD, RepoRef::FORGES],
            'metadataFailureReason' => [self::WORD, MetadataFailure::REASONS],
            'advisoriesNotCheckedReason' => [self::WORD, RunNote::ADVISORIES_NOT_CHECKED_REASONS],
            'repositoryActivityNotCheckedReason' => [self::WORD, RunNote::REPOSITORY_ACTIVITY_NOT_CHECKED_REASONS],
        ];
    }

    /**
     * A run note's `data` is typed per code the way a signal's is per id: one `anyOf` branch per code
     * RunNote writes, in RunNote::CODES order, each naming the one definition that types it, and a
     * last branch holding only a `not` over the same codes, so an unknown code carries any object and
     * a known one cannot borrow that branch. The explain schema spells every definition the notes use
     * as the report does.
     */
    public function testARunNotesDataIsTypedPerCodeAndBothSchemasSpellItAlike(): void
    {
        $report = self::schema(Schemas::REPORT, 1);
        $explain = self::schema(Schemas::EXPLAIN, 1);
        foreach (['report' => $report, 'explain' => $explain] as $document => $schema) {
            self::assertSame(['type' => 'array', 'items' => ['$ref' => '#/definitions/noteDetail']], array_diff_key(JsonPath::arrayAt($schema, ['properties', 'note_details']), ['description' => true]), $document);
            self::assertNotContains('note_details', JsonPath::arrayAt($schema, ['required']), $document);
            self::assertSame(['code', 'text', 'docs_url', 'sets_network_failures', 'data'], JsonPath::arrayAt($schema, ['definitions', 'noteDetail', 'required']), $document);
            self::assertSame(['$ref' => '#/definitions/noteCode'], JsonPath::arrayAt($schema, ['definitions', 'noteDetail', 'properties', 'code']), $document);
            self::assertArrayNotHasKey('additionalProperties', JsonPath::arrayAt($schema, ['definitions', 'noteDetail']), $document.': open');
            $docsUrl = JsonPath::arrayAt($schema, ['definitions', 'noteDetail', 'properties', 'docs_url']);
            self::assertSame(['string', 'null'], $docsUrl['type'] ?? null, $document);
            self::assertArrayNotHasKey('pattern', $docsUrl, $document.': a pattern could never change under one schema number');
            self::assertArrayNotHasKey('format', $docsUrl, $document);

            $anyOf = JsonPath::arrayAt($schema, ['definitions', 'noteDetail', 'anyOf']);
            $unknown = array_pop($anyOf);
            self::assertIsArray($unknown);
            self::assertSame(['description', 'not'], array_keys($unknown), $document.': the last branch holds only its not');
            self::assertSame(['properties' => ['code' => ['enum' => RunNote::CODES]]], $unknown['not'], $document);
            $codes = [];
            foreach ($anyOf as $index => $branch) {
                self::assertIsArray($branch);
                self::assertSame(['properties'], array_keys($branch), $document.' branch '.$index);
                self::assertCount(1, JsonPath::arrayAt($branch, ['properties', 'code', 'enum']));
                $code = JsonPath::stringAt($branch, ['properties', 'code', 'enum', 0]);
                $codes[] = $code;
                self::assertSame('#/definitions/note'.str_replace('_', '', ucwords($code, '_')), JsonPath::stringAt($branch, ['properties', 'data', '$ref']), $document.' '.$code);
            }
            self::assertSame(RunNote::CODES, $codes, $document);
        }
        foreach (array_keys(JsonPath::arrayAt($report, ['definitions'])) as $name) {
            if (strpos((string) $name, 'note') === 0 || \in_array($name, ['forgeId', 'forgeRepository', 'failedForgeRepository', 'metadataFailureReason', 'advisoriesNotCheckedReason', 'repositoryActivityNotCheckedReason'], true)) {
                self::assertSame(JsonPath::arrayAt($report, ['definitions', $name]), JsonPath::arrayAt($explain, ['definitions', $name]), 'the explain schema spells '.$name.' as the report does');
            }
        }
    }

    /**
     * A finding's `origin` is one open object both schemas spell alike: its three members always
     * written, a kind and a registry from their own open sets, and a page URL with no pattern, since a
     * registry lockrot learns to link must not need a new schema number.
     */
    public function testAFindingsOriginIsAnOpenObjectBothSchemasSpellAlike(): void
    {
        $report = self::schema(Schemas::REPORT, 1);
        $explain = self::schema(Schemas::EXPLAIN, 1);
        foreach (['report' => $report, 'explain' => $explain] as $document => $schema) {
            $origin = JsonPath::arrayAt($schema, ['definitions', 'packageOrigin']);
            self::assertSame(['kind', 'registry', 'package_url'], $origin['required'] ?? null, $document);
            self::assertArrayNotHasKey('additionalProperties', $origin, $document.': open');
            self::assertSame(['$ref' => '#/definitions/originKind'], JsonPath::arrayAt($origin, ['properties', 'kind']), $document);
            self::assertSame(['$ref' => '#/definitions/originRegistry'], JsonPath::arrayAt($origin, ['properties', 'registry']), $document);
            self::assertSame(['type' => ['string', 'null']], array_diff_key(JsonPath::arrayAt($origin, ['properties', 'package_url']), ['description' => true]), $document);
            self::assertSame('#/definitions/packageOrigin', JsonPath::stringAt($schema, ['definitions', 'finding', 'properties', 'origin', '$ref']), $document);
            self::assertNotContains('origin', JsonPath::arrayAt($schema, ['definitions', 'finding', 'required']), $document);
        }
        foreach (['packageOrigin', 'originKind', 'originRegistry'] as $definition) {
            self::assertSame(JsonPath::arrayAt($report, ['definitions', $definition]), JsonPath::arrayAt($explain, ['definitions', $definition]), 'the explain schema spells '.$definition.' as the report does');
        }
    }

    /**
     * Every open set is a string with a `pattern` and the values lockrot writes in `x-known-values`,
     * or an integer with no pattern and its known integers there, never an enum, and every known
     * value fits the pattern. The patterns are spelled out, since the strict reading drops them and
     * the widening check never sees one narrowed. The known values are held to the code. The list of
     * places is complete: an `x-known-values` anywhere in a shipped file else fails.
     */
    public function testTheOpenSetsAreOpenStringsWithTheirKnownValues(): void
    {
        $report = self::schema(Schemas::REPORT, 1);
        $explain = self::schema(Schemas::EXPLAIN, 1);
        $branchRow = ['definitions', 'metadata', 'properties', 'branches', 'items', 'properties'];
        $open = self::openSets();
        foreach ($open as $where => [$node, $pattern, $known]) {
            self::assertOpenSet($where, $node, $pattern, $known);
        }
        self::assertSame(['type' => 'null'], JsonPath::arrayAt($report, ['definitions', 's8', 'properties', 'floor_source', 'oneOf', 1]), 'floor_source is otherwise null');
        self::assertCount(2, JsonPath::arrayAt($report, ['definitions', 's8', 'properties', 'floor_source', 'oneOf']));
        self::assertSame(['type' => 'null'], JsonPath::arrayAt($explain, array_merge($branchRow, ['php_blocked_by', 'oneOf', 1])), 'php_blocked_by is otherwise null');
        self::assertCount(2, JsonPath::arrayAt($explain, array_merge($branchRow, ['php_blocked_by', 'oneOf'])));
        foreach (['report' => $report, 'explain' => $explain] as $document => $schema) {
            self::assertSame(['type' => 'null'], JsonPath::arrayAt($schema, ['definitions', 'finding', 'properties', 'libyears_unmeasured', 'oneOf', 1]), $document.': libyears_unmeasured is otherwise null');
            self::assertCount(2, JsonPath::arrayAt($schema, ['definitions', 'finding', 'properties', 'libyears_unmeasured', 'oneOf']));
        }
        foreach ([['run', 'properties', 'fail_on_kind'], ['findingGate', 'properties', 'exempt_by']] as $nullable) {
            $at = array_merge(['definitions'], $nullable, ['oneOf']);
            self::assertSame(['type' => 'null'], JsonPath::arrayAt($report, array_merge($at, [1])), implode('/', $nullable).' is otherwise null');
            self::assertCount(2, JsonPath::arrayAt($report, $at));
        }
        self::assertTrue(JsonPath::arrayAt($report, ['definitions', 'gate', 'properties', 'tripped_by'])['uniqueItems'] ?? null, 'a cause is listed once');
        foreach (['report' => $report, 'explain' => $explain] as $document => $schema) {
            self::assertSame(['type' => 'null'], JsonPath::arrayAt($schema, ['definitions', 'originRegistry', 'oneOf', 1]), $document.': a registry is otherwise null');
            self::assertCount(2, JsonPath::arrayAt($schema, ['definitions', 'originRegistry', 'oneOf']));
        }
        foreach (['misses_target_php', 'misses_project_php'] as $side) {
            self::assertSame(['type' => 'null'], JsonPath::arrayAt($explain, array_merge($branchRow, [$side, 'oneOf', 1])), $side.' is otherwise null');
            self::assertCount(2, JsonPath::arrayAt($explain, array_merge($branchRow, [$side, 'oneOf'])));
        }

        $found = [];
        foreach ([Schemas::REPORT, Schemas::EXPLAIN, Schemas::CONFIG, Schemas::BASELINE] as $document) {
            foreach (Schemas::numbers($document) as $number) {
                foreach (self::withKnownValues(self::schema($document, $number), '#') as $path) {
                    $found[] = $document.'-'.$number.' '.$path;
                }
            }
        }
        sort($found);
        $expected = array_keys($open);
        sort($expected);
        self::assertSame($expected, $found);
    }

    /**
     * The clause for integer sets, on a synthetic schema that holds one: no shipped file has an
     * integer set. Its row passes, and the same node read as a string set, or with a value that
     * is no integer, fails.
     */
    public function testAnIntegerSetIsAnIntegerWithItsKnownIntegersAndNoPattern(): void
    {
        $schema = JsonPath::decodeFile(self::INTEGER_SET);
        self::assertSame(['#/properties/divisor'], self::withKnownValues($schema, '#'), 'the synthetic schema holds one open set');
        $node = JsonPath::arrayAt($schema, ['properties', 'divisor']);

        self::assertOpenSet('synthetic #/properties/divisor', $node, null, [1, 2, 4]);

        $misread = 0;
        foreach ([
            'as a string set' => [$node, self::WORD, [1, 2, 4]],
            'with a value that is no integer' => [array_merge($node, [KnownValues::KEYWORD => [1, '2', 4]]), null, [1, '2', 4]],
            'with a pattern' => [array_merge($node, ['pattern' => '^[0-9]+$']), null, [1, 2, 4]],
            'listing other values' => [$node, null, [1, 2]],
        ] as $what => [$changed, $pattern, $known]) {
            try {
                self::assertOpenSet($what, $changed, $pattern, $known);
            } catch (AssertionFailedError $expected) {
                ++$misread;
                continue;
            }
            self::fail('the clause accepts an integer set '.$what);
        }
        self::assertSame(4, $misread);
    }

    /**
     * One open set: a string with its pattern, or, with no pattern, an integer. It is never an enum.
     * Its `x-known-values` are exactly the values given, each once, each fitting the pattern or an integer.
     *
     * @param array<mixed, mixed>   $node
     * @param list<string>|list<int> $known
     */
    private static function assertOpenSet(string $where, array $node, ?string $pattern, array $known): void
    {
        self::assertArrayNotHasKey('enum', $node, $where);
        self::assertSame($known, $node[KnownValues::KEYWORD] ?? null, $where);
        self::assertSame($known, array_values(array_unique($known, \SORT_REGULAR)), $where);
        if ($pattern === null) {
            self::assertSame('integer', $node['type'] ?? null, $where);
            self::assertArrayNotHasKey('pattern', $node, $where.': an integer set has no pattern');
            foreach ($known as $value) {
                self::assertIsInt($value, $where);
            }

            return;
        }
        self::assertSame('string', $node['type'] ?? null, $where);
        self::assertSame($pattern, $node['pattern'] ?? null, $where);
        foreach ($known as $value) {
            self::assertIsString($value, $where);
            // Delimited as SchemaWidening delimits a schema pattern, so a quantifier's braces
            // or a lone `}` never end it early.
            self::assertMatchesRegularExpression("\x01".$pattern."\x01", $value, $where);
        }
    }

    /**
     * Every open set is named in the one list a consumer reads to learn which sets grow: the list
     * after "Objects are open" in docs/schema.md's "Open sets". docs/compatibility.md links it
     * rather than repeating it, so a set added to a schema has one sentence to update.
     */
    public function testEveryOpenSetIsNamedInTheSchemaPagesList(): void
    {
        $list = self::listAfter(self::section(self::page('schema.md'), '## Open sets'), 'Objects are open');
        self::assertNotSame('', $list, 'the list after "Objects are open"');

        foreach (self::openSets() as $where => [, , , $phrase]) {
            self::assertStringContainsString($phrase, $list, $where.': docs/schema.md\'s list of open sets');
        }
    }

    private static function listAfter(string $text, string $opening): string
    {
        $blocks = preg_split('/\n\s*\n/', $text) ?: [];
        foreach ($blocks as $index => $block) {
            if (strpos(ltrim($block), $opening) === 0) {
                $list = $blocks[$index + 1] ?? '';

                return strpos(ltrim($list), '- ') === 0 ? self::flat($list) : '';
            }
        }
        self::fail('no paragraph opens with "'.$opening.'"');
    }

    /** From the heading to the next heading of the same or a higher level. */
    private static function section(string $page, string $heading): string
    {
        $start = strpos($page, "\n".$heading."\n");
        self::assertNotFalse($start, $heading);
        $level = \strlen((string) strstr($heading, ' ', true));
        $rest = substr($page, $start + \strlen($heading) + 2);
        $end = preg_match('/^#{1,'.$level.'} /m', $rest, $match, \PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : \strlen($rest);

        return substr($rest, 0, $end);
    }

    private static function flat(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', $text);
    }

    private static function page(string $name): string
    {
        $contents = file_get_contents(self::ROOT.'docs/'.$name);
        self::assertIsString($contents, $name);

        return $contents;
    }

    /**
     * @param array<mixed, mixed> $node
     *
     * @return list<string>
     */
    private static function withKnownValues(array $node, string $path): array
    {
        $found = \array_key_exists(KnownValues::KEYWORD, $node) ? [$path] : [];
        foreach ($node as $key => $value) {
            if (\is_array($value) && $key !== KnownValues::KEYWORD) {
                $found = array_merge($found, self::withKnownValues($value, $path.'/'.$key));
            }
        }

        return $found;
    }

    /**
     * No name lockrot ships holds a colon, since `<vendor>:<name>` is for everyone else. No
     * configuration key starts with `x-`, at the top level or in an `ignore` entry. `extensions` at
     * the top level means nothing to lockrot. Nothing reads a `LOCKROT_X_` variable. Nothing is
     * declared under `Lockrot\Extension\`, in any letter case, since PHP class names are
     * case-insensitive.
     */
    public function testNoCoreNameUsesANameReservedForExtensions(): void
    {
        $config = self::schema(Schemas::CONFIG, 1);
        $topLevelKeys = self::keys(JsonPath::arrayAt($config, ['properties']));
        $ignoreKeys = self::keys(JsonPath::arrayAt($config, ['properties', 'ignore', 'items', 'properties']));
        $names = array_merge(Verdict::all(), Priority::all(), FailOn::allowed(), LockrotConfig::FORMATS, ClosedSets::signalIds(), [PhpFloor::PROJECT, PhpFloor::TARGET, PhpFloor::NEEDS_NEWER, PhpFloor::STOPS_BEFORE, PhpFloor::SKIPS, PhpFloor::UNSATISFIABLE], Libyears::REASONS, PriorityBasis::STEPS, NoFix::REASONS, Gate::MODES, FailOn::KINDS, Gate::TRIPS, Gate::EXEMPTIONS, RunNote::CODES, RepoRef::FORGES, MetadataFailure::REASONS, RunNote::ADVISORIES_NOT_CHECKED_REASONS, RunNote::REPOSITORY_ACTIVITY_NOT_CHECKED_REASONS, PackageOrigin::KINDS, PackageOrigin::REGISTRIES, $topLevelKeys, $ignoreKeys);

        foreach ($names as $name) {
            self::assertStringNotContainsString(':', $name, '<vendor>:<name> is reserved for names that are not lockrot\'s');
        }
        foreach (array_merge($topLevelKeys, $ignoreKeys) as $key) {
            self::assertStringStartsNotWith('x-', strtolower($key), 'extra.lockrot keys starting with x- are reserved');
        }
        self::assertNotContains('extensions', $topLevelKeys, 'extra.lockrot.extensions is reserved');

        $read = 0;
        foreach (self::shippedSources() as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source, $path);
            self::assertStringNotContainsString('LOCKROT_X_', $source, $path.': LOCKROT_X_* variables are reserved');
            self::assertDoesNotMatchRegularExpression('/^\s*namespace\s+Lockrot\\\\Extension\b/im', $source, $path.': Lockrot\\Extension\\ is reserved');
            ++$read;
        }
        self::assertGreaterThan(1, $read, 'the source tree and bin/lockrot were read');

        foreach (scandir(self::ROOT.'src') ?: [] as $entry) {
            self::assertNotSame('extension', strtolower($entry), 'src/'.$entry.' would hold Lockrot\\Extension\\, which is reserved');
        }
    }

    /**
     * Every file under src/, whatever its extension, and bin/lockrot, which has none.
     *
     * @return list<string>
     */
    private static function shippedSources(): array
    {
        $paths = [self::ROOT.'bin/lockrot'];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }

    /**
     * @param array<mixed, mixed> $properties
     *
     * @return list<string>
     */
    private static function keys(array $properties): array
    {
        return array_map('strval', array_keys($properties));
    }

    /** @return array<mixed, mixed> */
    private static function schema(string $document, int $number): array
    {
        return JsonPath::decodeFile(Schemas::path($document, $number));
    }
}
