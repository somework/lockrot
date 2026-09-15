<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Exception\ConfigException;

final class Formatters
{
    /**
     * `json` needs nothing about the run itself, so the context is optional and defaults to
     * FormatContext::unknown() for a caller that only wants that one; `table` reads the terminal
     * width out of it, and `github` and `sarif` the lock path and the fail-on threshold.
     */
    public static function for(string $format, ?FormatContext $context = null): FormatterInterface
    {
        $context ??= FormatContext::unknown();
        switch ($format) {
            case 'table':
                return new TableFormatter($context);
            case 'json':
                return new JsonFormatter();
            case 'github':
                return new GithubFormatter($context);
            case 'sarif':
                return new SarifFormatter($context);
            case 'gitlab':
                return new GitlabFormatter($context);
            case 'markdown':
                return new MarkdownFormatter($context);
            default:
                throw new ConfigException('Unknown output format "'.$format.'"');
        }
    }
}
