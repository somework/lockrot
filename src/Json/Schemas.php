<?php

declare(strict_types=1);

namespace Lockrot\Json;

/**
 * Where lockrot's JSON documents say their schema lives. Each document written for a machine — the
 * `--format=json` report, the `--explain` document, the baseline file — opens with a `$schema` key
 * naming the published copy of the file under resources/ that describes it, so an editor can
 * complete it and a CI step can validate it without a copy of lockrot around.
 *
 * The number in the URL is the document's schema number ({@see \Lockrot\Output\JsonFormatter::SCHEMA},
 * {@see \Lockrot\Baseline\Baseline::SCHEMA}): a file under one number only ever gains fields, so a
 * field added later validates against a copy a consumer fetched earlier; the number moves only when
 * a field is removed or renamed. Each number ships as its own file,
 * resources/lockrot-<document>-<number>.schema.json ({@see path()}), and the `id` inside it is this
 * same URL.
 *
 * Objects are open, and so are the sets of values that grow in minor releases: signal ids (a
 * signal's `id`, and each entry of S10's `blocks` array), S10's `check` and `reason`, S8's
 * `floor_source`, S6's `reason`, the explain document's `php_blocked_by` and `misses_*_php`, a
 * finding's `libyears_unmeasured`, a priority step's and a no-fix advisory's `reason`, `run.mode`,
 * `run.fail_on_kind`, `gate.tripped_by`, a finding's `gate.exempt_by`, and the config schema's
 * `format` are strings with a `pattern` and an `x-known-values` list of what this
 * release writes ({@see KnownValues}), and the report types a signal whose id it does not list with
 * a generic branch that takes any object as its data. A copy fetched from 0.13.0 on accepts a value
 * a later release adds; the closed sets — verdicts, priorities, levels, standings, a baseline
 * entry's verdict, the schema number — stay enums (docs/compatibility.md, "Open sets").
 *
 * @internal
 */
final class Schemas
{
    public const BASE_URL = 'https://lockrot.dev/schema/';

    public const REPORT = 'report';
    public const EXPLAIN = 'explain';
    public const BASELINE = 'baseline';
    public const CONFIG = 'config';

    /**
     * The numbers lockrot ships a schema file for, per document. A number stays once its file has
     * shipped: a document written under it keeps validating against the file of its number.
     */
    private const NUMBERS = [
        self::REPORT => [1],
        self::EXPLAIN => [1],
        self::BASELINE => [1],
        self::CONFIG => [1],
    ];

    /** @return non-empty-string e.g. `https://lockrot.dev/schema/report-1.json` */
    public static function url(string $document, int $schema): string
    {
        return self::BASE_URL.$document.'-'.$schema.'.json';
    }


    /** @return non-empty-list<int> the schema numbers lockrot ships a file for, oldest first */
    public static function numbers(string $document): array
    {
        if (!isset(self::NUMBERS[$document])) {
            throw new \InvalidArgumentException('lockrot ships no '.$document.' schema');
        }

        return self::NUMBERS[$document];
    }

    /** @return non-empty-string e.g. `lockrot-report-1.schema.json` */
    public static function fileName(string $document, int $number): string
    {
        return 'lockrot-'.$document.'-'.$number.'.schema.json';
    }

    /** The bundled copy of a document's schema under a number, as JsonSchema's Validator wants it. */
    public static function path(string $document, int $number): string
    {
        if (!\in_array($number, self::numbers($document), true)) {
            throw new \InvalidArgumentException('lockrot ships no '.$document.'-'.$number.' schema');
        }

        return __DIR__.'/../../resources/'.self::fileName($document, $number);
    }
}
