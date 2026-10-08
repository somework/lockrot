<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Json;

use Lockrot\Json\Schemas;
use Lockrot\Output\JsonFormatter;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/**
 * Each report-2 property description that states a relation ("exactly when", "only", "null for",
 * "else null", "never") is held by the schema or listed with the reason it is not: a relation that
 * names the property in backticks encodes it, the property's own keywords hold it
 * ({@see STRUCTURAL}), or draft-04 cannot say it ({@see ENGINE}: arithmetic, list equality, a value
 * in another object or outside the document, or prose that states no cross-field rule). A claim that
 * this head writes a placeholder for is listed with the pull request that restores it ({@see PLACEHOLDERS}).
 */
final class SchemaClaimsTest extends TestCase
{
    private const CLAIM = '{exactly when|\bonly\b|null for|else null|\bnever\b}i';

    /** The claims that the property's own keywords hold, by definition and property. */
    private const STRUCTURAL = [
        'rootFlagVulnerable.leading' => 'type null',
        'rootFlagVulnerable.accepted' => 'type null',
        's9row.counted' => 'enum [true]',
        'scoreGraded.text' => 'minLength 1',
        'move.text' => 'minLength 1',
        'alsoMove.text' => 'minLength 1',
        'findingFlag.summary' => 'minLength 1',
        'findingFlag.signal_ids' => 'minItems 1',
        's3.forge' => 'type string',
        's4.forge' => 'type string',
        'run.project_php_lowest' => 'pattern',
        'findingGate/properties/by/items.facts' => 'minItems 1 on a failing value (findingGate relation)',
        'latest/oneOf/0.released' => 'nullable, with relation null ⇒ relation null by the move model; prose of when',
    ];

    /** The claims that draft-04 cannot express, each with the reason. */
    private const ENGINE = [
        'move.through' => 'engine (one entry per direct_dependents name of a transitive package, [] otherwise; the why-not commands follow newest_requires)',
        '#.exposure' => 'membership against another list (S7 counts)',
        '#.unattributed' => 'fan-in against exposure_rule.max_fan_in (a number in another object)',
        '#.libyears' => 'a sum over findings',
        'clearedFact.basis' => 'branch dates and the GA date are outside the document',
        'factBaseline/oneOf/0.state' => 'the baseline file is outside the document',
        'factBaseline/oneOf/0.since' => 'the baseline file is outside the document',
        'heldBy.constraint' => 'PROSE: "never interpreted"',
        'phpCheck.project_allows' => 'the parse of require.php (PhpFloor)',
        'termSecurity.points' => 'weight x multiplier (scoreGraded model-1 tables hold its values)',
        'modifier.divide_by' => 'encoded under model 1 (scoreGraded: every halving divides by 2); PROSE otherwise',
        'withoutRow.total' => 'a number compared to score.total (arithmetic between two fields)',
        'scoreGraded.model' => 'equal to run.score_model.id (another object)',
        'replacement/oneOf/0.package' => 'a name test against the package itself and finding.replacement (cross-object equality)',
        'replacement/oneOf/0.suggestion' => 'PROSE: "never markup"',
        'replacement/oneOf/0.url' => 'PROSE: "never build one"',
        'replacement/oneOf/0.finding' => 'another finding of the same report',
        'move.fix_kind' => 'encoded for `vulnerable` sources (move relation); the clears-every-advisory case needs clears against security.counts',
        'alsoMove.fix_kind' => 'as move.fix_kind',
        'move.commands' => 'encoded for the kinds and transitive require/tag (finding relation); the argv contents are engine',
        'alsoMove.commands' => 'as move.commands',
        'branchFixes.unknown' => 'PROSE: "never not fixed"',
        'ignoredAdvisory.reason' => 'PROSE: "never inside a machine string"',
        'securityVulnerable.ignored' => 'disjoint from the S9 rows: list disjointness',
        'securityClear.ignored' => 'as securityVulnerable.ignored',
        'securityUnchecked.ignored' => 'as securityVulnerable.ignored',
        'securityVulnerable.gets' => 'release data outside the document',
        'headline.value' => 'per unit (headline anyOf); the years reading is engine',
        'findingFlag.role' => 'PROSE: "never this word" (the join rule)',
        'findingFlag.degree' => 'per flag id (findingFlag anyOf)',
        'checkMissing.check' => 'PROSE',
        'allowlist/oneOf/0.reason' => 'PROSE: "never entered into a machine string"',
        'allowlist/oneOf/0.reason_id' => 'encoded (allowlist relation: by builtin/type ⇒ reason_id, project ⇒ null)',
        'allowlist/oneOf/0.flag_ids' => 'the config file is outside the document',
        'metadata.status' => 'encoded (finding: read ⇔ maintenance_judged); the origin clause is engine',
        'findingBaseline/oneOf/0/properties/recorded/oneOf/0.fix_model' => 'the baseline file is outside the document',
        's1.replacement' => 'PROSE: "never markup"',
        's1.replacement_url' => 'PROSE: "show a link only when it is a string"',
        's6.snapshot_time' => 'S6 reason against the lock time (outside)',
        's7/properties/packages/items.flag_ids' => 'PROSE: the name rule',
        's9row.title' => 'PROSE: "never markup"',
        'finding.branch' => 'equal to S8 and the branch row (cross-object equality)',
        'finding.maintenance_judged' => 'encoded (finding relation with metadata.status)',
        'finding.allowlist_reason' => 'encoded (finding relation: set only beside a whole entry)',
        'packageOrigin.package_url' => 'a registry fact outside the document',
        'scoreRule.kind' => 'PROSE',
        'scoreRule.illustration' => 'PROSE: keys vary by rule',
        'scoreRule.accept_group' => 'PROSE: a parameter',
        'scoreRule/properties/tie_break/items.collation' => 'PROSE: a direction word',
        'scoreModel.score_text_grammar' => 'PROSE',
        'scoreModel.exclusive_groups' => 'PROSE: a model fact (ScoreModelTest)',
        'scoreModel/properties/flags/items.points' => 'per flag id: ScoreModelTest',
        'scoreModel/properties/flags/items.band' => 'per flag id: ScoreModelTest',
        'scoreModel/properties/flags/items.corroborating_points' => 'ScoreModelTest',
        'scoreModel/properties/flags/items/properties/basis.v013_base' => 'ScoreModelTest',
        'scoreModel/properties/exclusive_groups/items.holds_unless' => 'PROSE',
        'scoreModel/properties/sort/items.collation' => 'PROSE: a direction word',
        'run.project_php' => 'PROSE: "never parse it"',
        'run.lock_file' => 'PROSE: "never its path"',
        'run.fix_model' => 'baselines are outside the document',
        'run.score_rules_used' => 'counts over findings (a sum)',
        'noteAdvisoryIgnoreUnreadable.message' => 'PROSE',
        'noteDetail.text' => 'equality with notes[i] (two lists)',
        'noteDetail.docs_url' => 'PROSE: "never build it"',
        'baselineComparison.path' => 'a file path outside the document',
        'rootSecurity/properties/packages.unchecked' => 'a count over findings',
        's9fix.reason' => 'encoded (s9fix relation: unknown/none ⇒ reason)',
        'securityVulnerable/properties/partial/oneOf/0/properties/if_applied.at_most' => 'unverified is a sibling of if_applied in partial: encoded there when present; else engine',
        'findingBaseline/oneOf/0/properties/worsened_by/items.recorded' => 'encoded (worsened_by relation)',
        'findingBaseline/oneOf/0/properties/worsened_by/items.now' => 'encoded (worsened_by relation)',
        '#/properties/libyears.unmeasured' => 'membership: each non-null libyears_unmeasured of a finding is one of its keys (a count over findings)',
        '#/properties/libyears/properties/unmeasured.no_stable_release_date' => 'PROSE: what the reason covers',
    ];

