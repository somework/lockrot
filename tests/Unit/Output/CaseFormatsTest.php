<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Output\ConsoleMarkup;
use Lockrot\Output\FormatContext;
use Lockrot\Output\GithubFormatter;
use Lockrot\Output\GitlabFormatter;
use Lockrot\Output\MarkdownFormatter;
use Lockrot\Output\SarifFormatter;
use Lockrot\Output\TableFormatter;
use Lockrot\Tests\Support\BaselineEntries;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\TextGolden;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every format over the case finding of every case, at `--fail-on=high,unchecked` and with
 * `--all`, without and with a baseline. SARIF and GitLab are held as one line per record: rule,
 * level and message.
 */
final class CaseFormatsTest extends TestCase
{
    private const FAIL_ON = 'high,unchecked';

    private static ?Report $report = null;

    private static function report(): Report
    {
        return self::$report ??= CaseHydrator::everyCase()->withRun(new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse(self::FAIL_ON), RunSettings::SOURCE_OPTION, null));
    }

    /**
     * Known, covered, worsened, re-rated and unchecked facts: the entries record fewer flags or
     * another severity than the run counts. A baseline for the run's target PHP also compares the
     * fixes, so a lost fix shows.
     */
    private static function baselined(string $targetPhp, string $targetPhpSource): Report
    {
        $report = self::report();
        $baseline = Baseline::of([
            new BaselineEntry('symfony/debug', 'v3.4.47', '2026-06-01', ['abandoned' => '2026-06-01'], []),
            BaselineEntries::of('fgrosse/phpasn1', 'v2.5.0', Verdict::STALE, '2026-06-01'),
            new BaselineEntry('guzzlehttp/guzzle', '6.5.8', '2026-06-01', ['left-behind' => '2026-06-01'], []),
            BaselineEntries::of('symfony/yaml', 'v5.4.23', Verdict::STALE, '2026-06-01', [['PKSA-b14r-zh1d-vdrc', 'low', 'update']]),
            BaselineEntries::of('league/commonmark', '2.9.1', Verdict::STALE, '2026-06-01', [['PKSA-m4t9-vsgq-8khn', 'medium', 'update']]),
            BaselineEntries::of('balping/json-raw-encoder', 'v1.0.2', Verdict::STALE, '2026-06-01', [['PKSA-eith-0000-0002', 'low', 'none']]),
            BaselineEntries::of('scheb/2fa-google-authenticator', 'v8.6.1', Verdict::STALE, '2026-06-01'),
        ], '2026-09-14T00:00:00+00:00', $targetPhp, $targetPhpSource);
        $names = array_map(static fn (Finding $finding): string => $finding->package(), $report->findings());

        return $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', $names));
    }

    private static function context(): FormatContext
    {
        return FormatContext::create(null, self::FAIL_ON);
    }

    /** @return iterable<string, array{string}> */
    public static function runs(): iterable
    {
        yield 'without a baseline' => ['cases'];
        yield 'with a baseline' => ['cases.baseline'];
        yield 'with a baseline for the same target' => ['cases.baseline.same-target'];
    }

    private static function of(string $golden): Report
    {
        if ($golden === 'cases') {
            return self::report();
        }

        return $golden === 'cases.baseline' ? self::baselined('8.5', RunSettings::SOURCE_RUNTIME) : self::baselined('8.4', RunSettings::SOURCE_OPTION);
    }

    /** @dataProvider runs */
    #[DataProvider('runs')]
    public function testTheTableOfEveryCaseIsItsGolden(string $golden): void
    {
        TextGolden::assertMatches($golden.'.table.txt', ConsoleMarkup::render((new TableFormatter(self::context()))->format(self::of($golden), true), false), __FUNCTION__);
    }

    /** At 60 columns the marking, age and missing-checks lines wrap under their hang. */
    public function testTheNarrowTableOfEveryCaseIsItsGolden(): void
    {
        $context = FormatContext::create(null, self::FAIL_ON, Version::STRING, 60);

        TextGolden::assertMatches('cases.narrow.table.txt', ConsoleMarkup::render((new TableFormatter($context))->format(self::of('cases.baseline.same-target'), true), false), __FUNCTION__);
    }

    /** @dataProvider runs */
    #[DataProvider('runs')]
    public function testTheMarkdownOfEveryCaseIsItsGolden(string $golden): void
    {
        TextGolden::assertMatches($golden.'.markdown.md', (new MarkdownFormatter(self::context()))->format(self::of($golden), true), __FUNCTION__);
    }

    /** @dataProvider runs */
    #[DataProvider('runs')]
    public function testTheGithubAnnotationsOfEveryCaseAreTheirGolden(string $golden): void
    {
        TextGolden::assertMatches($golden.'.github.txt', (new GithubFormatter(self::context()))->format(self::of($golden), true), __FUNCTION__);
    }

    /** @dataProvider runs */
    #[DataProvider('runs')]
    public function testTheSarifResultsOfEveryCaseAreTheirGolden(string $golden): void
    {
        $run = JsonPath::arrayAt(JsonPath::decoded(json_decode((new SarifFormatter(self::context()))->format(self::of($golden), true), true)), ['runs', 0]);
        $lines = [];
        foreach (JsonPath::arrayAt($run, ['results']) as $result) {
            self::assertIsArray($result);
            $suppressed = isset($result['suppressions']) ? ' ['.JsonPath::stringAt($result, ['suppressions', 0, 'justification']).']' : '';
            $lines[] = JsonPath::stringAt($result, ['ruleId']).' '.JsonPath::stringAt($result, ['level']).(isset($result['rank']) ? ' '.json_encode($result['rank']) : '').$suppressed.': '.JsonPath::stringAt($result, ['message', 'text']);
        }

        self::assertNotSame([], $lines);
        TextGolden::assertMatches($golden.'.sarif.txt', implode("\n", $lines)."\n", __FUNCTION__);
    }

    /** @dataProvider runs */
    #[DataProvider('runs')]
    public function testTheGitlabIssuesOfEveryCaseAreTheirGolden(string $golden): void
    {
        $lines = [];
        foreach (JsonPath::decoded(json_decode((new GitlabFormatter(self::context()))->format(self::of($golden), true), true)) as $issue) {
            self::assertIsArray($issue);
            $lines[] = JsonPath::stringAt($issue, ['check_name']).' '.JsonPath::stringAt($issue, ['severity']).' '.JsonPath::stringAt($issue, ['categories', 0]).': '.JsonPath::stringAt($issue, ['description']);
        }

        self::assertNotSame([], $lines);
        TextGolden::assertMatches($golden.'.gitlab.txt', implode("\n", $lines)."\n", __FUNCTION__);
    }
}
