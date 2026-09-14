<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;

final class JsonFormatter implements FormatterInterface
{
    public const VERSION = '0.1.0';
    public const SCHEMA = 1;

    public function format(Report $report, bool $showAll = false): string
    {
        $data = ['lockrot' => ['version' => self::VERSION, 'schema' => self::SCHEMA]] + $report->toArray();

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
