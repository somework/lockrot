<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Analyzer\Report;
use Lockrot\Clock;
use Lockrot\Config\LockrotConfig;
use Lockrot\Output\FormatContext;
use Lockrot\Output\GitlabFormatter;
use Lockrot\Output\HtmlFormatter;
use Lockrot\Output\JsonFormatter;
use Lockrot\Output\SarifFormatter;
use Lockrot\Signal\AgeMeasure;
use Lockrot\Signal\Rule\LeftBehindRule;
use Lockrot\Signal\Rule\NoPushRule;
use Lockrot\Signal\Rule\NoReleaseRule;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Unit\Signal\FactsBuilder;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Years are integer tenths on the run clock (SPEC §2.1): `intdiv(10 * Δs + SPY / 2, SPY) / 10`,
 * Δs clamped to 0, thresholds on the exact ratio, and every JSON writer pinned to the shortest
 * float form, so an inherited `serialize_precision=17` cannot print `6.9000000000000004`.
 */
final class YearsOnTheRunClockTest extends TestCase
{
    private const NOW = '2026-10-01T00:00:00+00:00';

    private static function ago(int $seconds): string
    {
        return (new \DateTimeImmutable(self::NOW))->modify(\sprintf('%+d seconds', -$seconds))->format(\DATE_ATOM);
    }

    /** @return iterable<string, array{int, float}> seconds before the run clock => published years */
    public static function rows(): iterable
    {
        $half = intdiv(Clock::SECONDS_PER_YEAR, 20);
        yield '0.05 years exactly is the first 0.1' => [$half, 0.1];
        yield 'one second below 0.05 years is 0' => [$half - 1, 0.0];
        yield 'a release after the run clock reads 0' => [-30 * 86400, 0.0];
        yield 'seven years' => [7 * Clock::SECONDS_PER_YEAR, 7.0];
        yield '6.9 years' => [69 * 2 * $half, 6.9];
    }

    /** @dataProvider rows */
    #[DataProvider('rows')]
    public function testEveryReadingPublishesTenths(int $secondsAgo, float $years): void
    {
        $measure = new AgeMeasure(Clock::fixed(self::NOW), new Thresholds());
        $at = self::ago($secondsAgo);
        $facts = FactsBuilder::facts(
            FactsBuilder::package(['version' => '1.0.0', 'time' => $at]),
            FactsBuilder::metadata([['1.0.0', $at]]),
            FactsBuilder::activity(false, $at)
        );

        foreach ([$measure->installed($facts), $measure->release($facts), $measure->branchRelease($facts), $measure->push($facts)] as $reading) {
            self::assertSame($years, $reading->years());
        }
    }

    public function testSwiftmailerPublishesFiveYearsAtWarn(): void
    {
        // swiftmailer/swiftmailer v6.3.0, released 2021-10-18: 4.95 years on 2026-10-01. The
        // published tenths round up to 5.0; the level reads the exact ratio and stays `warn`.
        $clock = Clock::fixed(self::NOW);
        $facts = FactsBuilder::facts(FactsBuilder::package(['version' => 'v6.3.0']), FactsBuilder::metadata([['v6.3.0', '2021-10-18T12:06:47+00:00']]));

        $signal = (new NoReleaseRule($clock, new Thresholds()))->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame(Signal::LEVEL_WARN, $signal->level());
        self::assertSame(5.0, $signal->data()['years']);
        self::assertSame('last release 2021-10-18 (5.0 years ago)', $signal->summary());
        self::assertStringContainsString('"years": 5,', (new JsonFormatter())->format(self::report([$signal])));
    }

    public function testTheSignalSummariesPrintThePublishedTenths(): void
    {
        $clock = Clock::fixed(self::NOW);
        $thresholds = new Thresholds();
        // 3.05 years exactly: the float form printed 3.0 or 3.1 depending on the binary noise.
        $at = self::ago(61 * intdiv(Clock::SECONDS_PER_YEAR, 20));
        $facts = FactsBuilder::facts(
            FactsBuilder::package(['version' => '1.2.0']),
            FactsBuilder::metadata([['2.0.0', self::ago(86400)], ['1.2.0', $at]]),
            FactsBuilder::activity(false, $at)
        );

        foreach ([new NoPushRule($clock, $thresholds), new LeftBehindRule($clock, $thresholds)] as $rule) {
            $signal = $rule->evaluate($facts);
            self::assertNotNull($signal);
            self::assertSame(3.1, $signal->data()['years']);
            self::assertStringContainsString('(3.1 years ago', $signal->summary());
        }
    }

    public function testEveryJsonWriterPrintsTheSameBytesUnderSerializePrecisionSeventeen(): void
    {
        $report = self::report([self::sixPointNine()]);
        $writers = [
            'json' => new JsonFormatter(),
            'gitlab' => new GitlabFormatter(FormatContext::create(null, LockrotConfig::FAIL_ON_NONE)),
            'sarif' => new SarifFormatter(FormatContext::create(null, LockrotConfig::FAIL_ON_NONE)),
            'html' => new HtmlFormatter(),
        ];
        $previous = \ini_get('serialize_precision');
        foreach ($writers as $name => $writer) {
            ini_set('serialize_precision', '-1');
            $shortest = $writer->format($report);
            ini_set('serialize_precision', '17');
            try {
                $inherited = $writer->format($report);
            } finally {
                ini_set('serialize_precision', (string) $previous);
            }
            self::assertSame($shortest, $inherited, $name);
            self::assertStringNotContainsString('6.9000000000000004', $inherited, $name);
        }
    }

    public function testARunUnderDashDSerializePrecisionSeventeenPrintsTheSameBytes(): void
    {
        $script = 'require '.var_export(__DIR__.'/../../vendor/autoload.php', true).'; echo (new '.JsonFormatter::class.'())->format('.self::class.'::report(['.self::class.'::sixPointNine()]));';
        $output = [];
        exec(escapeshellarg(\PHP_BINARY).' -d serialize_precision=17 -r '.escapeshellarg($script), $output, $status);

        self::assertSame(0, $status);
        $printed = implode("\n", $output);
        self::assertStringContainsString('"years": 6.9,', $printed);
        self::assertSame(rtrim((new JsonFormatter())->format(self::report([self::sixPointNine()]))), $printed);
    }

    /** S8 at 6.9 years: a tenth with no exact binary form. */
    public static function sixPointNine(): Signal
    {
        $at = self::ago(69 * intdiv(Clock::SECONDS_PER_YEAR, 10));
        $facts = FactsBuilder::facts(FactsBuilder::package(['version' => '1.2.0']), FactsBuilder::metadata([['2.0.0', self::ago(86400)], ['1.2.0', $at]]));
        $signal = (new LeftBehindRule(Clock::fixed(self::NOW), new Thresholds()))->evaluate($facts);
        if ($signal === null) {
            throw new \LogicException('S8 did not fire');
        }

        return $signal;
    }

    /** @param list<Signal> $signals */
    public static function report(array $signals): Report
    {
        $finding = (new FindingBuilder())->withVerdict(Verdict::LEFT_BEHIND)->withSignals($signals)->build();

        return new Report([$finding], [], new \DateTimeImmutable(self::NOW), 1, 0);
    }
}
