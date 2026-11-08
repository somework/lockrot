<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\Contract\CaseRecording;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FailOn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §5.6, the command argument rule: a version that a step quotes and that holds `|`, `<` or `>`
 * runs another program or redirects to a file when a Windows reader pastes the POSIX line. The move
 * keeps everything but its steps, and every text format prints no command and says why.
 */
final class UnsafeCommandArgumentTest extends TestCase
{
    private const PACKAGE = 'typo3/html-sanitizer';
    private const CLAUSE = '; lockrot prints no command, because its release name holds |, < or >';

    /** @return iterable<string, array{string}> */
    public static function unsafeVersions(): iterable
    {
        yield 'a pipe' => ['v2.3.2+x|calc|x'];
        yield 'a less-than sign' => ['v2.3.2+x<y'];
        yield 'a greater-than sign' => ['v2.3.2+x>y'];
    }

    /**
     * @dataProvider unsafeVersions
     */
    #[DataProvider('unsafeVersions')]
    public function testAnUpdateMoveWithAnUnsafeVersionHasNoStepAndSaysWhy(string $version): void
    {
        $move = self::nextStep(self::document($version, 'v2.3.2', 'move-check‡'), self::PACKAGE);

        self::assertSame([], $move['commands']);
        self::assertSame('update', $move['kind']);
        self::assertSame($version, $move['version']);
        self::assertStringEndsWith(self::CLAUSE, JsonPath::stringAt($move, ['text']));
        self::assertStringContainsString('update it with Composer (gets '.$version.')', JsonPath::stringAt($move, ['text']));
    }

    /**
     * @dataProvider unsafeVersions
     */
    #[DataProvider('unsafeVersions')]
    public function testARunNoteNamesTheMoveThatHasNoStep(string $version): void
    {
        $document = self::document($version, 'v2.3.2', 'move-check‡');

        $notes = array_values(array_filter(JsonPath::arrayAt($document, ['note_details']), static fn ($note): bool => \is_array($note) && $note['code'] === 'command_argument_unsafe'));
        self::assertCount(1, $notes);
        self::assertSame([['package' => self::PACKAGE, 'version' => $version]], JsonPath::arrayAt($notes[0], ['data', 'moves']));
        self::assertSame('lockrot prints no command for 1 move: a release name holds |, < or >, which cmd.exe and PowerShell run or redirect', JsonPath::stringAt($notes[0], ['text']));
    }

    /**
     * @dataProvider unsafeVersions
     */
    #[DataProvider('unsafeVersions')]
    public function testNoTextFormatPrintsACommandThatHoldsTheVersion(string $version): void
    {
        foreach (self::formatted($version, 'v2.3.2', 'move-check‡') as $format => $output) {
            if ($format === 'json') {
                continue;
            }
            // `fix, by package: composer update 1` names a fix kind, a command names a package.
            self::assertDoesNotMatchRegularExpression('{composer (update|require) \'?[a-z0-9-]+/}', $output, $format);
            // The table wraps its lines.
            self::assertStringContainsString('lockrot prints no command', (string) preg_replace('/\\s+/', ' ', $output), $format);
        }
    }

    /** A raw `|` in a markdown table cell starts another cell, so each row keeps the header's six cells. */
    public function testAMarkdownRowWithAPipeInItsReleaseNameKeepsItsCells(): void
    {
        $markdown = self::formatted('v2.3.2+x|calc', 'v2.3.2', 'move-check‡')['markdown'];
        $rows = array_values(array_filter(explode("\n", $markdown), static fn (string $line): bool => strncmp($line, '| ', 2) === 0));

        self::assertStringContainsString('v2.3.2+x\\|calc', $markdown);
        self::assertNotSame([], $rows);
        foreach ($rows as $row) {
            self::assertCount(8, preg_split('{(?<!\\\\)\|}', $row) ?: [], $row);
        }
    }

