<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Analyzer\Report;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\RunFooter;
use Lockrot\Tests\Support\Contract\ContractBundle;
use Lockrot\Tests\Support\Contract\Fields;
use Lockrot\Tests\Support\Contract\MissingField;
use Lockrot\Tests\Support\Contract\ReferenceText;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ScoreLineFromJson;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * SPEC-0.14.md §7.11 item 2: each `x-rendered-from` list names exactly the fields that its string
 * renders from. {@see StringsAreRenderingsOfFieldsTest} shows that the listed fields suffice. This
 * test shows that the lists are the schemas' and that each listed field is read.
 *
 * @coversNothing
 */
#[CoversNothing]
final class RenderedFromTest extends TestCase
{
    private const SCHEMAS = [__DIR__.'/../../resources/lockrot-report-2.schema.json', __DIR__.'/../../resources/lockrot-explain-2.schema.json'];

    /** The schema definition of each string that ReferenceText renders, by its key in ReferenceText::INPUTS. */
    private const DEFINITIONS = [
        'score.text' => 'scoreGraded/properties/text',
        'next_step.text' => 'move/properties/text',
        'also.text' => 'alsoMove/properties/text',
        'evidence' => 'finding/properties/evidence',
        'gate.text' => 'rootGate/properties/text',
    ];

    /** A listed field that no rendering needs, because other listed fields decide it. */
    private const NOT_DECIDING = [
        'score.text' => ['score.rounded_down' => 'it is `exact` != `total`, which the line reads'],
    ];

    public function testEachListOfTheSchemasIsTheInputsOfItsRenderer(): void
    {
        foreach (self::SCHEMAS as $file) {
            $schema = JsonPath::decodeFile($file);
            foreach (self::DEFINITIONS as $key => $pointer) {
                $path = array_merge(['definitions'], explode('/', $pointer));
                if (!JsonPath::has($schema, $path)) {
                    continue;
                }
                self::assertSame(ReferenceText::INPUTS[$key], JsonPath::arrayAt($schema, array_merge($path, ['x-rendered-from'])), basename($file).' '.$pointer);
            }
        }
    }

    public function testEveryListedFieldIsReadByItsRenderer(): void
    {
        $unread = [];
        foreach (ReferenceText::INPUTS as $key => $paths) {
            foreach ($paths as $path) {
                if (isset(self::NOT_DECIDING[$key][$path])) {
                    continue;
                }
                // also[] moves render by the grammar of next_step: a field that next_step reads counts.
                $as = $key === 'also.text' && $path !== 'next_step.also[]' ? 'next_step.text' : $key;
                if (!self::someRenderingNeeds($as, $path)) {
                    $unread[] = $key.': '.$path;
                }
            }
        }

        self::assertSame([], $unread, 'a listed field that no recorded rendering reads');
    }

    public function testTheDepthLineChangesWhenOnlyTheDepthChanges(): void
    {
        $line = static function (array $chain): ?string {
            $finding = (new FindingBuilder())->withVerdict(Verdict::STALE)->withChain($chain)->withNamedIn([ProjectConfig::REQUIRE])->build();

            return RunFooter::depth(new Report([$finding], [], new \DateTimeImmutable('2026-10-14T00:00:00+00:00'), 1, 0), static fn (string $name): string => $name);
        };

        self::assertNotSame($line(['root/project', 'vendor/a', 'vendor/pkg']), $line(['root/project', 'vendor/a', 'vendor/b', 'vendor/pkg']));
    }

    public function testTheExplainReachLineChangesWhenOnlyTheDepthChanges(): void
    {
        $line = static fn (array $chain): string => ExplainFormatter::reachLine((new FindingBuilder())->withChain($chain)->withNamedIn([ProjectConfig::REQUIRE])->build(), false);

        self::assertNotSame($line(['root/project', 'vendor/a', 'vendor/pkg']), $line(['root/project', 'vendor/a', 'vendor/b', 'vendor/pkg']));
    }

    /** Whether leaving $path out of the inputs changes the rendering of $key on one recorded document. */
    private static function someRenderingNeeds(string $key, string $path): bool
    {
        $inputs = ReferenceText::INPUTS[$key];
        $without = array_values(array_diff($inputs, [$path]));
        foreach (self::inputsOf($key) as $in) {
            try {
                $full = self::render($key, Fields::only($in, $inputs));
            } catch (MissingField|\UnexpectedValueException $absent) {
                continue;
            }
            // A renderer that meets a pruned field fails, whether it throws or only warns.
            set_error_handler(static function (int $severity, string $message): bool {
                throw new \ErrorException($message, 0, $severity);
            });
            try {
                if (self::render($key, Fields::only($in, $without)) !== $full) {
                    return true;
                }
            } catch (\Throwable $broken) {
                return true;
            } finally {
                restore_error_handler();
            }
        }

        return false;
    }

    /**
     * @param array<mixed, mixed> $in
     *
     * @return mixed
     */
    private static function render(string $key, array $in)
    {
        $words = static fn (string $file): array => JsonPath::decodeFile(ContractBundle::DIRECTORY.'/en/'.$file);
        switch ($key) {
            case 'score.text':
                return ScoreLineFromJson::render(\is_array($in['score'] ?? null) ? $in['score'] : []);
            case 'next_step.text':
                return ReferenceText::move($in);
            case 'also.text':
                $in['next_step'] = JsonPath::arrayAt($in, ['next_step', 'also', 0]);

                return ReferenceText::move($in);
            case 'gate.text':
                return ReferenceText::runGate($in, $words('gate-words.json'));
            case 'evidence':
                return ReferenceText::evidence($in);
            case 'gate_line':
                return ReferenceText::gateLine($in);
            case 'age_line':
                return ReferenceText::ageLine($in, $words('reason-words.json'), $words('years-phrases.json'));
            case 'explain_age':
                return ReferenceText::explainAge($in, $words('age-words.json'), $words('reason-words.json'), $words('years-phrases.json'));
            case 'baseline_suffix':
                return ReferenceText::baselineSuffix($in);
            default:
                throw new \LogicException('no renderer for '.$key);
        }
    }

    /** @return iterable<array<mixed, mixed>> the inputs that $key renders from: the report root for gate.text, else each finding with `run` */
    private static function inputsOf(string $key): iterable
    {
        $files = glob(ContractBundle::DIRECTORY.'/report-2/*.json') ?: [];
        sort($files);
        self::assertNotSame([], $files, 'record the bundle with tools/report/record');
        foreach ($files as $file) {
            if (basename($file) === 'composite.json' && $key !== 'gate.text') {
                continue;
            }
            $document = JsonPath::decodeFile($file);
            if ($key === 'gate.text') {
                yield $document;
                continue;
            }
            foreach (JsonPath::arrayAt($document, ['findings']) as $finding) {
                if (\is_array($finding) && ($key !== 'also.text' || JsonPath::has($finding, ['next_step', 'also', 0]))) {
                    yield $finding + ['run' => JsonPath::arrayAt($document, ['run'])];
                }
            }
        }
    }
}
