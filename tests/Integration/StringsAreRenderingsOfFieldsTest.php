<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\Contract\CaseRecording;
use Lockrot\Tests\Support\Contract\ContractBundle;
use Lockrot\Tests\Support\Contract\Fields;
use Lockrot\Tests\Support\Contract\MissingField;
use Lockrot\Tests\Support\Contract\ReferenceText;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ScoreLineFromJson;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * SPEC-0.14.md §7.11: every human string of the recorded bundle is a rendering of the fields that it
 * lists. {@see ReferenceText} renders each one again from those fields alone, and a string it cannot
 * render from them names the missing field. Re-record the bundle with tools/report/record.
 *
 * @coversNothing
 */
#[CoversNothing]
final class StringsAreRenderingsOfFieldsTest extends TestCase
{
    /** The composite joins the findings of seven projects, so its run holds no require.php of theirs. */
    private const MOVES_OF_ANOTHER_RUN = ['composite'];
    /** The documents of the real baselined projects beside each bundle directory, by their glob below tests/fixtures/baselined/. */
    private const BASELINED = [
        'report-2' => ['*/t0/report.json', '*/check/report.json', '*/check/gates-*.json', '*/check/target-*.json', '*/regenerate/report.json'],
        'explain-2' => ['*/check/explain/*.json'],
    ];
    /** The other formats of a baselined check, which share its stem. */
    private const OTHER_FORMATS = '{\.(sarif|gitlab|bundle)\.json$}';

    public function testEveryStringOfEveryReportIsARenderingOfItsFields(): void
    {
        $differ = [];
        $rendered = [];
        foreach (self::documents('report-2') as $name => $document) {
            self::check($differ, $rendered, $name.' gate.text', JsonPath::stringAt($document, ['gate', 'text']), static fn (): string => ReferenceText::runGate(Fields::only($document, ReferenceText::INPUTS['gate.text']), self::words('gate-words.json')));
            foreach (JsonPath::arrayAt($document, ['findings']) as $finding) {
                self::assertIsArray($finding);
                self::checkFinding($differ, $rendered, $name.' '.JsonPath::stringAt($finding, ['package']), $finding + ['run' => JsonPath::arrayAt($document, ['run'])], !\in_array($name, self::MOVES_OF_ANOTHER_RUN, true));
            }
        }

        self::assertSame([], $differ);
        self::assertGreaterThan(80, $rendered['next_step.text'] ?? 0, 'too few moves rendered to hold the grammar');
        self::assertArrayHasKey('also.text', $rendered);
    }

    public function testEveryStringOfEveryExplanationIsARenderingOfItsFields(): void
    {
        $differ = [];
        $rendered = [];
        foreach (self::documents('explain-2') as $name => $document) {
            self::checkFinding($differ, $rendered, $name, JsonPath::arrayAt($document, ['finding']) + ['run' => JsonPath::arrayAt($document, ['run'])], true);
        }

        self::assertSame([], $differ);
        self::assertArrayHasKey('evidence', $rendered);
    }

    /** The strings of the text formats that a JSON document holds no copy of: each case's PHP goldens. */
    public function testEveryGoldenOfTheTextFormatsIsARenderingOfItsFields(): void
    {
        $differ = [];
        $rendered = [];
        $reasons = self::words('reason-words.json');
        $years = self::words('years-phrases.json');
        foreach (self::documents('goldens') as $name => $goldens) {
            $report = JsonPath::decodeFile(ContractBundle::DIRECTORY.'/report-2/'.$name.'.json');
            $in = ContractBundle::findingOf($report, self::packageOf($name)) + ['run' => JsonPath::arrayAt($report, ['run'])];
            self::check($differ, $rendered, $name.' gate_line', $goldens['gate_line'], static fn (): ?string => ReferenceText::gateLine(Fields::only($in, ReferenceText::INPUTS['gate_line'])));
            self::check($differ, $rendered, $name.' age_line', $goldens['age_line'], static fn (): ?string => ReferenceText::ageLine(Fields::only($in, ReferenceText::INPUTS['age_line']), $reasons, $years));
            self::check($differ, $rendered, $name.' explain_age', $goldens['explain_age'], static fn (): array => ReferenceText::explainAge(Fields::only($in, ReferenceText::INPUTS['explain_age']), self::words('age-words.json'), $reasons, $years));
            self::check($differ, $rendered, $name.' baseline_suffix', $goldens['baseline_suffix'], static fn (): ?string => ReferenceText::baselineSuffix(Fields::only($in, ReferenceText::INPUTS['baseline_suffix'])));
        }

        self::assertSame([], $differ);
        self::assertGreaterThan(80, $rendered['age_line'] ?? 0);
    }