    /**
     * @dataProvider unsafeVersions
     */
    #[DataProvider('unsafeVersions')]
    public function testATagMoveWithAnUnsafeTagHasNoStepAndNamesTheTagInTheNote(string $version): void
    {
        $tag = '1.6.2'.substr($version, \strlen('v2.3.2'));
        $document = self::document($tag, '1.6.2', 'G-tag‡');
        $move = self::nextStep($document, 'friendsofsymfony/oauth-server-bundle');

        self::assertSame(['tag', []], [$move['kind'], $move['commands']]);
        self::assertSame($tag, JsonPath::stringAt($move, ['latest', 'version']));
        self::assertStringEndsWith(self::CLAUSE, JsonPath::stringAt($move, ['text']));
        $notes = array_values(array_filter(JsonPath::arrayAt($document, ['note_details']), static fn ($note): bool => \is_array($note) && $note['code'] === 'command_argument_unsafe'));
        self::assertSame([['package' => 'friendsofsymfony/oauth-server-bundle', 'version' => $tag]], JsonPath::arrayAt($notes[0], ['data', 'moves']));
    }

    public function testARequireMoveWithAnUnsafeVersionDropsItsPhpStepToo(): void
    {
        $move = self::nextStep(self::document('v3.27.0+x|calc', 'v3.27.0', 'A'), 'twig/twig');

        self::assertSame([], $move['commands']);
        self::assertSame('^3.27.0+x|calc', $move['constraint']);
    }

    public function testTheRunNoteNamesAnUnsafeAlsoMove(): void
    {
        self::assertSame([['package' => 'symfony/debug', 'version' => 'v4.4.0+x|calc']], self::noteMoves('either-critical‡', ['v4.4.0' => 'v4.4.0+x|calc']));
    }

    public function testTheRunNoteNamesTheNextStepBeforeTheAlsoMove(): void
    {
        $moves = self::noteMoves('B-also‡', ['v2.8.52' => 'v2.8.52+x|calc', 'v5.4.0' => 'v5.4.0+x<y']);

        self::assertSame([['package' => 'symfony/monolog-bridge', 'version' => 'v2.8.52+x|calc'], ['package' => 'symfony/monolog-bridge', 'version' => 'v5.4.0+x<y']], $moves);
    }

    /** The report sorts twig/twig before symfony/twig-bridge, so the note does not follow the package names. */
    public function testTheRunNoteNamesTheMovesInReportOrder(): void
    {
        $moves = self::noteMoves('A', ['v3.27.0' => 'v3.27.0+x|calc', 'v4.4.51' => 'v4.4.51+x>y']);

        self::assertSame([['package' => 'twig/twig', 'version' => 'v3.27.0+x|calc'], ['package' => 'symfony/twig-bridge', 'version' => 'v4.4.51+x>y']], $moves);
    }

    public function testASafeMoveKeepsItsPhpStepAndWritesNoNote(): void
    {
        $document = self::document('v3.27.0', 'v3.27.0', 'A');
        $move = self::nextStep($document, 'twig/twig');

        self::assertSame(['composer', 'require', 'php:>=8.1', '--no-update'], JsonPath::arrayAt($move, ['commands', 0]));
        self::assertStringNotContainsString('lockrot prints no command', JsonPath::stringAt($move, ['text']));
        self::assertSame([], array_filter(JsonPath::arrayAt($document, ['note_details']), static fn ($note): bool => \is_array($note) && $note['code'] === 'command_argument_unsafe'));
    }

