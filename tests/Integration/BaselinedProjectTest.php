<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Composer;
use JsonSchema\Validator;
use Lockrot\Baseline\BaselineFile;
use Lockrot\Exception\ConfigException;
use Lockrot\Tests\Support\Contract\BaselinedFloors;
use Lockrot\Tests\Support\Contract\BaselinedScenario;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Real projects under a committed baseline-2 (items C78): the earlier lock writes the baseline on
 * its own day, and the later lock is checked against it, then regenerated and checked again. The
 * four steps run in-process on the fixture server and the recorded answers, and their files equal
 * the committed ones. Re-record them with tools/report/record --all-baselined.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class BaselinedProjectTest extends TestCase
{
    private const BASELINE_1 = BaselinedScenario::FIXTURES.'baselined/wallabag-main/lockrot-baseline-1.json';

    /** SPEC-0.14.md §6.4 (Q2): the 0.13 file that wallabag would have committed is refused before schema validation. */
    public function testTheReal013BaselineIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('lockrot 0.14 reads baseline-2 only. Review the findings, then run --generate-baseline.');

        BaselineFile::resolve(\dirname(self::BASELINE_1), basename(self::BASELINE_1), '0.14.0')->read();
    }

    /** @return iterable<string, array{string}> */
    public static function scenarios(): iterable
    {
        foreach (array_keys(BaselinedScenario::SCENARIOS) as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    /**
     * @dataProvider scenarios
     *
     * @runInSeparateProcess
     */
    #[DataProvider('scenarios')]
    #[RunInSeparateProcess]
    public function testTheFourStepsWriteTheCommittedFilesAndMeetTheFloors(string $scenario): void
    {
        $dir = BaselinedScenario::FIXTURES.'baselined/'.$scenario.'/';
        $files = BaselinedScenario::record($scenario);

        // Composer below 2.4 has no advisory API, so lockrot checks no advisory there. The recording ran with one, so on
        // such a Composer the files differ, and only the floors that need no advisory and the invariants below hold.
        $advisories = version_compare(Composer::VERSION, '2.4', '>=');
        if ($advisories) {
            self::assertSame(self::committed($dir), array_keys($files), $scenario.': the recorder writes every committed file and no other');
            foreach ($files as $path => $contents) {
                self::assertSame((string) @file_get_contents($dir.$path), $contents, $scenario.'/'.$path.': re-record with tools/report/record --baselined '.$scenario);
            }
        }
        $missed = BaselinedFloors::missed($scenario, $files);
        self::assertSame([], $advisories ? $missed : array_values(array_diff($missed, BaselinedFloors::ADVISORY_FLOORS)));
        $provenance = json_decode($files['provenance.json']);
        $validator = new Validator();
        $validator->validate($provenance, json_decode((string) file_get_contents(BaselinedScenario::FIXTURES.'baselined/provenance.schema.json')));
        self::assertTrue($validator->isValid(), (string) json_encode($validator->getErrors()));
        $check = JsonPath::decoded(json_decode($files['check/report.json'], true));
        self::assertRootCountsAreTheFindingsCounts($check);
        self::assertRecordedValuesAreSet($check);
        self::assertTheRegeneratedFileKeepsItsHistory(json_decode($files['lockrot-baseline.json'], true), json_decode($files['regenerate/lockrot-baseline.json'], true), $check, substr(BaselinedScenario::SCENARIOS[$scenario]['t1'], 0, 10));
    }

    /** @return list<string> the recorded files of the scenario directory, by their path below it: its inputs left out */
    private static function committed(string $dir): array
    {
        $paths = ['lockrot-baseline.json', 'provenance.json'];
        foreach (['t0', 'check', 'regenerate'] as $step) {
            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir.$step, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $paths[] = substr($file->getPathname(), \strlen($dir));
            }
        }
        sort($paths);

        return $paths;
    }

    /** @param array<mixed, mixed> $report */
    private static function assertRootCountsAreTheFindingsCounts(array $report): void
    {
        $counts = ['known' => 0, 'new' => 0, 'worsened' => 0];
        foreach (JsonPath::arrayAt($report, ['findings']) as $finding) {
            $status = \is_array($finding) && \is_array($finding['baseline'] ?? null) ? $finding['baseline']['status'] : null;
            if (\is_string($status) && isset($counts[$status])) {
                ++$counts[$status];
            }
        }

        self::assertSame($counts, array_intersect_key(JsonPath::arrayAt($report, ['baseline']), $counts));
    }

    /**
     * Q2: every entry comes from a baseline-2 file, so its fix model, target PHP and dates are set.
     *
     * @param array<mixed, mixed> $report
     */
    private static function assertRecordedValuesAreSet(array $report): void
    {
        foreach (JsonPath::arrayAt($report, ['findings']) as $finding) {
            $baseline = \is_array($finding) && \is_array($finding['baseline'] ?? null) ? $finding['baseline'] : [];
            $recorded = \is_array($baseline['recorded'] ?? null) ? $baseline['recorded'] : null;
            if ($recorded === null) {
                continue;
            }
            self::assertNotNull($recorded['fix_model'] ?? null);
            self::assertNotNull($recorded['target_php'] ?? null);
            foreach (\is_array($recorded['flags'] ?? null) ? $recorded['flags'] : [] as $flag) {
                self::assertNotNull(\is_array($flag) ? ($flag['known_since'] ?? null) : null);
            }
        }
    }

    /**
     * The regenerated file keeps `first_seen` and each `known_since` of a fact still counted, dates a
     * new fact on the check's day and drops the entries of packages the later lock no longer holds.
     *
     * @param mixed               $before
     * @param mixed               $after
     * @param array<mixed, mixed> $report
     */
    private static function assertTheRegeneratedFileKeepsItsHistory($before, $after, array $report, string $today): void
    {
        self::assertIsArray($before);
        self::assertIsArray($after);
        $old = JsonPath::arrayAt($before, ['findings']);
        $new = JsonPath::arrayAt($after, ['findings']);
        foreach (JsonPath::arrayAt($report, ['baseline', 'stale']) as $stale) {
            $package = \is_array($stale) ? ($stale['package'] ?? null) : $stale;
            self::assertArrayNotHasKey(\is_string($package) ? $package : '', $new, 'a stale entry is dropped');
        }
        $kept = 0;
        foreach ($new as $package => $entry) {
            self::assertIsArray($entry);
            $was = \is_array($old[$package] ?? null) ? $old[$package] : null;
            self::assertSame($was === null ? $today : $was['first_seen'], $entry['first_seen'], (string) $package);
            foreach (\is_array($entry['flags'] ?? null) ? $entry['flags'] : [] as $flag => $state) {
                $since = $was !== null && JsonPath::has($was, ['flags', $flag, 'known_since']) ? JsonPath::stringAt($was, ['flags', $flag, 'known_since']) : null;
                self::assertSame($since ?? $today, \is_array($state) ? $state['known_since'] : null, $package.' '.$flag);
                $kept += $since === null ? 0 : 1;
            }
        }
        self::assertGreaterThan(0, $kept, 'a flag the earlier file knew keeps its date');
    }
}
