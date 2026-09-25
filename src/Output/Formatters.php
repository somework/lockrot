<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Exception\ConfigException;
use Lockrot\Html\PageData;

/**
 * Resolves a format name to its formatter.
 *
 * @internal
 */
final class Formatters
{
    /**
     * `json` needs nothing about the run itself, so the context is optional and defaults to
     * FormatContext::unknown() for a caller that only wants that one; `table` reads the terminal
     * width out of it, and `github` and `sarif` the lock path and the fail-on threshold.
     *
     * `html` is the one format that wants more than the report — the facts behind each finding, the
     * baseline comparison, the thresholds — and {@see PageData} carries them. Left out, the page
     * still renders, minus the release branches and the baseline column.
     */
    /**
     * Whether $format's text carries console markup — style tags, and `\<` escapes the console
     * undoes — and so must go through Symfony's tag formatter before anyone reads it: only `table`.
     * Every other format is written raw, so a `<` in a constraint or a package name reaches the
     * parser on the other end untouched.
     */
    public static function carriesConsoleMarkup(string $format): bool
    {
        return $format === 'table';
    }

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