    /**
     * The claims that this head writes a placeholder for, by the pull request that restores the
     * rule, and the text of the strict relation that the schema leaves out until then (null when
     * no relation says it).
     */
    private const PLACEHOLDERS = [
        'findingGate.by' => ['PR 6a: gate.by names the values a failing finding fails', 'names the values it fails'],
        'findingGate.exempt_by' => ['PR 5: exempt_by baseline only under a baseline entry', '`exempt_by: baseline` only there'],
        'finding.next_step' => ['PR 4c: a graded finding has a move', 'and `next_step` agree'],
        'heldBy.holder' => ['PR 4c: the holder object, filled in a second pass', null],
        'branchFixes.lowest' => ['PR 4c: the easiest release of the window (P2b)', null],
        'branchFixes.if_applied' => ['PR 4c: the score after a move to the candidate', null],
        'securityVulnerable.fix_kind' => ['PR 4c: the fix_kind of the move that move_in names', null],
        'rootSecurity.update_now' => ['PR 4c: counted from the move fix_kind', null],
    ];

    /** The claims that a relation of another definition encodes. */
    private const ENCODED_AT = [
        'partMaintenance.status' => 'scoreGraded: the maintenance part counts exactly when a maintenance term exists; partMaintenance: counts exactly when it contributes',
        'headline.source' => 'headline\'s own per-unit branches: `php`, `reason` and `advisories` type `source` null',
    ];

    public function testEveryRelationADescriptionStatesIsEncodedOrListed(): void
    {
        $claims = self::claims(self::schema());

        self::assertNotSame([], $claims);
        self::assertSame([], array_keys(array_filter($claims, static fn (string $class): bool => $class === 'unclassified')));
    }

    public function testEveryListedClaimIsStillAClaim(): void
    {
        $claims = self::claims(self::schema());

        self::assertSame([], array_values(array_diff(array_merge(array_keys(self::STRUCTURAL), array_keys(self::ENGINE), array_keys(self::ENCODED_AT)), array_keys($claims))));
    }

