<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunNote;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\CorpusFloor;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `verdict_rules_changed` note names the findings whose 0.13 priority reached the grade value
 * and whose verdict does not. The corpus floor holds what 0.13 recorded for each finding, and the
 * expected names are the model's (`security-axis/r3`, `report-2/` and `report-2-variants/`).
 */
final class VerdictRulesChangedNoteTest extends TestCase
{
    /**
     * The corpus report, the grade value, and the findings the note names as `package version`.
     *
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function corpusNotes(): iterable
    {
        yield 'mautic-v4 at high' => ['mautic-v4', 'high', ['league/flysystem', 'symfony/http-client', 'symfony/validator', 'symfony/yaml']];
        yield 'prestashop-v1 at high' => ['prestashop-v1', 'high', ['symfony/http-client']];
        yield 'joomla-cms-v4 at critical' => ['joomla-cms-v4', 'critical', ['enshrined/svg-sanitize']];
        yield 'phpbb at critical' => ['phpbb', 'critical', ['symfony/http-kernel', 'symfony/routing', 'symfony/twig-bridge', 'symfony/yaml']];
        yield 'wallabag at critical' => ['wallabag', 'critical', ['enshrined/svg-sanitize', 'symfony/dom-crawler', 'symfony/mailer', 'symfony/routing', 'symfony/validator']];
    }

    /**
     * @param list<string> $packages
     *
     * @dataProvider corpusNotes
     */
    #[DataProvider('corpusNotes')]
    public function testTheNoteNamesTheFindingsThatTheGradeValueNoLongerReaches(string $name, string $grade, array $packages): void
    {
        $note = self::note(self::floorReport($name), $grade);

        self::assertNotNull($note, $name.' at '.$grade.' writes the note');
        self::assertSame($packages, JsonPath::column(JsonPath::decoded($note->data()), ['findings'], 'package'));
    }

    public function testPrestashopNamesTheHttpClientAtItsInstalledVersion(): void
    {
        $note = self::note(self::floorReport('prestashop-v1'), 'high');

        self::assertNotNull($note);
        self::assertSame(['v4.4.26'], JsonPath::column(JsonPath::decoded($note->data()), ['findings'], 'version'));
    }

    /** The model writes the note at `high` on mautic-v4 and prestashop-v1 only. */
    public function testNoOtherCorpusReportWritesTheNoteAtHigh(): void
    {
        $writing = [];
        foreach (CorpusFloor::reports() as $report) {
            if (self::note(self::floorReport($report['name']), 'high') !== null) {
                $writing[] = $report['name'];
            }
        }

        self::assertSame(['mautic-v4', 'prestashop-v1'], $writing);
    }

    /** A run without a grade value writes no note: the flag values read no priority. */
    public function testAFlagGateWritesNoNote(): void
    {
        self::assertNull(self::note(self::floorReport('mautic-v4'), 'abandoned,vulnerable'));
    }

    /**
     * 0.13 read every matched `ignore[]` entry as accepting the whole package, so its priority was
     * none. An entry that accepts `abandoned` only leaves 0.14's legacy verdict `abandoned`, but
     * the note keeps 0.13's view and does not name the package.
     */
    public function testAPackageThatAnEntryMatchesIsNeverNamed(): void
    {
        $signals = [new Signal(Signal::LEVEL_HIGH, 'marked abandoned by its repository', SignalDataStub::of(Signal::S1, []))];
        $entry = new AllowlistEntry('acme/gone', null, 'replaced next sprint', null, AllowlistEntry::BY_PROJECT, [FlagSet::ABANDONED]);
        $finding = (new FindingBuilder())->withPackage('acme/gone')->withVerdict(Verdict::ABANDONED)->withSignals($signals)->withChain(['acme/gone'])->withFlags(FlagSet::fromSignals($signals, $entry, []))->build();
        $finding = $finding->withDetails(FindingBuilder::detailsOf($finding, true, $entry));

        self::assertSame(0, $finding->score()->total(), 'the entry accepts the only flag');
        self::assertNull(self::note(new Report([$finding], [], new \DateTimeImmutable('2026-10-01T00:00:00+00:00'), 1, 0), 'high'));
    }

    private static function note(Report $report, string $failOn): ?RunNote
    {
        $run = new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::parse($failOn), RunSettings::SOURCE_OPTION, null);
        foreach ($report->withRun($run)->runNotes() as $note) {
            if ($note->code() === RunNote::VERDICT_RULES_CHANGED) {
                return $note;
            }
        }

        return null;
    }

    private static function floorReport(string $name): Report
    {
        foreach (CorpusFloor::reports() as $report) {
            if ($report['name'] === $name) {
                $findings = array_map(static fn (array $recorded): Finding => CorpusFloor::finding($recorded, CorpusFloor::advisories($recorded)), $report['findings']);

                return new Report($findings, [], new \DateTimeImmutable($report['generated_at']), \count($findings), 0);
            }
        }
        self::fail('no corpus report '.$name);
    }
}
