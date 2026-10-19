<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Html\PageHeading;
use Lockrot\Json\Schemas;
use Lockrot\Solver\MoveCheck;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
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
    use ValidatesJsonSchemas;

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
     * SPEC-0.14.md §7.9.4 and §7.9.9: the page cases show the allowlist counts and the `release_dates`
     * check words. No valid document reaches the words of `releases`. Its S10 entry stands beside a
     * counted advisory (I35), and such a finding has a move.
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
     * SPEC-0.14.md §7.9.21 "The move": the sheet's move cards of a page case hold the check line of
     * each move. The page cases show a resolved, a refused and two unchecked moves.
     */
    public function testTheSheetMoveCardsHoldTheCheckLineOfEachMove(): void
    {
        $differ = [];
        $lines = [];
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(self::CONTRACT.'page-goldens.json'), ['goldens']) as $id => $golden) {
            $golden = JsonPath::decoded($golden);
            self::assertArrayHasKey('sheet_moves', $golden, $id.': re-record page-goldens.json from the model');
            $findings = [];
            foreach (JsonPath::arrayAt(self::modelDocument(JsonPath::stringAt($golden, ['document'])), ['findings']) as $finding) {
                $finding = JsonPath::decoded($finding);
                $findings[JsonPath::stringAt($finding, ['package'])] = $finding;
            }
            foreach (JsonPath::arrayAt($golden, ['sheet_moves']) as $package => $cards) {
                $lead = JsonPath::arrayAt($findings[$package], ['next_step']);
                foreach (array_values(array_merge([$lead], JsonPath::arrayAt($lead, ['also']))) as $i => $move) {
                    $move = JsonPath::decoded($move);
                    $result = JsonPath::stringAt($move, ['check', 'result']);
                    $expected = [
                        'resolves' => 'checked with Composer',
                        'refused' => 'Composer refuses the move',
                        'not_checked' => 'if Composer resolves it (not checked: '.($result === MoveCheck::NOT_CHECKED ? MoveCheck::reasonWords(JsonPath::stringAt($move, ['check', 'reason'])) : '').')',
                    ][$result];
                    $conditions = JsonPath::arrayAt(JsonPath::decoded($cards), [$i, 'after', 'conditions']);
                    $lines[] = $expected;
                    if (!\in_array($expected, $conditions, true)) {
                        $differ[$id.' '.$package.' card '.$i] = ['expected' => $expected, 'conditions' => $conditions];
                    }
                }
            }
        }

        self::assertSame([], $differ);
        foreach (['checked with Composer', 'Composer refuses the move', 'if Composer resolves it (not checked: '.MoveCheck::reasonWords(MoveCheck::UNLOADED_NAME).')', 'if Composer resolves it (not checked: '.MoveCheck::reasonWords(MoveCheck::BASELINE_REFUSED).')'] as $line) {
            self::assertContains($line, $lines, 'no sheet move card of a page case reads: '.$line);
        }
    }

    /**
     * SPEC-0.14.md §7.9.21 "Two sheet places": a Held by item adds a stopped check of its holder's own
     * step in short words. The page cases show a refused step, an unchecked step of each reason, and
     * a resolved step with no words.
     */
    public function testTheSheetHeldByItemsAddTheShortWordsOfAStoppedCheck(): void
    {
        $differ = [];
        $forms = [];
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(self::CONTRACT.'page-goldens.json'), ['goldens']) as $id => $golden) {
            $golden = JsonPath::decoded($golden);
            self::assertArrayHasKey('sheet_holders', $golden, $id.': re-record page-goldens.json from the model');
            $findings = self::findingsByPackage(self::modelDocument(JsonPath::stringAt($golden, ['document'])));
            foreach (JsonPath::arrayAt($golden, ['sheet_holders']) as $package => $cards) {
                $lead = JsonPath::arrayAt($findings[$package], ['next_step']);
                $checks = [];
                foreach (array_merge([$lead], JsonPath::arrayAt($lead, ['also'])) as $move) {
                    foreach (JsonPath::arrayAt(JsonPath::decoded($move), ['held_by']) as $holder) {
                        $holder = JsonPath::decoded($holder);
                        $step = \is_array($holder['holder'] ?? null) ? ($holder['holder']['next_step'] ?? null) : null;
                        if (\is_array($step)) {
                            $checks[JsonPath::stringAt($holder, ['package']).' '.self::textAt($holder, ['version'])] = $step['check'] ?? null;
                        }
                    }
                }
                foreach (array_filter(JsonPath::decoded($cards), 'is_array') as $card) {
                    $card = JsonPath::decoded($card);
                    foreach (array_merge(JsonPath::arrayAt($card, ['shown']), \is_array($card['fold'] ?? null) ? JsonPath::arrayAt($card, ['fold', 'holders']) : []) as $item) {
                        $item = JsonPath::decoded($item);
                        $step = self::textAt($item, ['step']);
                        if ($step === null) {
                            continue;
                        }
                        $check = $checks[JsonPath::stringAt($item, ['name'])] ?? null;
                        $words = self::shortCheckWords(\is_array($check) ? $check : null);
                        $forms[] = $words ?? 'none';
                        $at = strpos($step, ' · ');
                        $stated = $at === false ? null : substr($step, $at + \strlen(' · '));
                        if ($stated !== $words) {
                            $differ[$id.' '.$package.' '.JsonPath::stringAt($item, ['name'])] = ['expected' => $words, 'step' => $step];
                        }
                    }
                }
            }
        }

        self::assertSame([], $differ);
        foreach (['none', 'Composer refuses the move', 'not checked: '.MoveCheck::reasonWords(MoveCheck::UNLOADED_NAME), 'not checked: '.MoveCheck::reasonWords(MoveCheck::BASELINE_REFUSED)] as $form) {
            self::assertContains($form, $forms, 'no Held by item of a page case reads: '.$form);
        }
    }

    /**
     * SPEC-0.14.md §7.9.21 "Two sheet places": a Security ladder row whose check stopped drops ", if
     * Composer resolves it" and ends with the short words. Only the wallabag-2.6 bundle carries a
     * details block, so the page cases reach the `baseline_refused` words and no refused row.
     */
    public function testTheSheetLadderRowsEndWithTheShortWordsOfAStoppedCheck(): void
    {
        $differ = [];
        $forms = [];
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(self::CONTRACT.'page-goldens.json'), ['goldens']) as $id => $golden) {
            $golden = JsonPath::decoded($golden);
            self::assertArrayHasKey('sheet_ladder', $golden, $id.': re-record page-goldens.json from the model');
            $ladders = JsonPath::arrayAt($golden, ['sheet_ladder']);
            if ($ladders === []) {
                continue;
            }
            $details = JsonPath::arrayAt(JsonPath::decodeFile(__DIR__.'/../fixtures/'.JsonPath::stringAt($golden, ['document'])), ['details']);
            foreach ($ladders as $package => $rungs) {
                $checks = [];
                foreach (JsonPath::arrayAt($details, [(string) $package, 'metadata', 'branches']) as $row) {
                    $row = JsonPath::decoded($row);
                    $checks[JsonPath::stringAt($row, ['branch'])] = \is_array($row['fixes'] ?? null) ? ($row['fixes']['check'] ?? null) : null;
                }
                foreach (JsonPath::decoded($rungs) as $rung) {
                    $rung = JsonPath::decoded($rung);
                    $check = $checks[JsonPath::stringAt($rung, ['branch'])] ?? null;
                    $words = self::shortCheckWords(\is_array($check) ? $check : null);
                    $lines = array_values(array_filter(JsonPath::arrayAt($rung, ['lines']), 'is_string'));
                    $forms[] = $words ?? 'none';
                    $last = (string) end($lines);
                    $ends = $words === null || substr($last, -\strlen(' · '.$words)) === ' · '.$words;
                    if (!$ends || ($words !== null && preg_grep('/, if Composer resolves it/', $lines) !== [])) {
                        $differ[$id.' '.$package.' '.JsonPath::stringAt($rung, ['branch'])] = ['expected' => $words, 'lines' => $lines];
                    }
                }
            }
        }

        self::assertSame([], $differ);
        self::assertContains('not checked: '.MoveCheck::reasonWords(MoveCheck::BASELINE_REFUSED), $forms, 'no Security ladder row of a page case ends with the baseline_refused words');
    }

    /**
     * A ‡ page document that lags its source document fails schema validation.
     *
     * @dataProvider provideEveryGolden
     */
    #[DataProvider('provideEveryGolden')]
    public function testEveryPageDocumentFollowsTheReportSchema(string $id): void
    {
        $golden = JsonPath::arrayAt(JsonPath::decodeFile(self::CONTRACT.'page-goldens.json'), ['goldens', $id]);

        $this->assertValid(Schemas::REPORT, self::documentJson(JsonPath::stringAt($golden, ['document'])), $id);
    }

    /**
     * SPEC-0.14.md §7.9.19: a fix note starts with "needs PHP {php}" exactly when the fix's `php` is
     * set and one of its two php answers is false.
     */
    public function testTheFixNotesSayNeedsPhpExactlyWhenAPhpAnswerIsFalse(): void
    {
        $differ = [];
        $needs = [];
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(self::CONTRACT.'page-goldens.json'), ['goldens']) as $id => $golden) {
            $golden = JsonPath::decoded($golden);
            $findings = self::findingsByPackage(self::modelDocument(JsonPath::stringAt($golden, ['document'])));
            foreach (JsonPath::arrayAt($golden, ['advisory_groups']) as $group) {
                $group = JsonPath::decoded($group);
                $package = JsonPath::stringAt($group, ['package']);
                $expected = [];
                foreach (JsonPath::arrayAt($findings[$package], ['signals']) as $signal) {
                    $signal = JsonPath::decoded($signal);
                    foreach (self::textAt($signal, ['id']) === 'S9' ? JsonPath::arrayAt($signal, ['data', 'advisories']) : [] as $row) {
                        $fix = JsonPath::arrayAt(JsonPath::decoded($row), ['fix']);
                        if (\is_string($fix['php'] ?? null) && \in_array(false, [$fix['admits_target_php'] ?? null, $fix['admits_project_php'] ?? null], true)) {
                            $expected[] = 'needs PHP '.$fix['php'];
                        }
                    }
                }
                $stated = [];
                foreach (array_filter(JsonPath::arrayAt($group, ['fix_notes']), 'is_string') as $note) {
                    if (strpos($note, 'needs PHP ') === 0) {
                        $stated[] = explode(' · ', $note)[0];
                    }
                }
                $needs = array_merge($needs, $stated);
                if (array_values(array_unique($expected)) !== array_values(array_unique($stated))) {
                    $differ[$id.' '.$package] = ['expected' => $expected, 'stated' => $stated];
                }
            }
        }

        self::assertSame([], $differ);
        self::assertNotSame([], $needs, 'no fix note of a page case says "needs PHP"');
    }

    /**
     * SPEC-0.14.md §7.9.21 "Holds": a Held by item names its hold in the words of the link table, with
     * the target where the table names it.
     */
    public function testTheSheetHeldByItemsNameEachHoldByTheLinkTable(): void
    {
        $differ = [];
        $forms = [];
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(self::CONTRACT.'page-goldens.json'), ['goldens']) as $id => $golden) {
            $golden = JsonPath::decoded($golden);
            $findings = self::findingsByPackage(self::modelDocument(JsonPath::stringAt($golden, ['document'])));
            foreach (JsonPath::arrayAt($golden, ['sheet_holders']) as $package => $cards) {
                $lead = JsonPath::arrayAt($findings[$package], ['next_step']);
                $words = [];
                foreach (array_merge([$lead], JsonPath::arrayAt($lead, ['also'])) as $move) {
                    foreach (JsonPath::arrayAt(JsonPath::decoded($move), ['held_by']) as $hold) {
                        $hold = JsonPath::decoded($hold);
                        $name = self::textAt($hold, ['source']) === 'root' ? 'your composer.json' : JsonPath::stringAt($hold, ['package']).' '.self::textAt($hold, ['version']);
                        $words[$name][self::linkWords($hold)] = JsonPath::stringAt($hold, ['link']).(self::textAt($hold, ['target']) === null ? '' : ', a target');
                    }
                }
                foreach (array_filter(JsonPath::decoded($cards), 'is_array') as $card) {
                    $card = JsonPath::decoded($card);
                    foreach (array_merge(JsonPath::arrayAt($card, ['shown']), \is_array($card['fold'] ?? null) ? JsonPath::arrayAt($card, ['fold', 'holders']) : []) as $item) {
                        $item = JsonPath::decoded($item);
                        $name = JsonPath::stringAt($item, ['name']);
                        $link = explode(' · ', JsonPath::stringAt($item, ['meta']))[0];
                        if (!isset($words[$name][$link])) {
                            $differ[$id.' '.$package.' '.$name.' '.$link] = array_keys($words[$name] ?? []);
                        } else {
                            $forms[] = $words[$name][$link];
                        }
                    }
                }
            }
        }

        self::assertSame([], $differ);
        foreach (['require', 'require, a target', 'root-require, a target'] as $form) {
            self::assertContains($form, $forms, 'no Held by item of a page case names a hold of the form: '.$form);
        }
    }

    /**
     * SPEC-0.14.md §7.9.21, the link words.
     *
     * @param array<mixed, mixed> $hold
     */
    private static function linkWords(array $hold): string
    {
        $target = self::textAt($hold, ['target']);
        $constraint = JsonPath::stringAt($hold, ['constraint']);
        $link = JsonPath::stringAt($hold, ['link']);
        if ($link === 'replace') {
            return $target === null ? 'replaced by '.JsonPath::stringAt($hold, ['package']) : 'replaces '.$target.' '.$constraint;
        }
        if ($link === 'root-require' && $target === null) {
            return 'no release of '.$constraint.' can install';
        }

        return ($link === 'conflict' ? 'conflicts with ' : 'requires ').($target === null ? '' : $target.' ').$constraint;
    }

    /**
     * @param array<mixed, mixed>|null $check
     */
    private static function shortCheckWords(?array $check): ?string
    {
        $result = $check['result'] ?? null;
        if ($result === MoveCheck::REFUSED) {
            return 'Composer refuses the move';
        }

        return $result === MoveCheck::NOT_CHECKED ? 'not checked: '.MoveCheck::reasonWords(self::textAt($check ?? [], ['reason']) ?? '') : null;
    }

    /**
     * @param array<mixed, mixed> $document
     *
     * @return array<string, array<mixed, mixed>>
     */
    private static function findingsByPackage(array $document): array
    {
        $findings = [];
        foreach (JsonPath::arrayAt($document, ['findings']) as $finding) {
            $finding = JsonPath::decoded($finding);
            $findings[JsonPath::stringAt($finding, ['package'])] = $finding;
        }

        return $findings;
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
        $path = self::documentPath($document);

        return strpos($path, 'compress.zlib://') === 0 ? JsonPath::decoded(json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR)) : JsonPath::decodeFile($path);
    }

    /** The page document as JSON text, whose empty objects the schema reads as objects. */
    private static function documentJson(string $document): string
    {
        if (strpos($document, 'baselined/') === 0) {
            $bundle = json_decode((string) file_get_contents(__DIR__.'/../fixtures/'.$document));
            self::assertInstanceOf(\stdClass::class, $bundle);

            return (string) json_encode(get_object_vars($bundle)['report'] ?? null);
        }

        return (string) file_get_contents(self::documentPath($document));
    }

    private static function documentPath(string $document): string
    {
        if (strpos($document, 'contract/') === 0) {
            return self::CONTRACT.substr($document, \strlen('contract/'));
        }
        $path = strpos($document, 'report-2/') === 0 ? self::DOCUMENTS.'corpus/'.basename($document) : self::DOCUMENTS.$document;

        return is_file($path) ? $path : 'compress.zlib://'.$path.'.gz';
    }

    /** @return array<mixed, mixed>|null */
    private static function lockrotDocument(string $document): ?array
    {
        $path = self::CONTRACT.'report-2/'.basename($document);

        return strpos($document, 'cases/') === 0 && is_file($path) ? JsonPath::decodeFile($path) : null;
    }
}
