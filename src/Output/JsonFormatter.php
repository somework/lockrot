<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Json\JsonWriter;
use Lockrot\Json\Schemas;
use Lockrot\Version;

/**
 * The machine-readable report: docs/ci.md#-formatjson. The `lockrot` envelope and the schema number
 * are in docs/schema.md#the-document.
 *
 * @internal
 */
final class JsonFormatter implements FormatterInterface
{
    /** @see Version::STRING The release number lives there. This is its published alias. */
    public const VERSION = Version::STRING;
    public const SCHEMA = 2;

    public function format(Report $report, bool $showAll = false): string
    {
        $data = ['$schema' => Schemas::url(Schemas::REPORT, self::SCHEMA), 'lockrot' => ['version' => self::VERSION, 'schema' => self::SCHEMA]] + $report->toArray();

        $json = JsonWriter::encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        // Report data always encodes. Without this guard, a failure prints an empty document.
        if ($json === null) {
            throw new \RuntimeException('Cannot encode report as JSON: '.json_last_error_msg());
        }

        return $json."\n";
    }
}
