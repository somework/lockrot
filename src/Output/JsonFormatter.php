<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Json\Schemas;
use Lockrot\Version;

/**
 * The machine-readable report: Report::toArray() under a `lockrot` envelope carrying the tool
 * version and the document schema number, which only changes when a field is removed or renamed.
 * The document opens with `$schema`, the published resources/lockrot-report.schema.json ({@see Schemas}).
 *
 * @internal
 */
final class JsonFormatter implements FormatterInterface
{
    /** @see Version::STRING — lockrot's release number lives there; this stays as its published alias. */
    public const VERSION = Version::STRING;
    public const SCHEMA = 1;

    public function format(Report $report, bool $showAll = false): string
    {
        $data = ['$schema' => Schemas::url(Schemas::REPORT, self::SCHEMA), 'lockrot' => ['version' => self::VERSION, 'schema' => self::SCHEMA]] + $report->toArray();

        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        // Report::toArray() only ever produces scalars, arrays and ISO-8601 date strings, so this
        // is unreachable in practice; guarded explicitly so a future encoding failure fails loudly
        // instead of silently emitting the string "false".
        if ($json === false) {
            throw new \RuntimeException('Cannot encode report as JSON: '.json_last_error_msg());
        }

        return $json."\n";
    }
}
