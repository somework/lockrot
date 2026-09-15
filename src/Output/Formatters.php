<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Exception\ConfigException;

final class Formatters
{
    /**
     * `table` and `json` need nothing about the run itself, so the context is optional and defaults
     * to FormatContext::unknown() for callers that only want one of those two; `github` and `sarif`
     * read the lock path and the fail-on threshold out of it.
     */
    public static function for(string $format, ?FormatContext $context = null): FormatterInterface
    {
        $context ??= FormatContext::unknown();
        switch ($format) {
            case 'table':
                return new TableFormatter();
            case 'json':
                return new JsonFormatter();
            case 'github':
                return new GithubFormatter($context);
            case 'sarif':
                return new SarifFormatter($context);
            default:
                throw new ConfigException('Unknown output format "'.$format.'"');
        }
    }
}
