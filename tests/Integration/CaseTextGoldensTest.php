<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Output\AgeText;
use Lockrot\Solver\MoveCheck;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\CommandArgument;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\ShellCommand;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The text grammars against the model's goldens (`golden.*` of tests/fixtures/flags/cases.json),
 * on every case with an `inputs` block, hydrated through the real analyzer.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class CaseTextGoldensTest extends TestCase
{
    private const REFUSED = 'This command holds characters the page does not print.';

    public function testTheAgeLineOfEveryCaseIsItsGolden(): void
    {
        $differ = [];
        $compared = 0;
        foreach (self::cases() as $id => [$case, $finding, $written]) {
            if (self::ageInputs(JsonPath::arrayAt($case, ['finding'])) !== self::ageInputs($written)) {
                continue;
            }
            ++$compared;
            $golden = JsonPath::arrayAt($case, ['golden']);
            $expected = \is_string($golden['age_line'] ?? null) ? $golden['age_line'] : null;
            $actual = AgeText::line($finding);
            if ($actual !== $expected) {
                $differ[$id] = ['expected' => $expected, 'actual' => $actual];
            }
        }

        self::assertSame([], $differ);
        self::assertGreaterThan(20, $compared, 'the hydrated readings agree with the model on too few cases to pin the grammar');
    }

    public function testEveryWrittenCommandIsAnArgvListAndEachModelCommandRendersAsItsGoldens(): void
    {
        $differ = [];
        foreach (self::cases() as $id => [$case, $finding, $written]) {
            $golden = JsonPath::arrayAt($case, ['golden']);
            $model = JsonPath::arrayAt($case, ['finding']);
            $argvs = \is_array($model['next_step'] ?? null) && \is_array($model['next_step']['commands'] ?? null) ? $model['next_step']['commands'] : [];
            foreach (\is_array($written['next_step'] ?? null) && \is_array($written['next_step']['commands'] ?? null) ? $written['next_step']['commands'] : [] as $argv) {
                self::assertIsArray($argv, $id);
                self::assertSame(array_values(array_filter($argv, 'is_string')), $argv, $id);
            }
            $actual = ['posix' => [], 'cmd' => []];
            foreach ($argvs as $argv) {
                self::assertIsArray($argv, $id);
                $strings = array_values(array_filter($argv, 'is_string'));
                $actual['posix'][] = ShellCommand::posix($strings);
                $actual['cmd'][] = ShellCommand::cmd($strings);
            }
            $expected = ['posix' => $golden['commands_posix'] ?? [], 'cmd' => $golden['commands_cmd'] ?? []];
            if ($actual !== $expected) {
                $differ[$id] = ['expected' => $expected, 'actual' => $actual];
            }
        }

        self::assertSame([], $differ);
    }

    /**
     * SPEC-0.14.md §7.9.8.6: the eyebrow of a move card's command box names the number and the kind
     * of the move's steps. Each card is the lead move or one `also[]` move, in order.
     */
    public function testEachMoveCardsCommandsLabelNamesItsSteps(): void
    {
        $differ = [];
        $labels = [];
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(__DIR__.'/../fixtures/flags/cases.json'), ['cases']) as $case) {
            $case = JsonPath::decoded($case);
            $id = JsonPath::stringAt($case, ['id']);
            $cards = JsonPath::has($case, ['golden', 'moves']) ? JsonPath::arrayAt($case, ['golden', 'moves']) : [];
            $lead = JsonPath::has($case, ['finding', 'next_step', 'kind']) ? JsonPath::arrayAt($case, ['finding', 'next_step']) : null;
            $moves = $lead === null ? [] : array_merge([$lead], JsonPath::arrayAt($lead, ['also']));
            self::assertCount(\count($moves), $cards, $id);
            foreach (array_values($moves) as $i => $move) {
                $steps = JsonPath::arrayAt(JsonPath::decoded($move), ['commands']);
                $whyNot = array_filter($steps, static fn ($argv): bool => \is_array($argv) && ($argv[1] ?? null) === 'why-not');
                $expected = $steps === [] ? null : (\count($whyNot) === \count($steps) ? 'Check' : (\count($steps) === 1 ? 'Command' : 'Commands, in order'));
                $actual = JsonPath::decoded($cards[$i])['commands_label'] ?? null;
                $labels[] = $actual;
                if ($actual !== $expected) {
                    $differ[$id.' card '.$i] = ['expected' => $expected, 'actual' => $actual];
                }
            }
        }

        self::assertSame([], $differ);
        self::assertEqualsCanonicalizing([null, 'Check', 'Command', 'Commands, in order'], array_values(array_unique($labels, \SORT_REGULAR)), 'the cases reach every label');
    }

    /**
     * SPEC-0.14.md §7.9.8.6: a move card's command box prints the refusal line exactly when
     * CommandArgument refuses one argv of the move.
     */
    public function testAMoveCardRefusesItsCommandsExactlyWhenCommandArgumentRefusesOneStep(): void
    {
        $differ = [];
        $compared = 0;
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(__DIR__.'/../fixtures/flags/cases.json'), ['cases']) as $case) {
            $case = JsonPath::decoded($case);
            $id = JsonPath::stringAt($case, ['id']);
            $cards = JsonPath::has($case, ['golden', 'moves']) ? JsonPath::arrayAt($case, ['golden', 'moves']) : [];
            $lead = JsonPath::has($case, ['finding', 'next_step', 'kind']) ? JsonPath::arrayAt($case, ['finding', 'next_step']) : null;
            foreach (array_values($lead === null ? [] : array_merge([$lead], JsonPath::arrayAt($lead, ['also']))) as $i => $move) {
                $steps = JsonPath::arrayAt(JsonPath::decoded($move), ['commands']);
                $passes = array_filter($steps, static fn ($argv): bool => \is_array($argv) && CommandArgument::allPass(array_values(array_filter($argv, 'is_string'))));
                $expected = $steps !== [] && \count($passes) < \count($steps);
                $actual = JsonPath::arrayAt(JsonPath::decoded($cards[$i] ?? []), ['commands']) === [self::REFUSED];
                $compared += (int) ($steps !== []);
                if ($actual !== $expected) {
                    $differ[$id.' card '.$i] = ['steps' => $steps, 'refused' => $actual];
                }
            }
        }

        self::assertSame([], $differ);
        self::assertGreaterThan(0, $compared, 'no case golden shows a command box');
    }

    /**
     * SPEC-0.14.md §7.9.21 "The move" and G104: the After block of a move that runs a Composer request
     * holds one check line. The move's check and the run's move check give its words. Another kind holds none.
     */
    public function testTheAfterBlockOfAMoveCardHoldsItsCheckLine(): void
    {
        $differ = [];
        $lines = [];
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(__DIR__.'/../fixtures/flags/cases.json'), ['cases']) as $case) {
            $case = JsonPath::decoded($case);
            $id = JsonPath::stringAt($case, ['id']);
            $cards = JsonPath::has($case, ['golden', 'moves']) ? JsonPath::arrayAt($case, ['golden', 'moves']) : [];
            $runChecked = JsonPath::has($case, ['run', 'move_check', 'baseline']);
            $lead = JsonPath::has($case, ['finding', 'next_step', 'kind']) ? JsonPath::arrayAt($case, ['finding', 'next_step']) : null;
            foreach (array_values($lead === null ? [] : array_merge([$lead], JsonPath::arrayAt($lead, ['also']))) as $i => $move) {
                $move = JsonPath::decoded($move);
                $result = JsonPath::has($move, ['check', 'result']) ? JsonPath::stringAt($move, ['check', 'result']) : null;
                $reason = $result === MoveCheck::NOT_CHECKED ? JsonPath::stringAt($move, ['check', 'reason']) : '';
                $assumes = JsonPath::has($move, ['if_applied', 'assumes']) ? JsonPath::arrayAt($move, ['if_applied', 'assumes']) : [];
                $expected = null;
                if (\in_array($move['kind'] ?? null, ['update', 'require', 'raise-php', 'tag'], true)) {
                    $expected = [
                        'resolves' => 'checked with Composer',
                        'refused' => 'Composer refuses the move',
                        'not_checked' => 'if Composer resolves it (not checked: '.MoveCheck::reasonWords($reason).')',
                    ][(string) $result] ?? ($runChecked ? (\in_array('composer_resolves', $assumes, true) ? 'if Composer resolves it' : null) : 'if Composer resolves it (not checked: the run checked no move)');
                }
                $after = JsonPath::decoded($cards[$i] ?? [])['after'] ?? null;
                $conditions = \is_array($after) && \is_array($after['conditions'] ?? null) ? $after['conditions'] : [];
                $actual = array_values(preg_grep('/^(checked with Composer|Composer refuses the move|if Composer resolves it)/', array_filter($conditions, 'is_string')) ?: []);
                $lines[] = $expected;
                if ($actual !== ($expected === null ? [] : [$expected]) || ($expected !== null && ($move['if_applied'] ?? null) === null && $after !== ['result' => null, 'conditions' => [$expected]])) {
                    $differ[$id.' card '.$i] = ['expected' => $expected, 'after' => $after];
                }
            }
        }

        self::assertSame([], $differ);
        self::assertContains('checked with Composer', $lines, 'no case golden shows a move that Composer resolves');
        self::assertContains('if Composer resolves it (not checked: the run checked no move)', $lines, 'no case golden shows a run that checked no move');
    }

    /**
     * The fields that AgeText reads. A case whose hydrated readings differ from the model's (a
     * clock a day apart moves a tenth) tests the hydration, not the grammar.
     *
     * @param array<mixed, mixed> $finding
     *
     * @return array<mixed, mixed>
     */
    private static function ageInputs(array $finding): array
    {
        $activity = \is_array($finding['activity'] ?? null) ? $finding['activity'] : [];

        return [$finding['age'] ?? null, $finding['version'] ?? null, $finding['branch'] ?? null, $activity['event'] ?? null];
    }

    /** @return iterable<string, array{array<mixed, mixed>, Finding, array<mixed, mixed>}> */
    private static function cases(): iterable
    {
        $cases = JsonPath::arrayAt(JsonPath::decodeFile(__DIR__.'/../fixtures/flags/cases.json'), ['cases']);
        self::assertNotSame([], $cases);
        foreach ($cases as $case) {
            if (!\is_array($case) || !\is_array($case['inputs'] ?? null)) {
                continue;
            }
            $package = JsonPath::stringAt($case, ['finding', 'package']);
            $report = CaseHydrator::report($case);
            foreach ($report->findings() as $finding) {
                if ($finding->package() === $package) {
                    yield JsonPath::stringAt($case, ['id']) => [$case, $finding, JsonPath::arrayAt(JsonPath::decoded($report->findingRows()), [$package])];
                }
            }
        }
    }
}
