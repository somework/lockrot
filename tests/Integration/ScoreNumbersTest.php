<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\Contract\ContractBundle;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * SPEC-0.14.md §8.5.4: a whole number is written without `.0` in json, sarif and the html embed, so
 * a reader that compares bytes or prints a value sees `4`, never `4.0`. SARIF's `rank` keeps its
 * zero fraction.
 *
 * @coversNothing
 */
#[CoversNothing]
final class ScoreNumbersTest extends TestCase
{
    /** A JSON number token with a zero fraction, outside a string. */
    private const ZERO_FRACTION = '{(?<![\w."])(-?\d+)\.0(?![\d])}';

    public function testNoRecordedDocumentWritesAWholeNumberWithAZeroFraction(): void
    {
        $found = [];
        $files = array_merge(...array_map(static fn (string $dir): array => glob(ContractBundle::DIRECTORY.'/'.$dir.'/*.json') ?: [], ['report-2', 'explain-2', 'baseline-2', 'sarif', 'gitlab']));
        $files = array_merge($files, glob(__DIR__.'/../fixtures/baselined/*/check/*.json') ?: []);
        self::assertNotSame([], $files, 'record the bundle with tools/report/record');
        foreach ($files as $file) {
            $json = self::withoutStrings((string) file_get_contents($file));
            if (strpos($file, '/sarif/') !== false || substr($file, -11) === '.sarif.json') {
                $json = (string) preg_replace('{"rank":\s*\d+\.\d+}', '"rank": 0', $json);
            }
            if (preg_match_all(self::ZERO_FRACTION, $json, $matches) > 0) {
                $found[basename(\dirname($file)).'/'.basename($file)] = array_values(array_unique($matches[0]));
            }
        }

        self::assertSame([], $found);
    }

    /** The JSON text with every string replaced by "": a version or a date never reads as a number. */
    private static function withoutStrings(string $json): string
    {
        return (string) preg_replace('{"(?:[^"\\\\]|\\\\.)*"(?!\s*:)}', '""', $json);
    }
}
