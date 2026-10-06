<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Exception\ConfigException;
use Lockrot\Html\PageData;

/** @internal */
final class Formatters
{
    /**
     * Whether $format's text needs {@see ConsoleMarkup::render()} before anyone reads it. Every
     * other format is written as it is, so a `<` in a package name reaches the parser untouched.
     */
    public static function carriesConsoleMarkup(string $format): bool
    {
        return $format === 'table';
    }

    /** Without a $page, `html` renders the report alone: {@see PageData}. */
    public static function for(string $format, ?FormatContext $context = null, ?PageData $page = null): FormatterInterface
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
            case 'html':
                return new HtmlFormatter($page);
            default:
                throw new ConfigException('Unknown output format "'.$format.'"');
        }
    }
}