    /**
     * @param array<string, array{expected: mixed, rendered: mixed}> $differ
     * @param array<string, int>                                     $rendered
     * @param array<mixed, mixed>                                    $in
     */
    private static function checkFinding(array &$differ, array &$rendered, string $label, array $in, bool $moves): void
    {
        self::check($differ, $rendered, $label.' score.text', JsonPath::stringAt($in, ['score', 'text']), static fn (): string => ScoreLineFromJson::render(ContractBundle::stringKeys(JsonPath::arrayAt(Fields::only($in, ReferenceText::INPUTS['score.text']), ['score']))));
        self::check($differ, $rendered, $label.' evidence', JsonPath::stringAt($in, ['evidence']), static fn (): string => ReferenceText::evidence(Fields::only($in, ReferenceText::INPUTS['evidence'])));
        $move = $in['next_step'] ?? null;
        if (!$moves || !\is_array($move)) {
            return;
        }
        self::check($differ, $rendered, $label.' next_step.text', JsonPath::stringAt($move, ['text']), static fn (): string => ReferenceText::move(Fields::only($in, ReferenceText::INPUTS['next_step.text'])));
        foreach (\is_array($move['also'] ?? null) ? $move['also'] : [] as $i => $also) {
            self::assertIsArray($also);
            $only = Fields::only($in, ReferenceText::INPUTS['also.text']);
            $only['next_step'] = JsonPath::arrayAt($only, ['next_step', 'also', $i]);
            self::check($differ, $rendered, $label.' also.text', JsonPath::stringAt($also, ['text']), static fn (): string => ReferenceText::move($only));
        }
    }

    /**
     * @param array<string, array{expected: mixed, rendered: mixed}> $differ
     * @param array<string, int>                                     $rendered
     * @param mixed                                                  $expected
     */
    private static function check(array &$differ, array &$rendered, string $label, $expected, callable $render): void
    {
        $key = substr($label, (int) strrpos($label, ' ') + 1);
        try {
            $actual = $render();
        } catch (MissingField $missing) {
            $actual = $missing->getMessage();
        }
        $rendered[$key] = ($rendered[$key] ?? 0) + 1;
        if ($actual !== $expected) {
            $differ[$label] = ['expected' => $expected, 'rendered' => $actual];
        }
    }

    /**
     * @return array<string, array<mixed, mixed>> each decoded file of a bundle directory, by its name, then each
     *                                            baselined document of its kind, by its path
     */
    private static function documents(string $directory): array
    {
        $files = glob(ContractBundle::DIRECTORY.'/'.$directory.'/*.json') ?: [];
        sort($files);
        self::assertNotSame([], $files, 'record the bundle with tools/report/record');
        $documents = [];
        foreach ($files as $file) {
            $documents[basename($file, '.json')] = JsonPath::decodeFile($file);
        }
        $root = \dirname(ContractBundle::DIRECTORY).'/baselined/';
        foreach (self::BASELINED[$directory] ?? [] as $pattern) {
            $baselined = array_filter(glob($root.$pattern) ?: [], static fn (string $file): bool => preg_match(self::OTHER_FORMATS, $file) !== 1);
            sort($baselined);
            self::assertNotSame([], $baselined, 'record the baselined scenarios with tools/report/record --all-baselined');
            foreach ($baselined as $file) {
                $documents['baselined/'.substr($file, \strlen($root))] = JsonPath::decodeFile($file);
            }
        }

        return $documents;
    }

    /** The case package of a recorded case document, by its file name. */
    private static function packageOf(string $name): string
    {
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(CaseHydrator::CASES), ['cases']) as $case) {
            if (\is_array($case) && CaseRecording::fileName(JsonPath::stringAt($case, ['id'])) === $name) {
                return JsonPath::stringAt($case, ['finding', 'package']);
            }
        }
        throw new \UnexpectedValueException('no case is recorded as '.$name);
    }

    /** @return array<mixed, mixed> */
    private static function words(string $file): array
    {
        return JsonPath::decodeFile(ContractBundle::DIRECTORY.'/en/'.$file);
    }
}
