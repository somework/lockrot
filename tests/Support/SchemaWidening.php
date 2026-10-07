<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Json\KnownValues;

/**
 * Whether a newer JSON schema accepts every document an older one accepts: the rule docs/schema.md
 * states for one schema number, checked on the two schema files rather than on documents.
 *
 * {@see narrowings()} lists every way the newer schema is narrower, node by node. It fails closed on
 * a keyword outside COMPARED and ANNOTATIONS: one in the newer schema is reported unless the older node carries it
 * with the same value. Where it cannot be exact, it reports.
 */
final class SchemaWidening
{
    private const COMPARED = [
        '$ref', 'type', 'enum', 'oneOf', 'anyOf', 'properties', 'required', 'items', 'additionalProperties',
        'minimum', 'maximum', 'minItems', 'maxItems', 'minLength', 'maxLength', 'pattern', 'format',
        'patternProperties', 'allOf', 'not', 'uniqueItems', 'minProperties', 'multipleOf',
    ];

    /** The relation keywords that bear on a single value, which {@see accepts()} does not decide. */
    private const VALUE_RELATIONS = ['allOf', 'not', 'multipleOf'];

    /** Keywords that say nothing about which documents validate. */
    private const ANNOTATIONS = [
        '$schema', 'id', '$id', 'title', 'description', 'default', 'examples', 'definitions',
        KnownValues::KEYWORD, KnownValues::KEYS, 'x-rendered-from',
    ];

    private const ALTERNATIVES = ['oneOf', 'anyOf'];

    /** What an absent lower bound means: none for a number, 0 for a length. An absent upper bound is none. */
    private const LOWER = ['minimum' => null, 'minItems' => 0, 'minLength' => 0];

    /** Set on a merged newer node whose parts disagree on a keyword a single node cannot hold twice. */
    private const UNMERGEABLE = "\0unmergeable";

    private const MAX_DEPTH = 64;

    /** Set on an older node whose `not` {@see withoutNot()} folded in, for the comparison of the two `not`s. */
    private const OLD_NOT = "\0oldnot";

    /** @var array<mixed, mixed> */
    private array $oldRoot;

    /** @var array<mixed, mixed> */
    private array $newRoot;

    /**
     * @param array<mixed, mixed> $oldRoot
     * @param array<mixed, mixed> $newRoot
     */
    private function __construct(array $oldRoot, array $newRoot)
    {
        $this->oldRoot = $oldRoot;
        $this->newRoot = $newRoot;
    }

    /**
     * @param array<mixed, mixed> $old a schema as json_decode(…, true) returns it
     * @param array<mixed, mixed> $new
     *
     * @return list<string> every narrowing, each naming the path in the older schema's terms. Empty when the newer schema only widens
     */
    public static function narrowings(array $old, array $new): array
    {
        $old = self::strictly($old);
        $new = self::strictly($new);

        return array_values(array_unique((new self($old, $new))->compare($old, $new, '#', 0)));
    }

    /**
     * Both schemas are read strictly ({@see KnownValues::closed()}): an open set's `x-known-values`
     * is its enum and its `pattern` is dropped, and an open map's `x-known-keys` are its properties,
     * each typed by every regex it matches. No older lockrot wrote a value outside the list, because
     * the strict twin in ValidatesJsonSchemas holds its output to it. A value or key dropped from a
     * list is a narrowing, and one added is not. This check does not see a known value that stops
     * matching a narrowed pattern, so ClosedSetsTest spells out each open set's pattern.
     *
     * @param array<mixed, mixed> $schema
     *
     * @return array<mixed, mixed>
     */
    private static function strictly(array $schema): array
    {
        $object = json_decode((string) json_encode($schema));
        if (!$object instanceof \stdClass) {
            throw new \UnexpectedValueException('a schema is an object: '.self::show($schema));
        }
        $read = json_decode((string) json_encode(KnownValues::closed($object)), true);
        if (!\is_array($read)) {
            throw new \UnexpectedValueException('the strict reading of a schema is an object');
        }

        return $read;
    }

