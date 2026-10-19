<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Html\PageHeading;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The page's title and description equal the model's page goldens (tests/fixtures/contract/page-goldens.json),
 * and every golden holds the Overview lines that lockrot-report renders.
 * Where the contract bundle holds lockrot's own report-2 of a golden's document, the title reads its
 * root fields, which must equal the model's. Else lockrot cannot rebuild the document (a corpus run
 * of 0.13, a case with no inputs), and the title reads the model's root fields. A golden of a
 * `contract/` document is the model's rendering of lockrot's own report-2 (EnginePage). A golden of a
 * `baselined/` document reads the `report` of a baselined scenario's recorded bundle.
 */
final class PageGoldensTest extends TestCase
{
    private const CONTRACT = __DIR__.'/../fixtures/contract/';
    private const DOCUMENTS = __DIR__.'/../fixtures/schema/documents/';
    private const GRADES = ['critical', 'high', 'medium', 'low'];

    /**
     * The Overview lines of SPEC §7.9.4 that lockrot-report computes from report-2 fields. An empty lock
     * shows none of them, and a lock with nothing graded shows no load line.
     */
    private const OVERVIEW_LINES = ['grade_line', 'figure_line', 'load_line'];

    /** The goldens whose PHP inputs differ from the model's, with the reason. */
    private const PINNED = [
        'policy-off' => 'the hydrated lock holds joomla/filter and the three packages that require it, and the model\'s case document counts its one finding',
        'pagerfanta' => 'the hydrated lock holds pagerfanta/twig and the packages that its finding names, and the model\'s case document counts its one finding',
        'guzzle-mixed' => 'the hydrated lock holds guzzlehttp/guzzle and the packages that hold its moves, and the model\'s case document counts its one finding',
        'ok-unchecked' => 'the hydrated lock holds scheb/2fa-google-authenticator and the packages that its finding names, and the model\'s case document counts its one finding',
        'rounded-dev‡' => 'the hydrated lock holds jfcherng/php-color-output and the packages that its finding names, and the model\'s case document counts its one finding',
    ];

