<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Html\ReportDocument;
use Lockrot\Json\JsonWriter;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\JsonFormatter;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\VersionMask;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionMaskTest extends TestCase
{
    private const OTHER = '9.9.9';

    /**
     * Each document kind that prints the tool version, written under two versions. The report,
     * explain and html documents read `Version::STRING`, so their second copy has the version set
     * as a newer release would write it.
     *
     * @return iterable<string, array{string, string, non-empty-list<string>}> the document, its copy, the paths that print the version
     */
    public static function documents(): iterable
    {
        $report = self::report();
        $json = (new JsonFormatter())->format($report);
        yield 'report-2' => [$json, self::rewritten($json, ['lockrot', 'version']), ['lockrot', 'version']];
        $html = (string) JsonWriter::encode((new ReportDocument($report))->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        yield 'html bundle' => [$html, self::rewritten($html, ['report', 'lockrot', 'version']), ['report', 'lockrot', 'version']];
        $baseline = static fn (string $version): string => (string) JsonWriter::encode(Baseline::of([], '2026-10-14', '8.4', 'option', $version)->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        yield 'baseline-2' => [$baseline(Version::STRING), $baseline(self::OTHER), ['lockrot', 'version']];
        $sarif = static fn (string $version): string => Formatters::for('sarif', FormatContext::create(null, 'high', $version))->format($report);
        yield 'sarif' => [$sarif(Version::STRING), $sarif(self::OTHER), ['runs', '0', 'tool', 'driver', 'version']];
    }

    /**
     * @dataProvider documents
     */
    #[DataProvider('documents')]
    public function testTwoVersionsOfADocumentAreByteIdenticalAfterTheMask(string $written, string $writtenByAnother): void
    {
        self::assertNotSame($written, $writtenByAnother);
        self::assertSame(VersionMask::apply($written), VersionMask::apply($writtenByAnother));
    }

    /**
     * @param non-empty-list<string> $path
     *
     * @dataProvider documents
     */
    #[DataProvider('documents')]
    public function testTheMaskChangesOnlyTheVersionPath(string $written, string $writtenByAnother, array $path): void
    {
        $expected = json_decode(self::rewritten($written, $path, VersionMask::VALUE), true);

        self::assertSame($expected, json_decode(VersionMask::apply($written), true));
    }

    public function testATextFormatIsLeftAsWritten(): void
    {
        $table = Formatters::for('table', FormatContext::create(null, 'high'))->format(self::report());

        self::assertSame($table, VersionMask::apply($table));
    }

    public function testTheMaskIsTheReleaseTheBundleDescribes(): void
    {
        self::assertSame('0.14.0', VersionMask::VALUE);
        self::assertSame(['lockrot.version', 'report.lockrot.version', 'runs[].tool.driver.version'], VersionMask::PATHS);
    }

    private static function report(): Report
    {
        $finding = (new FindingBuilder())->withVerdict(Verdict::STALE)->build();

        return new Report([$finding], [], new \DateTimeImmutable('2026-10-14T00:00:00+00:00'), 1, 0);
    }

    /** @param non-empty-list<string> $path */
    private static function rewritten(string $json, array $path, string $version = self::OTHER): string
    {
        $data = json_decode($json, true);
        self::assertIsArray($data);

        return JsonWriter::encode(self::replaced($data, $path, $version), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION).(substr($json, -1) === "\n" ? "\n" : '');
    }

    /**
     * @param array<mixed, mixed>    $data
     * @param non-empty-list<string> $path
     *
     * @return array<mixed, mixed>
     */
    private static function replaced(array $data, array $path, string $version): array
    {
        $key = $path[0];
        $rest = \array_slice($path, 1);
        if ($rest === []) {
            $data[$key] = $version;

            return $data;
        }
        $node = $data[$key] ?? null;
        self::assertIsArray($node);
        $data[$key] = self::replaced($node, $rest, $version);

        return $data;
    }
}