    /**
     * @param array<mixed, mixed> $old
     * @param array<mixed, mixed> $new
     *
     * @return list<string>
     */
    private function compare(array $old, array $new, string $path, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [$path.': nested too deep to compare'];
        }
        if ($this->identical($old, $new, [])) {
            return [];
        }
        try {
            $resolved = $this->resolve($old, $this->oldRoot);
            $old = $this->withoutNot($resolved);
            if ($old !== null && \array_key_exists('not', $resolved)) {
                $old[self::OLD_NOT] = $resolved['not'];
            }
            $new = $this->resolve($new, $this->newRoot);
        } catch (\UnexpectedValueException $e) {
            return [$path.': '.$e->getMessage()];
        }
        if ($old === null) {
            // A branch that admits nothing: no older document can have taken it.
            return [];
        }

        $oldBranches = $this->branches($old, $this->oldRoot, false);
        if ($oldBranches !== null) {
            $problems = [];
            foreach ($oldBranches as $label => $branch) {
                $problems = array_merge($problems, $this->compare($branch, $new, $path.'/'.$label, $depth + 1));
            }

            return $problems;
        }

        $newBranches = $this->branches($new, $this->newRoot, true);
        if ($newBranches === null || self::finiteValues($old) !== null) {
            // Finitely many older values are checked one by one, each against every newer branch.
            return $this->plain($old, $new, $path, $depth);
        }

        $oldTypes = self::types($old) ?? [];
        if (\count($oldTypes) > 1) {
            // `["string", "null"]` against `oneOf: [string, null]`: each type must find its branch.
            $problems = [];
            foreach ($oldTypes as $type) {
                $problems = array_merge($problems, $this->compare(['type' => $type] + $old, $new, $path, $depth + 1));
            }

            return $problems;
        }