    /** @return iterable<string, array{string}> */
    public static function provideGoldens(): iterable
    {
        $goldens = JsonPath::arrayAt(JsonPath::decodeFile(self::CONTRACT.'page-goldens.json'), ['goldens']);
        self::assertNotSame([], $goldens);
        foreach (array_diff(array_keys($goldens), array_keys(self::PINNED)) as $id) {
            yield (string) $id => [(string) $id];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideEveryGolden(): iterable
    {
        $goldens = JsonPath::arrayAt(JsonPath::decodeFile(self::CONTRACT.'page-goldens.json'), ['goldens']);
        self::assertNotSame([], $goldens);
        foreach (array_keys($goldens) as $id) {
            yield (string) $id => [(string) $id];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function providePins(): iterable
    {
        foreach (array_keys(self::PINNED) as $id) {
            yield $id => [$id];
        }
    }

    /**
     * @dataProvider providePins
     */
    #[DataProvider('providePins')]
    public function testAPinnedGoldenStillReadsOtherInputs(string $id): void
    {
        $golden = JsonPath::arrayAt(JsonPath::decodeFile(self::CONTRACT.'page-goldens.json'), ['goldens', $id]);
        $lockrot = self::lockrotDocument(JsonPath::stringAt($golden, ['document']));

        self::assertNotNull($lockrot, $id.': the bundle holds no report-2 of it, so lockrot reads the model\'s inputs: remove the pin');
        self::assertNotSame(self::inputs(self::modelDocument(JsonPath::stringAt($golden, ['document']))), self::inputs($lockrot), $id.': its inputs agree with the model: remove the pin');
    }

    /**
     * @dataProvider provideGoldens
     */
    #[DataProvider('provideGoldens')]
    public function testTheTitleAndTheDescriptionEqualThePageGoldens(string $id): void
    {
        $golden = JsonPath::arrayAt(JsonPath::decodeFile(self::CONTRACT.'page-goldens.json'), ['goldens', $id]);
        $model = self::modelDocument(JsonPath::stringAt($golden, ['document']));
        $lockrot = self::lockrotDocument(JsonPath::stringAt($golden, ['document'])) ?? $model;

        self::assertSame(self::inputs($model), self::inputs($lockrot), $id.': lockrot and the model read different root fields');
        self::assertSame(self::GRADES, JsonPath::arrayAt($model, ['run', 'graded_verdicts']));
        $count = static fn (string $grade): int => JsonPath::intAt($lockrot, ['counts', $grade]);
        $heading = new PageHeading(JsonPath::intAt($lockrot, ['packages_checked']), $count('critical'), $count('high'), $count('medium'), $count('low'));

        self::assertSame(JsonPath::stringAt($golden, ['title']), $heading->title());
        self::assertSame(JsonPath::stringAt($golden, ['description']), $heading->description());
    }

    /**
     * @dataProvider provideEveryGolden
     */
    #[DataProvider('provideEveryGolden')]
    public function testEveryGoldenHoldsTheOverviewLines(string $id): void
    {
        $golden = JsonPath::arrayAt(JsonPath::decodeFile(self::CONTRACT.'page-goldens.json'), ['goldens', $id]);
        $model = self::modelDocument(JsonPath::stringAt($golden, ['document']));
        $empty = JsonPath::intAt($model, ['packages_checked']) === 0;
        $graded = JsonPath::intAt($model, ['packages_flagged']) > 0;

        foreach (self::OVERVIEW_LINES as $line) {
            self::assertArrayHasKey($line, $golden, $id.': re-record page-goldens.json from the model');
            if ($empty || ($line === 'load_line' && !$graded)) {
                self::assertNull($golden[$line], $id.': '.$line);
            } else {
                self::assertNotSame('', JsonPath::stringAt($golden, [$line]), $id.': '.$line);
            }
        }
    }

    /**
     * SPEC-0.14.md §7.9.7: the gate partition is null when no finding fails the gate, and its three
     * counts sum to the root `gate.failing`.
     *
     * @dataProvider provideEveryGolden
     */
    #[DataProvider('provideEveryGolden')]
    public function testTheGatePartitionSumsToTheFindingsThatFailTheGate(string $id): void
    {
        $golden = JsonPath::arrayAt(JsonPath::decodeFile(self::CONTRACT.'page-goldens.json'), ['goldens', $id]);
        $failing = JsonPath::intAt(self::modelDocument(JsonPath::stringAt($golden, ['document'])), ['gate', 'failing']);

        self::assertArrayHasKey('gate_partition', $golden, $id.': re-record page-goldens.json from the model');
        $partition = $golden['gate_partition'];
        self::assertSame($failing === 0 ? null : ['ready', 'held', 'wayout'], \is_array($partition) ? array_keys($partition) : $partition, $id.': the partition\'s parts');
        self::assertSame($failing, \is_array($partition) ? array_sum($partition) : 0, $id.': the partition against gate.failing');
    }

    /**
     * SPEC-0.14.md §7.9.7: a page case shows a ready move, a ready move that also holds other findings,
     * and a failing gate whose findings split into ready, held and another way out.
     */
    public function testThePageCasesShowAReadyMoveAndEveryPartOfTheGatePartition(): void
    {
        $goldens = JsonPath::arrayAt(JsonPath::decodeFile(self::CONTRACT.'page-goldens.json'), ['goldens']);
        $alsoHolds = [];
        $partitions = [];
        foreach ($goldens as $id => $golden) {
            $golden = JsonPath::decoded($golden);
            foreach (JsonPath::has($golden, ['ready', 'moves']) ? JsonPath::arrayAt($golden, ['ready', 'moves']) : [] as $move) {
                if (\is_array($move) && \is_string($move['also_holds'] ?? null)) {
                    $alsoHolds[] = $id;
                }
            }
            $partition = $golden['gate_partition'] ?? null;
            if (\is_array($partition) && $partition !== [] && min($partition) > 0) {
                $partitions[] = $id;
            }
        }

        self::assertNotSame([], $alsoHolds, 'no page case shows a ready move whose package holds other findings');
        self::assertNotSame([], $partitions, 'no page case splits its failing findings into ready, held and another way out, each above 0');
    }

    /**
     * SPEC-0.14.md §7.9.21: page cases show a `not_checked` move beside `resolves` moves, and a run whose
     * baseline Composer refuses, so that no move is ready and the moves_not_checked note prints.
     */
    public function testThePageCasesShowAMoveThatWasNotCheckedAndARefusedBaseline(): void
    {
        $beside = [];
        $refusedBaseline = [];
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(self::CONTRACT.'page-goldens.json'), ['goldens']) as $id => $golden) {
            $golden = JsonPath::decoded($golden);
            $document = self::modelDocument(JsonPath::stringAt($golden, ['document']));
            $results = [];
            foreach (JsonPath::arrayAt($document, ['findings']) as $finding) {
                $results[] = \is_array($finding) ? self::textAt($finding, ['next_step', 'check', 'result']) : null;
            }
            $wayOut = (string) self::textAt($golden, ['ready', 'way_out']);
            if (\in_array('not_checked', $results, true) && \in_array('resolves', $results, true) && strpos($wayOut, 'not checked: ') !== false) {
                $beside[] = $id;
            }
            $notes = JsonPath::has($golden, ['run_notes']) ? JsonPath::arrayAt($golden, ['run_notes']) : [];
            if (self::textAt($document, ['run', 'move_check', 'baseline']) === 'refused' && ($golden['ready_count'] ?? null) === 0 && preg_grep('/^moves not checked: /', array_filter($notes, 'is_string')) !== []) {
                $refusedBaseline[] = $id;
            }
        }

        self::assertNotSame([], $beside, 'no page case shows a not_checked move beside resolves moves');
        self::assertNotSame([], $refusedBaseline, 'no page case shows a refused baseline with its moves_not_checked note');
    }

    /**
     * SPEC-0.14.md §7.9.4 and §7.9.9: a page case shows the allowlist line with a count per flag, and
     * the check words of `release_dates` on the way-out line. No valid document reaches the words of
     * `releases`: its S10 entry stands beside a counted advisory (I35), and such a finding has a move.
     */
    public function testThePageCasesShowTheAcceptedCountsAndTheReleaseDatesCheckWords(): void
    {
        $accepted = [];
        $releaseDates = [];
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(self::CONTRACT.'page-goldens.json'), ['goldens']) as $id => $golden) {
            $golden = JsonPath::decoded($golden);
            $notes = JsonPath::has($golden, ['flags_notes']) ? array_filter(JsonPath::arrayAt($golden, ['flags_notes']), 'is_string') : [];
            if (preg_grep('/^Accepted by the allowlist: [a-z-]+ [1-9]\d*(, [a-z-]+ [1-9]\d*)+$/', $notes) !== []) {
                $accepted[] = $id;
            }
            if (strpos((string) self::textAt($golden, ['ready', 'way_out']), 'fails the gate as unchecked: release dates not checked') !== false) {
                $releaseDates[] = $id;
            }
        }

        self::assertNotSame([], $accepted, 'no page case shows the allowlist line with two flags and their counts');
        self::assertNotSame([], $releaseDates, 'no page case shows the release_dates check words on the way-out line');
    }

