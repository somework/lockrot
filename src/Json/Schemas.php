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
 * a field is removed or renamed. The `id` inside each resources/*.schema.json is this same URL.
 *
 * Objects are open, but some values are still enums: signal ids, S10's `check`, `reason` and
 * `blocks`, S8's `floor_source` and the config schema's `format`. A new value there needs a schema
 * update, and a copy fetched or vendored before it rejects the value until it is refreshed;
 * describing them as open strings is planned before 1.0 (docs/compatibility.md, "Open sets").
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

    /** @return non-empty-string e.g. `https://lockrot.dev/schema/report-1.json` */
    public static function url(string $document, int $schema): string
    {
        return self::BASE_URL.$document.'-'.$schema.'.json';
    }

    /** The bundled copy of a document's schema, as JsonSchema's Validator wants it. */
    public static function path(string $document): string
    {
        return __DIR__.'/../../resources/lockrot-'.$document.'.schema.json';
    }
}