        return $this->bestBranch($old, $newBranches, $path, $depth);
    }

    /**
     * The problems of the newer branch that fits the older node best: none when one fits, else those
     * of the branch that narrows it in the fewest places — the branch it targets, as a rule,
     * whose problems are the ones worth reading.
     *
     * @param array<mixed, mixed>                $old
     * @param array<string, array<mixed, mixed>> $branches
     *
     * @return list<string>
     */
    private function bestBranch(array $old, array $branches, string $path, int $depth): array
    {
        $best = null;
        $fewest = \PHP_INT_MAX;
        foreach ($branches as $branch) {
            if ($this->excludesAll($branch, $old)) {
                continue;
            }
            $problems = $this->compare($old, $branch, $path, $depth + 1);
            if ($problems === []) {
                return [];
            }
            $places = \count(array_unique(array_map(static fn (string $problem): string => (string) strstr($problem, ': ', true), $problems)));
            if ($places < $fewest || ($places === $fewest && \count($problems) < \count($best ?? []))) {
                $best = $problems;
                $fewest = $places;
            }
        }

        return $best ?? [$path.': no branch left to accept it'];
    }

    /**
     * An older node with its `not` folded in: the values of the one property it names that are left
     * once it has excluded its own, or null when none are — the node then admits nothing. A `not` of
     * any other shape, or over a property the node does not type as finitely many values, is dropped.
     *
     * @param array<mixed, mixed> $old resolved
     *
     * @return null|array<mixed, mixed>
     */
    private function withoutNot(array $old): ?array
    {
        if (!\array_key_exists('not', $old)) {
            return $old;
        }
        $excluded = self::excluded($old['not']);
        unset($old['not']);
        $values = $excluded === null ? null : $this->oldValuesOf($old, $excluded[0]);
        if ($excluded === null || $values === null) {
            return $old;
        }
        [$name, $list] = $excluded;
        $left = array_values(array_filter($values, static fn ($value): bool => !\in_array($value, $list, true)));
        if ($left === []) {
            return null;
        }
        $properties = self::map($old['properties'] ?? [], 'properties');
        $properties[$name] = ['enum' => $left] + $this->resolve(self::node($properties[$name], $name), $this->oldRoot);
        $old['properties'] = $properties;

        return $old;
    }

    /**
     * Whether a newer branch's `not` excludes every value the older node's property can hold, so
     * that the branch accepts none of the older node's documents.
     *
     * @param array<mixed, mixed> $branch
     * @param array<mixed, mixed> $old
     */
    private function excludesAll(array $branch, array $old): bool
    {
        $excluded = self::excluded($branch['not'] ?? null);
        if ($excluded === null) {
            return false;
        }
        try {
            $values = $this->oldValuesOf($old, $excluded[0]);
        } catch (\UnexpectedValueException $e) {
            return false;
        }

        return $values !== null && array_filter($values, static fn ($value): bool => !\in_array($value, $excluded[1], true)) === [];
    }

    /**
     * The values an older node's property admits, when finitely many; null when there are more, or
     * the node does not list the property.
     *
     * @param array<mixed, mixed> $old
     *
     * @return null|list<mixed>
     */
    private function oldValuesOf(array $old, string $name): ?array
    {
        $properties = self::map($old['properties'] ?? [], 'properties');
        if (!\array_key_exists($name, $properties)) {
            return null;
        }

        return self::finiteValues($this->resolve(self::node($properties[$name], $name), $this->oldRoot));
    }

    /**
     * The property and the values that a `not` of exactly `{properties: {K: {enum: L}}}` excludes.
     * That shape is the report's branch for signal ids it does not list. Null for any other `not`.
     *
     * @param mixed $not
     *
     * @return null|array{string, list<mixed>}
     */
    private static function excluded($not): ?array
    {
        if (!\is_array($not) || array_keys($not) !== ['properties'] || !\is_array($not['properties']) || \count($not['properties']) !== 1) {
            return null;
        }
        foreach ($not['properties'] as $name => $schema) {
            if (\is_array($schema) && array_keys($schema) === ['enum'] && \is_array($schema['enum'])) {
                return [(string) $name, array_values($schema['enum'])];
            }
        }

        return null;
    }

    /**
     * Two nodes without alternatives, both resolved.
     *
     * @param array<mixed, mixed> $old
     * @param array<mixed, mixed> $new
     *
     * @return list<string>
     */
    private function plain(array $old, array $new, string $path, int $depth): array
    {
        $problems = array_merge($this->uncompared($old, $new, $path), $this->relations($old, $new, $path));

        $values = self::finiteValues($old);
        if ($values !== null) {
            foreach ($values as $value) {
                if (!$this->accepts($value, $new, $depth + 1)) {
                    $problems[] = $path.': no longer accepts '.self::show($value);
                }
            }

            return $problems;
        }

        $oldTypes = self::types($old);
        $newTypes = self::types($new);
        if ($newTypes !== null) {
            if ($oldTypes === null) {
                $problems[] = $path.': type restricted to '.implode('|', $newTypes).', was any';
            } else {
                $lost = array_values(array_filter(
                    $oldTypes,
                    static fn (string $type): bool => !self::typeAdmits($newTypes, $type)
                ));
                if ($lost !== []) {
                    $problems[] = $path.': no longer accepts type '.implode('|', $lost);
                }
            }
        }
        if (\array_key_exists('enum', $new)) {
            $problems[] = $path.': enum '.self::show($new['enum']).' where any value was accepted';
        }

        $admits = static fn (string $type): bool => $oldTypes === null
            || self::typeAdmits($oldTypes, $type)
            || ($type === 'number' && \in_array('integer', $oldTypes, true));
        if ($admits('string')) {
            $problems = array_merge($problems, $this->bounds($old, $new, $path, ['minLength'], ['maxLength']), $this->sameText($old, $new, $path, ['pattern', 'format']));
        }
        if ($admits('number')) {
            $problems = array_merge($problems, $this->bounds($old, $new, $path, ['minimum'], ['maximum']));
        }
        if ($admits('array')) {
            $problems = array_merge($problems, $this->bounds($old, $new, $path, ['minItems'], ['maxItems']), $this->items($old, $new, $path, $depth));
        }
        if ($admits('object')) {
            $problems = array_merge($problems, $this->object($old, $new, $path, $depth));
        }

        return $problems;
    }

    /**
     * @param array<mixed, mixed> $old
     * @param array<mixed, mixed> $new
     *
     * @return list<string>
     */
    private function object(array $old, array $new, string $path, int $depth): array
    {
        $problems = [];
        $made = array_values(array_diff(self::strings($new['required'] ?? [], $path), self::strings($old['required'] ?? [], $path)));
        if ($made !== []) {
            $problems[] = $path.': made required '.implode(', ', $made);
        }

        $oldProperties = self::map($old['properties'] ?? [], $path);
        $newProperties = self::map($new['properties'] ?? [], $path);
        // A property that only the newer node lists is not compared: no older document carries it, since
        // the strict twin in ValidatesJsonSchemas holds lockrot's output to the listed fields. A config key
        // that a later release starts to list can already sit with another meaning, outside this check.
        foreach ($oldProperties as $name => $schema) {
            $at = $path.'/properties/'.$name;
            if (!\array_key_exists($name, $newProperties)) {
                $problems[] = $at.': no longer listed';

                continue;
            }
            $problems = array_merge($problems, $this->compare(self::node($schema, $at), self::node($newProperties[$name], $at), $at, $depth + 1));
        }
        $problems = array_merge($problems, $this->patternProperties($old, $new, $path, $depth));

        // Members the older node does not list: none when it lists its properties (the strict twin
        // closes such a node), anything when it lists none and leaves additionalProperties open.
        $oldOthers = $old['additionalProperties'] ?? ($oldProperties === [] ? [] : false);
        $newOthers = $new['additionalProperties'] ?? [];
        if ($newOthers === false && ($old['additionalProperties'] ?? true) !== false) {
            $problems[] = $path.': closed, additionalProperties false';
        } elseif (\is_array($newOthers) && $newOthers !== [] && \is_array($oldOthers)) {
            $problems = array_merge($problems, $this->compare($oldOthers, $newOthers, $path.'/additionalProperties', $depth + 1));
        }

        return $problems;
    }

    /**
     * An open map's keys, per regex. A map read through `x-known-keys` becomes properties in
     * {@see strictly()}, and is compared there. A regex that only the newer node holds beside a
     * closed `additionalProperties` widens only when no older key can match it. A literal regex is
     * tested against each older regex, and any other must accept what every older regex's schema
     * accepted, since whether two regexes overlap is undecidable.
     *
     * @param array<mixed, mixed> $old
     * @param array<mixed, mixed> $new
     *
     * @return list<string>
     */
    private function patternProperties(array $old, array $new, string $path, int $depth): array
    {
        $problems = [];
        $oldPatterns = self::map($old['patternProperties'] ?? [], $path);
        $newPatterns = self::map($new['patternProperties'] ?? [], $path);
        foreach ($oldPatterns as $regex => $schema) {
            $at = $path.'/patternProperties/'.$regex;
            if (!\array_key_exists($regex, $newPatterns)) {
                $problems[] = $at.': pattern property removed or its regex changed';

                continue;
            }
            $problems = array_merge($problems, $this->compare(self::node($schema, $at), self::node($newPatterns[$regex], $at), $at, $depth + 1));
        }
        foreach (array_diff_key($newPatterns, $oldPatterns) as $regex => $schema) {
            $at = $path.'/patternProperties/'.$regex;
            if (($old['additionalProperties'] ?? true) !== false) {
                // The older node's other keys, whatever they were, must match its schema.
                $problems[] = $at.': a pattern property added where the older node left other keys open';

                continue;
            }
            // A literal regex holds no metacharacter between its anchors.
            $literal = preg_match('/^\^([^\\\\.^$|?*+()\[\]{}]+)\$$/', (string) $regex, $match) === 1 ? $match[1] : null;
            foreach ($oldPatterns as $oldRegex => $oldSchema) {
                if ($literal !== null && !self::matches((string) $oldRegex, $literal)) {
                    continue;
                }
                foreach ($this->compare(self::node($oldSchema, $at), self::node($schema, $at), $at, $depth + 1) as $problem) {
                    $problems[] = $problem.' (a key '.$oldRegex.' admits may also match the added regex)';
                }
            }
        }

        return $problems;
    }

    /** Whether a schema regex matches a key, delimited as justinrainbow/json-schema delimits it. */
    private static function matches(string $regex, string $key): bool
    {
        return preg_match('~'.str_replace('~', '\\~', $regex).'~u', $key) === 1;
    }

    /**
     * @param array<mixed, mixed> $old
     * @param array<mixed, mixed> $new
     *
     * @return list<string>
     */
    private function items(array $old, array $new, string $path, int $depth): array
    {
        if (!\array_key_exists('items', $new)) {
            return [];
        }
        $at = $path.'/items';
        $newItems = self::node($new['items'], $at);
        $oldItems = self::node($old['items'] ?? [], $at);
        if (self::isTuple($newItems) || self::isTuple($oldItems)) {
            return [$at.': tuple items are not compared'];
        }

        return $this->compare($oldItems, $newItems, $at, $depth + 1);
    }

    /**
     * @param array<mixed, mixed> $old
     * @param array<mixed, mixed> $new
     * @param list<string>        $lower
     * @param list<string>        $upper
     *
     * @return list<string>
     */
    private function bounds(array $old, array $new, string $path, array $lower, array $upper): array
    {
        $problems = [];
        foreach ($lower as $keyword) {
            $was = $old[$keyword] ?? self::LOWER[$keyword];
            $is = $new[$keyword] ?? null;
            if ($is !== null && ($was === null || self::number($is, $path) > self::number($was, $path))) {
                $problems[] = $path.': '.$keyword.' raised to '.self::show($is);
            }
        }
        foreach ($upper as $keyword) {
            $was = $old[$keyword] ?? null;
            $is = $new[$keyword] ?? null;
            if ($is !== null && ($was === null || self::number($is, $path) < self::number($was, $path))) {
                $problems[] = $path.': '.$keyword.' lowered to '.self::show($is);
            }
        }

        return $problems;
    }

    /**
     * A `pattern` or `format` cannot be proven wider than another, so only the same one passes.
     *
     * @param array<mixed, mixed> $old
     * @param array<mixed, mixed> $new
     * @param list<string>        $keywords
     *
     * @return list<string>
     */
    private function sameText(array $old, array $new, string $path, array $keywords): array
    {
        $problems = [];
        foreach ($keywords as $keyword) {
            if (\array_key_exists($keyword, $new) && ($old[$keyword] ?? null) !== $new[$keyword]) {
                $problems[] = $path.': '.$keyword.' '.self::show($new[$keyword]).' where the older schema had '.self::show($old[$keyword] ?? null);
            }
        }

        return $problems;
    }

    /**
     * The relations: an `allOf` entry that the newer node adds or changes, unless the older node holds
     * the same entry, a `not` that differs, `uniqueItems` turned on, a raised `minProperties` and a
     * `multipleOf` added or changed. A release that adds a value to an open set adds such an `allOf`
     * entry, and lists it in SchemaWideningTest::ACCEPTED_NARROWINGS with its reason.
     *
     * @param array<mixed, mixed> $old
     * @param array<mixed, mixed> $new
     *
     * @return list<string>
     */
    private function relations(array $old, array $new, string $path): array
    {
        $problems = [];
        $oldEntries = self::node($old['allOf'] ?? [], $path.'/allOf');
        foreach (self::node($new['allOf'] ?? [], $path.'/allOf') as $i => $entry) {
            $held = array_filter($oldEntries, fn ($was): bool => $this->identical($was, $entry, []));
            if ($held === []) {
                $problems[] = $path.'/allOf/'.$i.': a relation the older schema did not hold';
            }
        }
        $oldNot = $old[self::OLD_NOT] ?? ($old['not'] ?? null);
        if (\array_key_exists('not', $new) && !$this->identical($oldNot, $new['not'], [])) {
            $problems[] = $path.': not '.self::show($new['not']).' where the older schema had '.self::show($oldNot);
        }
        if (($new['uniqueItems'] ?? false) === true && ($old['uniqueItems'] ?? false) !== true) {
            $problems[] = $path.': uniqueItems turned on';
        }
        if (isset($new['minProperties']) && self::number($new['minProperties'], $path) > self::number($old['minProperties'] ?? 0, $path)) {
            $problems[] = $path.': minProperties raised to '.self::show($new['minProperties']);
        }
        if (isset($new['multipleOf']) && (!isset($old['multipleOf']) || (float) self::number($new['multipleOf'], $path) !== (float) self::number($old['multipleOf'], $path))) {
            $problems[] = $path.': multipleOf '.self::show($new['multipleOf']).' where the older schema had '.self::show($old['multipleOf'] ?? null);
        }

        return $problems;
    }

    /**
     * Whether two nodes are the same text, every `$ref` in them naming a definition that is the same
     * in both roots, each followed once.
     *
     * @param mixed               $old
     * @param mixed               $new
     * @param array<string, true> $seen
     */
    private function identical($old, $new, array $seen): bool
    {
        if ($old !== $new) {
            return false;
        }
        foreach (array_keys(self::refsOf($old)) as $reference) {
            if (isset($seen[$reference])) {
                continue;
            }
            $seen[$reference] = true;
            try {
                $before = $this->resolve(['$ref' => $reference], $this->oldRoot);
                $after = $this->resolve(['$ref' => $reference], $this->newRoot);
            } catch (\UnexpectedValueException $e) {
                return false;
            }
            if (!$this->identical($before, $after, $seen)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every `$ref` a node holds, at any depth.
     *
     * @param mixed $node
     *
     * @return array<string, true>
     */
    private static function refsOf($node): array
    {
        if (!\is_array($node)) {
            return [];
        }
        $references = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && \is_string($value)) {
                $references[$value] = true;
            } else {
                $references += self::refsOf($value);
            }
        }

        return $references;
    }

    /**
     * Keywords of the newer node this check cannot compare, unless the older node says the same.
     *
     * @param array<mixed, mixed> $old
     * @param array<mixed, mixed> $new
     *
     * @return list<string>
     */
    private function uncompared(array $old, array $new, string $path): array
    {
        $problems = [];
        foreach (self::strings($new[self::UNMERGEABLE] ?? [], $path) as $keyword) {
            $problems[] = $path.': '.$keyword.' differs between a node and its '.implode('/', self::ALTERNATIVES).' branch, which this check does not compare';
        }
        foreach ($new as $keyword => $value) {
            if ($keyword === self::UNMERGEABLE || \in_array($keyword, self::COMPARED, true) || \in_array($keyword, self::ANNOTATIONS, true)) {
                continue;
            }
            if (!\array_key_exists($keyword, $old) || $old[$keyword] !== $value) {
                $problems[] = $path.': '.$keyword.' is a keyword this check does not compare';
            }
        }

        return $problems;
    }

    /**
     * Whether the newer node accepts one value. Anything it cannot decide counts as not accepted.
     *
     * @param mixed               $value
     * @param array<mixed, mixed> $node
     */
    private function accepts($value, array $node, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }
        try {
            $node = $this->resolve($node, $this->newRoot);
        } catch (\UnexpectedValueException $e) {
            return false;
        }
        $branches = $this->branches($node, $this->newRoot, true);
        if ($branches !== null) {
            foreach ($branches as $branch) {
                if ($this->accepts($value, $branch, $depth + 1)) {
                    return true;
                }
            }

            return false;
        }
        if ($this->uncompared([], $node, '') !== [] || array_intersect_key($node, array_flip(self::VALUE_RELATIONS)) !== [] || \is_array($value)) {
            return false;
        }

        $types = self::types($node);
        if ($types !== null && !self::typeAdmits($types, self::typeOf($value))) {
            return false;
        }
        if (\array_key_exists('enum', $node) && !\in_array($value, self::node($node['enum'], 'enum'), true)) {
            return false;
        }
        if (\is_string($value)) {
            return $this->acceptsString($value, $node);
        }
        if (\is_int($value) || \is_float($value)) {
            return (!isset($node['minimum']) || $value >= self::number($node['minimum'], 'minimum'))
                && (!isset($node['maximum']) || $value <= self::number($node['maximum'], 'maximum'));
        }

        return true;
    }

    /** @param array<mixed, mixed> $node */
    private function acceptsString(string $value, array $node): bool
    {
        if (isset($node['format'])) {
            return false;
        }
        $length = \count(preg_split('//u', $value, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        if (isset($node['minLength']) && $length < self::number($node['minLength'], 'minLength')) {
            return false;
        }
        if (isset($node['maxLength']) && $length > self::number($node['maxLength'], 'maxLength')) {
            return false;
        }
        if (isset($node['pattern'])) {
            $pattern = $node['pattern'];
            // Delimited by a byte no schema pattern holds, which PHP does not pair up the way it
            // pairs `{` with `}`: the pattern goes in as written, quantifiers and a lone `}` alike.
            // A pattern that does hold the byte is one this check cannot decide.
            $delimiter = "\x01";

            return \is_string($pattern)
                && strpos($pattern, $delimiter) === false
                && preg_match($delimiter.$pattern.$delimiter.'u', $value) === 1;
        }

        return true;
    }

    /**
     * The node's `oneOf`/`anyOf` split into branches, each joined with the rest of the node, or null
     * when it has none. Only the first of the two is split here, and the other stays on every branch
     * until the branch is compared. `oneOf` is read as `anyOf`: lockrot uses it only for a value or
     * null, whose branches never overlap.
     *
     * @param array<mixed, mixed> $node resolved
     * @param array<mixed, mixed> $root
     *
     * @return null|array<string, array<mixed, mixed>>
     */
    private function branches(array $node, array $root, bool $newer): ?array
    {
        foreach (self::ALTERNATIVES as $keyword) {
            if (!\array_key_exists($keyword, $node)) {
                continue;
            }
            $rest = $node;
            unset($rest[$keyword]);
            $branches = [];
            foreach (self::node($node[$keyword], $keyword) as $i => $branch) {
                $label = $keyword.'/'.$i;
                $branches[$label] = $this->merge($rest, $this->resolve(self::node($branch, $label), $root), $root, $newer);
            }

            return $branches;
        }

        return null;
    }

    /**
     * Two nodes an instance must satisfy both of, as one node. For the older schema a keyword the
     * two cannot share is dropped, which only widens the older node and so can only add a reported
     * narrowing. For the newer one it is marked, and reported.
     *
     * @param array<mixed, mixed> $a
     * @param array<mixed, mixed> $b
     * @param array<mixed, mixed> $root
     *
     * @return array<mixed, mixed>
     */
    private function merge(array $a, array $b, array $root, bool $newer): array
    {
        $merged = $a;
        foreach ($b as $keyword => $value) {
            if (!\array_key_exists($keyword, $merged) || $merged[$keyword] === $value || \in_array($keyword, self::ANNOTATIONS, true)) {
                $merged[$keyword] ??= $value;

                continue;
            }
            $mine = $merged[$keyword];
            switch ($keyword) {
                case 'type':
                    $merged['type'] = self::bothTypes(self::types($a) ?? [], self::types($b) ?? []);

                    break;
                case 'enum':
                    $theirs = self::node($value, 'enum');
                    $merged['enum'] = array_values(array_filter(self::node($mine, 'enum'), static fn ($v): bool => \in_array($v, $theirs, true)));

                    break;
                case 'required':
                    $merged['required'] = array_values(array_unique(array_merge(self::strings($mine, 'required'), self::strings($value, 'required'))));

                    break;
                case 'properties':
                    $properties = self::map($mine, 'properties');
                    foreach (self::map($value, 'properties') as $name => $schema) {
                        $properties[$name] = \array_key_exists($name, $properties)
                            ? $this->merge($this->resolve(self::node($properties[$name], $name), $root), $this->resolve(self::node($schema, $name), $root), $root, $newer)
                            : $schema;
                    }
                    $merged['properties'] = $properties;

                    break;
                case 'items':
                    $merged['items'] = $this->merge($this->resolve(self::node($mine, 'items'), $root), $this->resolve(self::node($value, 'items'), $root), $root, $newer);

                    break;
                case 'minimum':
                case 'minItems':
                case 'minLength':
                    $merged[$keyword] = max(self::number($mine, $keyword), self::number($value, $keyword));

                    break;
                case 'maximum':
                case 'maxItems':
                case 'maxLength':
                    $merged[$keyword] = min(self::number($mine, $keyword), self::number($value, $keyword));

                    break;
                default:
                    if ($newer) {
                        $merged[self::UNMERGEABLE] = array_merge(self::strings($merged[self::UNMERGEABLE] ?? [], $keyword), [(string) $keyword]);
                    }
            }
        }

        return $merged;
    }

    /**
     * Follows `$ref` to the node it names. Draft-04 ignores whatever else sits beside a `$ref`.
     *
     * @param array<mixed, mixed> $node
     * @param array<mixed, mixed> $root
     *
     * @return array<mixed, mixed>
     *
     * @throws \UnexpectedValueException for a reference outside the file, or one that names nothing
     */
    private function resolve(array $node, array $root): array
    {
        for ($hops = 0; \array_key_exists('$ref', $node); ++$hops) {
            $reference = $node['$ref'];
            if ($hops > self::MAX_DEPTH || !\is_string($reference) || strpos($reference, '#/') !== 0) {
                throw new \UnexpectedValueException('$ref '.self::show($reference).' is not one this check follows');
            }
            $target = $root;
            foreach (explode('/', substr($reference, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                if (!\is_array($target) || !\array_key_exists($segment, $target)) {
                    throw new \UnexpectedValueException('$ref '.$reference.' names nothing');
                }
                $target = $target[$segment];
            }
            if (!\is_array($target)) {
                throw new \UnexpectedValueException('$ref '.$reference.' names no schema');
            }
            $node = $target;
        }

        return $node;
    }

    /**
     * The values a node admits when there are finitely many: its `enum` (those of its type), or
     * every value of a node typed only null and boolean. Null when there are infinitely many.
     *
     * @param array<mixed, mixed> $node
     *
     * @return null|list<mixed>
     */
    private static function finiteValues(array $node): ?array
    {
        $types = self::types($node);
        if (\array_key_exists('enum', $node)) {
            return array_values(array_filter(
                self::node($node['enum'], 'enum'),
                static fn ($value): bool => $types === null || self::typeAdmits($types, self::typeOf($value))
            ));
        }
        if ($types === null || array_diff($types, ['null', 'boolean']) !== []) {
            return null;
        }
        $values = [];
        if (\in_array('null', $types, true)) {
            $values[] = null;
        }
        if (\in_array('boolean', $types, true)) {
            $values[] = true;
            $values[] = false;
        }

        return $values;
    }

    /**
     * @param array<mixed, mixed> $node
     *
     * @return null|list<string> null when the node does not restrict the type
     */
    private static function types(array $node): ?array
    {
        if (!\array_key_exists('type', $node)) {
            return null;
        }

        return \is_string($node['type']) ? [$node['type']] : self::strings($node['type'], 'type');
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<string>
     */
    private static function bothTypes(array $a, array $b): array
    {
        $both = [];
        foreach ($a as $type) {
            if (self::typeAdmits($b, $type)) {
                $both[] = $type;
            } elseif ($type === 'number' && \in_array('integer', $b, true)) {
                $both[] = 'integer';
            }
        }

        return array_values(array_unique($both));
    }

    /** @param list<string> $types */
    private static function typeAdmits(array $types, string $type): bool
    {
        return \in_array($type, $types, true) || ($type === 'integer' && \in_array('number', $types, true));
    }

    /** @param mixed $value */
    private static function typeOf($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (\is_bool($value)) {
            return 'boolean';
        }
        if (\is_int($value)) {
            return 'integer';
        }
        if (\is_float($value)) {
            return 'number';
        }
        if (\is_string($value)) {
            return 'string';
        }

        return \is_array($value) && ($value === [] || array_keys($value) === range(0, \count($value) - 1)) ? 'array' : 'object';
    }

    /** @param array<mixed, mixed> $node */
    private static function isTuple(array $node): bool
    {
        return $node !== [] && array_keys($node) === range(0, \count($node) - 1);
    }

    /**
     * @param mixed $value
     *
     * @return array<mixed, mixed>
     */
    private static function node($value, string $where): array
    {
        if ($value === true) {
            return [];
        }
        if (!\is_array($value)) {
            throw new \UnexpectedValueException($where.' is not a schema: '.self::show($value));
        }

        return $value;
    }

    /**
     * @param mixed $value
     *
     * @return array<string, mixed>
     */
    private static function map($value, string $where): array
    {
        $map = [];
        foreach (self::node($value, $where) as $key => $member) {
            $map[(string) $key] = $member;
        }

        return $map;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private static function strings($value, string $where): array
    {
        $strings = [];
        foreach (self::node($value, $where) as $member) {
            if (!\is_string($member)) {
                throw new \UnexpectedValueException($where.' holds '.self::show($member).', not a string');
            }
            $strings[] = $member;
        }

        return $strings;
    }

    /**
     * @param mixed $value
     *
     * @return float|int
     */
    private static function number($value, string $where)
    {
        if (!\is_int($value) && !\is_float($value)) {
            throw new \UnexpectedValueException($where.' holds '.self::show($value).', not a number');
        }

        return $value;
    }

    /** @param mixed $value */
    private static function show($value): string
    {
        return (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