    public function testEachPlaceholderIsAPropertyThatNoRelationEncodes(): void
    {
        $schema = self::schema();
        $relations = self::allRelations($schema);

        self::assertNotSame([], $relations);
        foreach (self::PLACEHOLDERS as $key => [$restoredBy, $strict]) {
            [$definition, $property] = explode('.', $key);
            self::assertTrue(JsonPath::has($schema, ['definitions', $definition, 'properties', $property]), $key.' is a property of the schema');
            if ($strict === null) {
                continue;
            }
            foreach ($relations as $relation) {
                self::assertStringNotContainsString($strict, $relation, $key.' is encoded: take it off PLACEHOLDERS ('.$restoredBy.')');
            }
        }
    }

    public function testAClaimWithoutARelationIsFound(): void
    {
        $schema = ['definitions' => ['thing' => [
            'properties' => [
                'a' => ['description' => 'Null exactly when `b` is null.'],
                'b' => ['description' => 'Set only when `c` is true.'],
                'c' => ['description' => 'A plain fact.'],
            ],
            'allOf' => [['description' => 'The `.b` relation.']],
        ]]];

        self::assertSame(['thing.a' => 'unclassified', 'thing.b' => 'encoded'], self::claims($schema));
    }

    /** @return array<mixed, mixed> */
    private static function schema(): array
    {
        $decoded = json_decode((string) file_get_contents(Schemas::path(Schemas::REPORT, JsonFormatter::SCHEMA)), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Each claim once, in the first class that holds it: encoded, structural, engine, unclassified.
     *
     * @param array<mixed, mixed> $schema
     *
     * @return array<string, string> class by claim key
     */
    private static function claims(array $schema): array
    {
        $definitions = \is_array($schema['definitions'] ?? null) ? $schema['definitions'] : [];
        $global = array_merge(self::relations($definitions['finding'] ?? []), self::relations($definitions['run'] ?? []), self::relations($schema));
        $claims = [];
        self::walk($schema, '#', null, $global, $claims);

        return $claims;
    }

    /**
     * @param mixed                 $node
     * @param mixed                 $owner  the definition that holds the node
     * @param list<string>          $global the relations of `finding`, `run` and the root
     * @param array<string, string> $claims
     */
    private static function walk($node, string $path, $owner, array $global, array &$claims): void
    {
        if (!\is_array($node)) {
            return;
        }
        $properties = $node['properties'] ?? null;
        if (\is_array($properties) && strpos($path, '/allOf') === false && strpos($path, '/anyOf') === false) {
            foreach ($properties as $name => $property) {
                $description = \is_array($property) ? ($property['description'] ?? null) : null;
                if (!\is_string($description) || preg_match(self::CLAIM, $description) !== 1) {
                    continue;
                }
                $at = strpos($path, '#/definitions/');
                $key = ($at === false ? $path : substr($path, $at + \strlen('#/definitions/'))).'.'.$name;
                $relations = array_merge(self::relations($node), self::relations($owner), $global);
                $claims[$key] = self::classOf($key, (string) $name, $relations);
            }
        }
        foreach ($node as $key => $child) {
            self::walk($child, $path.'/'.$key, $path === '#/definitions' ? $child : $owner, $global, $claims);
        }
    }

    /** @param list<string> $relations */
    private static function classOf(string $key, string $name, array $relations): string
    {
        if (isset(self::PLACEHOLDERS[$key])) {
            return 'placeholder';
        }
        foreach ($relations as $relation) {
            if (preg_match('{[`.]'.preg_quote($name, '{').'`}', $relation) === 1) {
                return 'encoded';
            }
        }
        if (isset(self::ENCODED_AT[$key])) {
            return 'encoded';
        }
        if (isset(self::STRUCTURAL[$key])) {
            return 'structural';
        }

        return isset(self::ENGINE[$key]) ? 'engine' : 'unclassified';
    }

    /**
     * The descriptions of every `allOf` entry and `anyOf` branch in the schema.
     *
     * @param mixed $node
     *
     * @return list<string>
     */
    private static function allRelations($node): array
    {
        if (!\is_array($node)) {
            return [];
        }
        $out = self::relations($node);
        foreach ($node as $child) {
            $out = array_merge($out, self::allRelations($child));
        }

        return $out;
    }

    /**
     * The descriptions of the node's `allOf` entries and `anyOf` branches.
     *
     * @param mixed $node
     *
     * @return list<string>
     */
    private static function relations($node): array
    {
        $out = [];
        foreach (['allOf', 'anyOf'] as $keyword) {
            foreach (\is_array($node) && \is_array($node[$keyword] ?? null) ? $node[$keyword] : [] as $relation) {
                if (\is_array($relation) && \is_string($relation['description'] ?? null)) {
                    $out[] = $relation['description'];
                }
            }
        }

        return $out;
    }
}
