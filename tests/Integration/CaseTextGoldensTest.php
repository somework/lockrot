<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Output\AgeText;
use Lockrot\Output\ShellCommand;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\Finding;
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