    /**
     * SPEC §5.6, "Update now": the unsafe update is the only candidate, so no `update now:` line
     * prints, as with an empty candidate set.
     */
    public function testAnUnsafeUpdateThatIsTheOnlyCandidateLeavesNoUpdateNowLine(): void
    {
        $case = CaseHydrator::case('move-check‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['checks'] = ['*' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]];
        $inputs['releases'] = array_map(static fn ($release): array => \is_array($release) && ($release['version'] ?? null) === 'v2.3.2' ? ['version' => 'v2.3.2+x|calc'] + $release : (array) $release, array_values(JsonPath::arrayAt($inputs, ['releases'])));
        $case['inputs'] = $inputs;
        $report = CaseHydrator::report($case);

        $document = JsonPath::decoded($report->toArray());
        self::assertSame([[], null], [JsonPath::arrayAt($document, ['security', 'update_now']), JsonPath::arrayAt($document, ['security'])['update_now_command']]);
        foreach (CaseRecording::formatted($report, FailOn::none(), CaseHydrator::lockJson(['move-check‡'])) as $format => $output) {
            self::assertStringNotContainsString('update now:', $output, $format);
        }
    }

    /**
     * Without the move check, the `why-not` step quotes the release name, so the move prints no
     * command. `update now` names the package with no version, so the rule does not apply there.
     */
    public function testARunWithoutTheMoveCheckDropsTheMovesStepsAndKeepsUpdateNowByName(): void
    {
        $case = CaseHydrator::case('move-check‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        unset($inputs['checks']);
        $inputs['releases'] = array_map(static fn ($release): array => \is_array($release) && ($release['version'] ?? null) === 'v2.3.2' ? ['version' => 'v2.3.2+x|calc'] + $release : (array) $release, array_values(JsonPath::arrayAt($inputs, ['releases'])));
        $case['inputs'] = $inputs;

        $document = JsonPath::decoded(CaseHydrator::report($case)->toArray());

        self::assertSame([], JsonPath::arrayAt(self::nextStep($document, self::PACKAGE), ['commands']));
        self::assertSame([[self::PACKAGE], ['composer', 'update', self::PACKAGE]], [JsonPath::arrayAt($document, ['security', 'update_now']), JsonPath::arrayAt($document, ['security', 'update_now_command'])]);
        $notes = array_values(array_filter(JsonPath::arrayAt($document, ['note_details']), static fn ($note): bool => \is_array($note) && $note['code'] === 'command_argument_unsafe'));
        self::assertSame([['package' => self::PACKAGE, 'version' => 'v2.3.2+x|calc']], JsonPath::arrayAt($notes[0], ['data', 'moves']));
    }

    /**
     * @return array<mixed, mixed> the report-2 document, not held to its schema
     */
    private static function document(string $to, string $from, string $id): array
    {
        return JsonPath::decoded(json_decode(self::formatted($to, $from, $id)['json'], true));
    }

    /**
     * @return array<string, string> by format
     */
    private static function formatted(string $to, string $from, string $id): array
    {
        $case = CaseHydrator::case($id);
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['releases'] = array_map(static fn ($release): array => \is_array($release) && ($release['version'] ?? null) === $from ? ['version' => $to] + $release : (array) $release, array_values(JsonPath::arrayAt($inputs, ['releases'])));
        $case['inputs'] = $inputs;

        return CaseRecording::formatted(CaseHydrator::report($case), FailOn::none(), CaseHydrator::lockJson([$id]));
    }

    /**
     * @param array<string, string> $renames release version => the version that replaces it, in the case's package and in its related packages
     *
     * @return array<mixed, mixed> `data.moves` of the command_argument_unsafe note
     */
    private static function noteMoves(string $id, array $renames): array
    {
        $case = CaseHydrator::case($id);
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $rename = static fn (array $releases): array => array_map(static fn ($release): array => \is_array($release) && \is_string($release['version'] ?? null) && isset($renames[$release['version']]) ? ['version' => $renames[$release['version']]] + $release : (array) $release, array_values($releases));
        $inputs['releases'] = $rename(JsonPath::arrayAt($inputs, ['releases']));
        if (\is_array($inputs['packages'] ?? null)) {
            $related = $inputs['packages'];
            foreach ($related as $name => $facts) {
                if (\is_array($facts) && \is_array($facts['releases'] ?? null)) {
                    $related[$name] = ['releases' => $rename($facts['releases'])] + $facts;
                }
            }
            $inputs['packages'] = $related;
        }
        $case['inputs'] = $inputs;
        $document = JsonPath::decoded(CaseHydrator::report($case)->toArray());
        $notes = array_values(array_filter(JsonPath::arrayAt($document, ['note_details']), static fn ($note): bool => \is_array($note) && $note['code'] === 'command_argument_unsafe'));
        self::assertCount(1, $notes);

        return JsonPath::arrayAt($notes[0], ['data', 'moves']);
    }

    /**
     * @param array<mixed, mixed> $document
     *
     * @return array<mixed, mixed>
     */
    private static function nextStep(array $document, string $package): array
    {
        foreach (JsonPath::arrayAt($document, ['findings']) as $finding) {
            if (\is_array($finding) && $finding['package'] === $package) {
                return JsonPath::arrayAt($finding, ['next_step']);
            }
        }
        self::fail('no finding for '.$package);
    }
}
