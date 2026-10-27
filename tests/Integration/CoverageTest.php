<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Json\Schemas;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The positive coverage of the -2 schemas, a port of `security-axis/r3/schema/coverage.py`: every
 * value that an `enum` or `x-known-values` lists is carried by a committed valid document at that
 * schema node, or is on the reviewed allow-list with its reason. A relation group (`allOf`) and a
 * `not` list values to constrain them, not values to write, so neither is a target.
 *
 * Each document is walked through its schema: `$ref`s resolved, a `oneOf` or `anyOf` branch
 * entered only when its one-value `enum` members fit the value. explain-2 copies report-2's
 * finding definitions, so a node inside a copied definition counts report-2's documents too.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class CoverageTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const DOCUMENTS = __DIR__.'/../fixtures/schema/documents/';
    private const SETS = [
        Schemas::REPORT => ['corpus/*.json.gz', 'variants/*.json', 'cases/*.json', 'coverage/baselined/*.json.gz', 'coverage/variants/*.json.gz'],
        Schemas::EXPLAIN => ['explain/*.json', 'explain/coverage/*.json'],
        Schemas::BASELINE => ['baseline/*/*.json'],
        Schemas::CONFIG => ['config/*.json', 'config/*/*.json'],
    ];
    private const D = '#/definitions/';
    private const NOT_REACHED = 'not reached: no corpus finding and no case has the input that writes it';

    /** @var array<string, array<string, array<int|string, true>>> by schema, the values seen at each `pointer keyword` */
    private array $seen = [];

    public function testEveryListedValueIsCarriedByADocumentOrAllowedWithItsReason(): void
    {
        $schemas = [];
        $counts = [];
        foreach (self::SETS as $name => $patterns) {
            $schema = json_decode((string) file_get_contents(Schemas::path($name, 2)));
            self::assertInstanceOf(\stdClass::class, $schema);
            $schemas[$name] = $schema;
            $this->seen[$name] = [];
            $paths = [];
            foreach ($patterns as $pattern) {
                $paths = array_merge($paths, glob(self::DOCUMENTS.$pattern) ?: []);
            }
            $counts[$name] = \count($paths);
            foreach ($paths as $path) {
                $this->record($name, $schema, $schema, '#', json_decode((string) file_get_contents(substr($path, -3) === '.gz' ? 'compress.zlib://'.$path : $path)));
            }
        }
        self::assertSame([Schemas::REPORT => 352, Schemas::EXPLAIN => 268, Schemas::BASELINE => 141, Schemas::CONFIG => 79], $counts, 'tests/fixtures/schema/documents/provenance.json');

        $shared = [];
        foreach ((array) $schemas[Schemas::EXPLAIN]->definitions as $definition => $node) {
            if (isset($schemas[Schemas::REPORT]->definitions->{$definition}) && json_encode($node) === json_encode($schemas[Schemas::REPORT]->definitions->{$definition})) {
                $shared[$definition] = true;
            }
        }
        $allowed = self::allowed();
        $missing = [];
        foreach (array_keys(self::SETS) as $name) {
            foreach (self::targets($schemas[$name]) as $key => $values) {
                [$pointer] = explode(' ', $key);
                $got = $this->seen[$name][$key] ?? [];
                $segments = explode('/', $pointer);
                if ($name === Schemas::EXPLAIN && strpos($pointer, self::D) === 0 && isset($shared[$segments[2]])) {
                    $got += $this->seen[Schemas::REPORT][$key] ?? [];
                }
                foreach ($values as $value) {
                    if (!isset($got[$value]) && !isset($allowed[$name.' '.$pointer.' '.$value])) {
                        $missing[] = $name.' '.$key.' '.json_encode($value);
                    }
                }
            }
        }

        self::assertSame([], $missing, 'a listed value no document carries: add a document that writes it, or allow it with its reason');
    }

    /**
     * The explain-2, baseline-2 and config-2 documents are valid as published, and the baseline-2
     * and config-2 ones against the strict twin unless the name ends in `.open.json`. SchemaRelationsTest
     * validates the report-2 cases. Each corpus-scale report takes seconds to validate, so the model's
     * suite validates those (provenance.json) and this suite reads them for coverage only.
     */
    public function testTheExplainBaselineAndConfigDocumentsAreValid(): void
    {
        $invalid = [];
        foreach ([Schemas::EXPLAIN, Schemas::BASELINE, Schemas::CONFIG] as $name) {
            foreach (self::SETS[$name] as $pattern) {
                foreach (glob(self::DOCUMENTS.$pattern) ?: [] as $path) {
                    $json = (string) file_get_contents($path);
                    $strict = $name !== Schemas::EXPLAIN && substr($path, -10) !== '.open.json';
                    foreach ($strict ? [false, true] : [false] as $twin) {
                        foreach ($this->errors($name, $json, $twin, 2) as $error) {
                            $invalid[] = basename($path).($twin ? ' (strict)' : '').' '.$error;
                        }
                    }
                }
            }
        }

        self::assertSame([], \array_slice($invalid, 0, 20), \count($invalid).' errors');
    }

    /**
     * @param mixed $node
     * @param mixed $value
     */
    private function record(string $name, \stdClass $root, $node, string $pointer, $value): void
    {
        [$node, $pointer] = self::resolve($root, $node, $pointer);
        if (!$node instanceof \stdClass) {
            return;
        }
        if (!\is_array($value) && !$value instanceof \stdClass && !\is_bool($value)) {
            foreach (self::listed($node) as $keyword => $values) {
                if (\in_array($value, $values, true)) {
                    $this->seen[$name][$pointer.' '.$keyword][$value] = true;
                }
            }
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            foreach (\is_array($node->{$keyword} ?? null) ? $node->{$keyword} : [] as $i => $branch) {
                if (self::fits($root, $branch, $value)) {
                    $this->record($name, $root, $branch, $pointer.'/'.$keyword.'/'.$i, $value);
                }
            }
        }
        if ($value instanceof \stdClass) {
            $properties = $node->properties ?? new \stdClass();
            $patterns = (array) ($node->patternProperties ?? new \stdClass());
            foreach ((array) $value as $key => $child) {
                $key = (string) $key;
                $matched = false;
                if (isset($properties->{$key})) {
                    $matched = true;
                    $this->record($name, $root, $properties->{$key}, $pointer.'/properties/'.$key, $child);
                }
                foreach ($patterns as $regex => $sub) {
                    if (preg_match('~'.str_replace('~', '\~', (string) $regex).'~u', $key) === 1) {
                        $matched = true;
                        $this->record($name, $root, $sub, $pointer.'/patternProperties/'.$regex, $child);
                    }
                }
                if (!$matched && ($node->additionalProperties ?? null) instanceof \stdClass) {
                    $this->record($name, $root, $node->additionalProperties, $pointer.'/additionalProperties', $child);
                }
            }
        } elseif (\is_array($value) && ($node->items ?? null) instanceof \stdClass) {
            foreach ($value as $child) {
                $this->record($name, $root, $node->items, $pointer.'/items', $child);
            }
        }
    }

    /**
     * Every `enum` and `x-known-values` list of the schema, by `pointer keyword`.
     *
     * @return array<string, list<int|string>>
     */
    private static function targets(\stdClass $root): array
    {
        $out = [];
        $seen = [];
        $walk = static function ($node, string $pointer) use (&$walk, &$out, &$seen, $root): void {
            [$node, $pointer] = self::resolve($root, $node, $pointer);
            if (!$node instanceof \stdClass || isset($seen[$pointer])) {
                return;
            }
            $seen[$pointer] = true;
            foreach (self::listed($node) as $keyword => $values) {
                $out[$pointer.' '.$keyword] = $values;
            }
            foreach ((array) ($node->properties ?? []) as $key => $sub) {
                $walk($sub, $pointer.'/properties/'.$key);
            }
            foreach ((array) ($node->patternProperties ?? []) as $key => $sub) {
                $walk($sub, $pointer.'/patternProperties/'.$key);
            }
            if (($node->additionalProperties ?? null) instanceof \stdClass) {
                $walk($node->additionalProperties, $pointer.'/additionalProperties');
            }
            if (($node->items ?? null) instanceof \stdClass) {
                $walk($node->items, $pointer.'/items');
            }
            foreach (['oneOf', 'anyOf'] as $keyword) {
                foreach (\is_array($node->{$keyword} ?? null) ? $node->{$keyword} : [] as $i => $branch) {
                    $walk($branch, $pointer.'/'.$keyword.'/'.$i);
                }
            }
        };
        $walk($root, '#');

        return $out;
    }

    /**
     * A branch is entered when each of its one-value enum members fits the value (a
     * discriminator), or it has none.
     *
     * @param mixed $branch
     * @param mixed $value
     */
    private static function fits(\stdClass $root, $branch, $value): bool
    {
        [$branch] = self::resolve($root, $branch, '');
        if (!$branch instanceof \stdClass) {
            return true;
        }
        if (isset($branch->type)) {
            $types = \is_array($branch->type) ? $branch->type : [$branch->type];
            $kind = $value instanceof \stdClass ? 'object' : (\is_array($value) ? 'array' : (\is_string($value) ? 'string' : (\is_bool($value) ? 'boolean' : ($value === null ? 'null' : (\is_int($value) ? 'integer' : 'number')))));
            if (!\in_array($kind, $types, true) && !($kind === 'integer' && \in_array('number', $types, true))) {
                return false;
            }
        }
        if (\is_array($branch->enum ?? null) && !\in_array($value, $branch->enum, false)) {
            return false;
        }
        if ($value instanceof \stdClass) {
            foreach ((array) ($branch->properties ?? []) as $key => $sub) {
                [$sub] = self::resolve($root, $sub, '');
                if ($sub instanceof \stdClass && \is_array($sub->enum ?? null) && \count($sub->enum) === 1) {
                    if (!property_exists($value, (string) $key) || !\in_array($value->{$key}, $sub->enum, false)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param mixed $node
     *
     * @return array{0: mixed, 1: string}
     */
    private static function resolve(\stdClass $root, $node, string $pointer): array
    {
        for ($hops = 0; $node instanceof \stdClass && isset($node->{'$ref'}) && $hops < 64; ++$hops) {
            $segments = explode('/', \is_string($node->{'$ref'}) ? $node->{'$ref'} : '');
            $name = end($segments);
            $node = $root->definitions->{$name};
            $pointer = self::D.$name;
        }

        return [$node, $pointer];
    }

    /** @return array<string, list<int|string>> */
    private static function listed(\stdClass $node): array
    {
        $out = [];
        foreach (['enum', 'x-known-values'] as $keyword) {
            $values = $node->{$keyword} ?? null;
            $listed = [];
            foreach (\is_array($values) ? $values : [] as $value) {
                if (!\is_string($value) && !\is_int($value)) {
                    continue 2;
                }
                $listed[] = $value;
            }
            if ($listed !== []) {
                $out[$keyword] = $listed;
            }
        }

        return $out;
    }

    /**
     * The reviewed values that no generated document carries, each with why: a value the model
     * cannot produce from the corpus or a variant without an input lockrot never sees, or one a
     * later release reserves. Keyed `schema pointer value`.
     *
     * @return array<string, string>
     */
    private static function allowed(): array
    {
        $shared = [
            [self::D.'alsoMove/properties/kind', ['replace', 'find-alternative', 'tag', 'update', 'raise-php', 'test', 'blocked', 'no-tag', 'no-move', 'no-single-fix'],
                'an also[] move shares the move kinds, and every corpus and case also[] move is the security move beside a maintenance lead. next_step carries each other kind'],
            [self::D.'alsoMove/properties/composer_moves_because/items', ['holders', 'transitive', 'unreached'], 'as alsoMove.kind: next_step.composer_moves_because carries every value'],
            [self::D.'alsoMove/properties/reason/oneOf/0', ['package_quiet', 'no_higher_release', 'higher_undated', 'releases_unknown', 'affected_range_unknown', 'metadata_not_read', 'local_package', 'no_release_admits'],
                'as alsoMove.kind: a no-move reason belongs to a maintenance move, which no also[] move is'],
            [self::D.'holder/oneOf/0/properties/next_step/oneOf/0/properties/kind', ['replace', 'find-alternative', 'tag', 'update', 'test', 'blocked', 'no-tag', 'no-move', 'no-fix', 'no-single-fix'],
                "a holder's next_step copies the holding finding's own kind, and the finding's own next_step carries every other kind"],
            [self::D.'move/properties/reason/oneOf/0', ['no_higher_release', 'higher_undated'], self::NOT_REACHED.' (a left-behind lead with no higher branch releasing, or none dated)'],
            [self::D.'advisoriesNotCheckedReason', ['lookup_failed'], 'lockrot writes advisories_unavailable for a feed that fails, and RunNote::advisoriesNotChecked() refuses lookup_failed'],
            [self::D.'pushUnmeasured', ['undated'], self::NOT_REACHED.' (an activity answer that carried no date)'],
            [self::D.'libyearsUnmeasured', ['undated'], self::NOT_REACHED.' (an undated installed release of a package whose metadata was read)'],
            [self::D.'advisoryFeed/properties/registry/oneOf/0', ['wp-packages.org'], 'wp-packages.org has no advisory feed, so feeds[] never names it'],
            [self::D.'headline/anyOf/2/properties/value', ['no_stable_release'], self::NOT_REACHED.' (a pinned package with no stable release ever)'],
            [self::D.'s6/properties/reason', ['no_stable_release'], 'as headline: pinned with no stable release ever'],
            [self::D.'replacement/oneOf/0/properties/named_by', ['lock'], self::NOT_REACHED." (a replacement that composer.lock's abandoned field names)"],
            [self::D.'s8/properties/floor_source/oneOf/0', ['target'], self::NOT_REACHED." (S8's PHP floor taken from the target)"],
            [self::D.'unverifiedFact/properties/reason', ['no_release_data'], self::NOT_REACHED.' (a move whose release has no release data)'],
            [self::D.'withoutRow/properties/revealed/items/properties/role', ['accepted'], self::NOT_REACHED.' (a without[] row that reveals an accepted flag)'],
            [self::D.'securityVulnerable/properties/partial/oneOf/0/properties/if_applied/properties/assumes/items', ['composer_resolves', 'no_new_advisories'],
                self::NOT_REACHED.' (a security.partial on a held package). next_step.if_applied.assumes carries each value'],
            [self::D.'branchFixes/properties/if_applied/oneOf/0/properties/assumes/items', ['no_new_advisories'],
                self::NOT_REACHED.' (a branch row that fixes something beside an incomplete lookup). next_step.if_applied.assumes carries the value'],
            [self::D.'noteUpdateNowNotChecked/properties/reason', ['baseline_refused', 'unloaded_name', 'offline', 'not_from_composer_repository', 'no_composer_json', 'composer_json_invalid', 'no_release_read', 'pass_cap', 'solver_error', 'advisory_lookup_incomplete'],
                "the note's reason is a value of check.reason, which the move-check documents carry each. A document carries install_time_budget"],
            [self::D.'noteAdvisoriesDisabledByPolicy/properties/policy_key', ['policy.advisories.audit', 'COMPOSER_POLICY'],
                'the other keys that switch off advisory blocking. The documents run under policy and policy.advisories'],
            [self::D.'noteAbandonedNotAuditedByComposer/properties/policy_key', ['COMPOSER_POLICY'],
                'P1: COMPOSER_POLICY=0 also stops the abandoned audit. The model documents predate the key'],
            [self::D.'noteRepositoryActivityHostNotSupported/properties/hosts/items/properties/listed_in/oneOf/0', ['forgejo-domains'],
                "PR 4d: a host in Composer's forgejo-domains. The model documents predate the list"],
            [self::D.'scoreModel/properties/exclusive_groups/items/properties/holds_unless/oneOf/0/properties/below', ['push-warn-years', 'push-high-years', 'release-high-years'],
                "score model 1's own parameters: the list names every threshold a later model can use"],
            [self::D.'scoreModel/properties/exclusive_groups/items/properties/holds_unless/oneOf/0/properties/threshold', ['push-high-years', 'release-warn-years', 'release-high-years'], 'as holds_unless.below'],
            [self::D.'scoreRule/properties/of', ['counted', 'lead-first', 'corroborating-share', 'advisory-points', 'no-reachable-fix-multiplier', 'security-max',
                'security-exempt-from-reach', 'sum', 'divide-dev', 'floor-once', 'band-floors', 'zero-verdicts', 'sort', 'gate-bounded-new', 'baseline-cover'],
                "score model 1's own parameters: model 1 names divide-reach only"],
            [self::D.'throughEntry/properties/newest_requires_unmeasured/oneOf/0', ['releases_unknown', 'no_stable_release'], self::NOT_REACHED.' (a direct dependent without metadata or a stable release)'],
        ];
        $out = [];
        foreach ($shared as [$pointer, $values, $why]) {
            foreach ([Schemas::REPORT, Schemas::EXPLAIN] as $name) {
                foreach ($values as $value) {
                    $out[$name.' '.$pointer.' '.$value] = $why;
                }
            }
        }
        foreach (['skips', 'unsatisfiable'] as $value) {
            foreach (['misses_project_php', 'misses_target_php', 'highest_misses_project_php'] as $key) {
                $out[Schemas::EXPLAIN.' '.self::D.'repositoryMetadata/properties/branches/items/properties/'.$key.'/oneOf/0 '.$value] = "0.13's branch-row values: the details blocks carry needs_newer and needs_older only";
            }
        }

        return $out;
    }
}
