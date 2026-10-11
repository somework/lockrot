<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryIgnoreMatch;
use Lockrot\Data\Advisory\IgnoredAdvisory;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\RootRecount;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FindingDetails;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Score;
use PHPUnit\Framework\TestCase;

/**
 * Each root block of report-2 and `run.score_rules_used` agree with the written `findings[]`: a
 * reader that decodes the document and counts again gets the written block. The documents are the
 * cases of tests/fixtures/flags/cases.json, each under fail-on values that make findings reach the
 * gate. Each case also runs with a baseline of its own findings, which exempts them. One more
 * document holds the facts that no case has.
 * AppRootRecountTest checks the fixture apps.
 */
final class RootRecountTest extends TestCase
{
    private const FAIL_ON = ['low', 'abandoned', 'unchecked'];
    /** A finding shows no gate rule, and `sort` changes no number, so the recount writes each as 0 or null. */
    private const NEVER_COUNTED = ['run.score_rules_used.sort', 'run.score_rules_used.gate-bounded-new', 'run.score_rules_used.baseline-cover'];

    public function testEveryRootBlockOfEveryCaseRecountsFromItsFindings(): void
    {
        $mismatches = [];
        $seen = [];
        foreach (self::documents() as $label => $document) {
            foreach (RootRecount::mismatches($document) as $mismatch) {
                $mismatches[] = $label.' '.$mismatch;
            }
            foreach (RootRecount::counters($document) as $counter => $count) {
                $seen[$counter] = ($seen[$counter] ?? 0) + $count;
            }
        }

        self::assertSame([], $mismatches);
        self::assertNotSame([], $seen);
        self::assertSame([], array_keys(array_filter(array_diff_key($seen, array_flip(self::NEVER_COUNTED)), static fn (int $count): bool => $count === 0)), 'the documents make every counter count');
    }

    /** @return iterable<string, array<mixed, mixed>> */
    private static function documents(): iterable
    {
        $cases = 0;
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(CaseHydrator::CASES), ['cases']) as $case) {
            if (!\is_array($case) || !\is_array($case['inputs'] ?? null)) {
                continue;
            }
            ++$cases;
            $id = JsonPath::stringAt($case, ['id']);
            $report = CaseHydrator::report($case);
            yield $id => self::decode($report);
            foreach (self::FAIL_ON as $failOn) {
                $run = new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::fromString($failOn), RunSettings::SOURCE_OPTION, null);
                yield $id.' --fail-on='.$failOn => self::decode($report->withRun($run));
            }
            $names = array_map(static fn (Finding $finding): string => $finding->package(), $report->findings());
            $known = $report->withBaseline(BaselineComparison::compare(Baseline::fromReport($report), $report, 'lockrot-baseline.json', $names));
            $run = new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::fromString('low'), RunSettings::SOURCE_OPTION, null);
            yield $id.' with its own baseline' => self::decode($known->withRun($run));
        }
        self::assertGreaterThan(0, $cases);
        $run = new RunSettings(null, null, '8.4', RunSettings::SOURCE_OPTION, null, FailOn::fromString('low'), RunSettings::SOURCE_OPTION, null);
        yield 'the facts that no case has' => self::decode((new Report(self::factsNoCaseHas(), [], new \DateTimeImmutable('2026-01-02T00:00:00+00:00'), 6, 0))->withRun($run));
    }

    /**
     * The findings hold an abandoned package with a replacement and one with a suggestion, at two
     * data dates. One flag is accepted in a graded finding and in a score-0 one. One finding has an
     * ignored advisory and a counted one whose fix is unknown.
     *
     * @return list<Finding>
     */
    private static function factsNoCaseHas(): array
    {
        $abandoned = static fn (string $package, string $replacement, string $date): Finding => (new FindingBuilder())->withPackage($package)->withChain([$package])
            ->withSignals([new Signal('S1', 'high', 'marked abandoned', ['replacement' => $replacement])])->withDataDate(new \DateTimeImmutable($date))->build();
        $stale = new AllowlistEntry('acme/kept', null, 'kept', null, AllowlistEntry::BY_PROJECT, ['stale']);
        $s9 = new Signal('S9', 'warn', '1 advisory', ['advisories' => [['id' => 'PKSA-u', 'severity' => 'high', 'fix' => ['kind' => 'unknown']]]]);
        $vulnerable = (new FindingBuilder())->withPackage('acme/vulnerable')->withChain(['acme/vulnerable'])->withSignals([$s9])
            ->withFlags(FlagSet::fromSignals([$s9], null, [Score::advisory('PKSA-u', 'high', 'unknown')]))->build();
        $ignored = new IgnoredAdvisory(new Advisory('PKSA-i', null, null, null, 'low', null), new AdvisoryIgnoreMatch(AdvisoryIgnoreMatch::ID, 'PKSA-i', null, AdvisoryIgnoreMatch::BY_AUDIT));

        return [
            $abandoned('acme/abandoned', 'acme/new', '2026-01-01T00:00:00+00:00'),
            $abandoned('acme/forgotten', 'Symfony', '2025-06-01T00:00:00+00:00'),
            (new FindingBuilder())->withPackage('acme/kept')->withChain(['acme/kept'])->withFlags(FlagSet::fromSignals([new Signal('S5', 'warn', 'old promise'), new Signal('S2', 'warn', 'no release')], $stale, []))->build(),
            (new FindingBuilder())->withPackage('acme/quiet')->withChain(['acme/quiet'])->withFlags(FlagSet::fromSignals([new Signal('S2', 'warn', 'no release')], $stale, []))->build(),
            $vulnerable->withDetails(new FindingDetails('read', null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'complete', null, [$ignored], null)),
        ];
    }

    /** @return array<mixed, mixed> */
    private static function decode(Report $report): array
    {
        $document = json_decode((new JsonFormatter())->format($report), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        return $document;
    }
}
