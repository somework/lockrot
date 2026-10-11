<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Legacy\AdvisoryFacts013;
use Lockrot\Security\Fix;
use Lockrot\Security\Severity;
use Lockrot\Signal\Data\AdvisoryData;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\ScoreModel;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Assert;

/**
 * The corpus floor: lockrot.dev's watch reports reduced to the facts each finding was judged on and
 * what report-1 recorded. `tools/corpus/floor.py` writes it. The floor holds no releases, so a
 * hydrated advisory's fix is `unknown`, as lockrot gives it when it reads no releases.
 *
 * @phpstan-type FloorFinding array{package: string, version: string, direct: bool, dev: bool, chain: list<string>, verdict: string, priority: string, signals: list<array{id: string, level: string, data: array<string, mixed>}>, allowlist_reason: ?string}
 * @phpstan-type FloorReport array{name: string, lockrot: string, generated_at: string, target_php: string, findings: list<FloorFinding>}
 */
final class CorpusFloor
{
    public const PATH = __DIR__.'/../fixtures/corpus/report-1-floor.json.gz';
    public const PROVENANCE = __DIR__.'/../fixtures/corpus/report-1-floor.provenance.json';

    /** @var list<FloorReport>|null */
    private static ?array $reports = null;

    /** @return list<FloorReport> */
    public static function reports(): array
    {
        if (self::$reports === null) {
            $raw = gzdecode((string) file_get_contents(self::PATH));
            Assert::assertIsString($raw, self::PATH.' is not gzipped');
            $floor = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            Assert::assertIsArray($floor);
            Assert::assertIsArray($floor['reports']);
            /** @var list<FloorReport> $reports */
            $reports = $floor['reports'];
            self::$reports = $reports;
        }

        return self::$reports;
    }

    /**
     * The signals with their ids and levels. No reader of the floor reads their data, except
     * report-2's S9 rows: each advisory with its severity bucket and an `unknown` fix. S9's
     * report-1 rows are {@see legacy()}.
     *
     * @param FloorFinding $finding
     *
     * @return list<Signal>
     */
    public static function signals(array $finding): array
    {
        $signals = [];
        foreach ($finding['signals'] as $signal) {
            $data = null;
            if ($signal['id'] === Signal::S9) {
                $rows = [];
                foreach (self::advisories($finding) as $advisory) {
                    $bucket = Severity::fromComposer($advisory['severity'])->bucket();
                    $rows[] = SignalDataStub::row(['id' => $advisory['id'], 'severity' => $bucket, 'points' => ScoreModel::advisoryPoints($bucket, Fix::UNKNOWN)]);
                }
                $data = new AdvisoryData($rows, ($signal['data']['releases_read'] ?? false) === true, true);
            }
            $signals[] = new Signal($signal['id'], $signal['level'], '', $data);
        }

        return $signals;
    }

    /**
     * report-1's advisory facts of the finding's S9.
     *
     * @param FloorFinding $finding
     */
    public static function legacy(array $finding): AdvisoryFacts013
    {
        foreach ($finding['signals'] as $signal) {
            if ($signal['id'] === Signal::S9) {
                $rows = $signal['data']['advisories'];
                Assert::assertIsArray($rows);
                /** @var list<array<string, mixed>> $rows */
                return SignalDataStub::legacy($rows, ($signal['data']['releases_read'] ?? false) === true);
            }
        }

        return AdvisoryFacts013::none();
    }

    /**
     * The counted advisories of the finding's S9 rows, each with its severity and an `unknown` fix.
     *
     * @param FloorFinding $finding
     *
     * @return list<array{id: string, severity: string, fix_kind: string}>
     */
    public static function advisories(array $finding): array
    {
        $advisories = [];
        foreach ($finding['signals'] as $signal) {
            if ($signal['id'] !== Signal::S9) {
                continue;
            }
            $rows = $signal['data']['advisories'];
            Assert::assertIsArray($rows);
            foreach ($rows as $row) {
                Assert::assertIsArray($row);
                Assert::assertIsString($row['id']);
                $severity = $row['severity'] ?? '';
                Assert::assertIsString($severity);
                $advisories[] = Score::advisory($row['id'], $severity, 'unknown');
            }
        }

        return $advisories;
    }

    /**
     * The finding of these facts, with the report-1 verdict that {@see VerdictEngine} decides. An
     * allowlist reason becomes an entry that accepts the whole package. Report-1 has no entry that
     * accepts some flags only. The verdict `unknown` is the one trace of release metadata that
     * lockrot did not read.
     *
     * @param FloorFinding $finding
     * @param list<array{id: string, severity: string, fix_kind: string}> $advisories
     */
    public static function finding(array $finding, array $advisories = []): Finding
    {
        $signals = self::signals($finding);
        $reason = $finding['allowlist_reason'];
        $entry = $reason === null ? null : new AllowlistEntry($finding['package'], null, $reason, null, 'builtin');
        $judged = $finding['verdict'] !== 'unknown';
        $verdict = (new VerdictEngine())->decide($signals, $entry !== null, $judged);

        $built = new Finding($finding['package'], $finding['version'], $verdict, $signals, $finding['chain'], $reason, null, null, $finding['dev'], [], null, null, null, self::legacy($finding));

        return $built->withFlags(FlagSet::fromSignals($signals, $entry, $advisories), $judged)->withDetails(FindingBuilder::detailsOf($built, $judged));
    }
}