    /**
     * @param array<mixed, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function inputs(array $document): array
    {
        $counts = JsonPath::arrayAt($document, ['counts']);

        return [
            'packages_checked' => $document['packages_checked'] ?? null,
            'packages_flagged' => $document['packages_flagged'] ?? null,
            'counts' => array_map(static fn (string $grade) => $counts[$grade] ?? null, self::GRADES),
        ];
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<string>        $path
     */
    private static function textAt(array $data, array $path): ?string
    {
        foreach ($path as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return \is_string($data) ? $data : null;
    }

    /** @return array<mixed, mixed> */
    private static function modelDocument(string $document): array
    {
        if (strpos($document, 'baselined/') === 0) {
            return JsonPath::arrayAt(JsonPath::decodeFile(__DIR__.'/../fixtures/'.$document), ['report']);
        }
        if (strpos($document, 'contract/') === 0) {
            return JsonPath::decodeFile(self::CONTRACT.substr($document, \strlen('contract/')));
        }
        if (strpos($document, 'report-2/') === 0) {
            return JsonPath::decoded(json_decode((string) file_get_contents('compress.zlib://'.self::DOCUMENTS.'corpus/'.basename($document).'.gz'), true));
        }

        if (!is_file(self::DOCUMENTS.$document)) {
            return JsonPath::decoded(json_decode((string) file_get_contents('compress.zlib://'.self::DOCUMENTS.$document.'.gz'), true));
        }

        return JsonPath::decodeFile(self::DOCUMENTS.$document);
    }

    /** @return array<mixed, mixed>|null */
    private static function lockrotDocument(string $document): ?array
    {
        $path = self::CONTRACT.'report-2/'.basename($document);

        return strpos($document, 'cases/') === 0 && is_file($path) ? JsonPath::decodeFile($path) : null;
    }
}
