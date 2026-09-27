<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Analyzer\RunNote;

/**
 * Run notes with any text, for the tests of how a format prints a note whatever it says: markup, a
 * second line, a `%`. No run writes these; each carries the code `test:note`, the `<vendor>:<name>`
 * form no lockrot code takes, and no docs URL.
 */
final class Notes
{
    public const CODE = 'test:note';

    public static function text(string $text, bool $setsNetworkFailures = false): RunNote
    {
        $code = self::CODE;
        // RunNote's constructor is private so that a note's text is always its data's; only a test may bypass that.
        $build = \Closure::bind(static fn (): RunNote => new RunNote($code, $text, null, $setsNetworkFailures, []), null, RunNote::class);

        return $build();
    }

    /**
     * @param list<string> $texts
     *
     * @return list<RunNote>
     */
    public static function texts(array $texts, bool $setsNetworkFailures = false): array
    {
        return array_map(static fn (string $text): RunNote => self::text($text, $setsNetworkFailures), $texts);
    }
}
