<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit;

use Lockrot\Tests\Support\WrittenArrayReads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Arrays exist only at the JSON boundary: a domain object computes each value, and only a writer
 * reads an array that lockrot wrote ({@see WrittenArrayReads}). The count of {@see MIXED_MAP} in
 * each file of src/ stays at its ceiling. Lower a ceiling when the count drops, and never raise
 * one: a new value gets a typed object.
 */
final class ArraysAtTheBoundaryTest extends TestCase
{
    private const WRITERS = [
        'Lockrot\Analyzer\Report',
        'Lockrot\Baseline\BaselineFile',
        'Lockrot\Explain\Explanation',
        'Lockrot\Html\ReportDocument',
        'Lockrot\Json\JsonWriter',
        // Writes report-2's `signals[].data.advisories[]`.
        'Lockrot\Signal\Rule\AdvisoryRule',
    ];
    /** Per class, the writers that can pass it a written array. */
    private const HOLDERS = [
        // A signal holds report-2's `signals[].data` as its rule wrote it.
        'Lockrot\Signal\Signal' => ['Lockrot\Signal\Rule\AdvisoryRule'],
    ];
    /** The namespaces whose every class writes a document: the formatters and report-1. */
    private const WRITER_NAMESPACES = ['Lockrot\Output\\', 'Lockrot\Legacy\\'];

    /** A file of src/ that is not listed has a ceiling of 0. */
    private const CEILINGS = [
        'Allowlist/ProjectIgnoreList.php' => 1,
        'Analyzer/Report.php' => 2,
        'Analyzer/RunNote.php' => 4,
        'Analyzer/RunSettings.php' => 3,
        'Baseline/Baseline.php' => 5,
        'Baseline/BaselineComparison.php' => 1,
        'Baseline/BaselineFile.php' => 1,
        'Baseline/BaselineSchema.php' => 3,
        'Clock.php' => 1,
        'Composer/AnalyzerBootstrap.php' => 1,
        'Composer/ComposerHttpClient.php' => 1,
        'Composer/LockrotCommand.php' => 1,
        'Config/ConfigSchema.php' => 1,
        'Config/LockrotConfig.php' => 18,
        'Config/UnknownKeys.php' => 1,
        'Data/Abandoned/AbandonedIgnore.php' => 1,
        'Data/Abandoned/AbandonedPolicyReader.php' => 3,
        'Data/Abandoned/ComposerAbandonedPolicyReader.php' => 2,
        'Data/Advisory/AdvisoryIgnore.php' => 5,
        'Data/Advisory/AdvisoryPolicyReader.php' => 2,
        'Data/Forge/ForgeApi.php' => 1,
        'Data/Forge/SupportSource.php' => 1,
        'Data/Forge/Tokens.php' => 2,
        'Data/Http/HttpResult.php' => 4,
        'Data/Repository/PackageMetadata.php' => 1,
        'Explain/Explanation.php' => 3,
        'Html/ReportDocument.php' => 2,
        'Json/JsonReader.php' => 4,
        'Json/KnownValues.php' => 1,
        'Json/SchemaPayload.php' => 2,
        'Legacy/SignalData013.php' => 1,
        'Lock/ConfiguredRepositories.php' => 1,
        'Lock/LockFile.php' => 1,
        'Lock/ProjectConfig.php' => 6,
        'Output/ExplainFormatter.php' => 1,
        'Output/GitlabFormatter.php' => 2,
        'Output/HtmlFormatter.php' => 1,
        'Output/SarifFormatter.php' => 4,
        'SelfUpdate/ReleaseLocator.php' => 9,
        'Signal/Rule/AdvisoryRule.php' => 3,
        'Signal/Rule/PinnedRule.php' => 1,
        'Signal/Signal.php' => 3,
        'Signal/Thresholds.php' => 1,
        'Verdict/Finding.php' => 10,
        'Verdict/FindingDetails.php' => 9,
        'Verdict/FlagSentence.php' => 4,
        'Verdict/ScoreModel.php' => 6,
    ];

    /** Any spacing of `array<string, mixed>`, `array<array-key, mixed>` and `array<mixed…>`. */
    private const MIXED_MAP = '/array<\\s*(?:mixed\\b[^>]*|(?:string|array-key)\\s*,\\s*mixed\\s*)>/';

    public function testOnlyAWriterReadsAnArrayThatLockrotWrote(): void
    {
        $violations = [];
        foreach (self::sourceFiles() as $relative => $path) {
            foreach (WrittenArrayReads::inSource(self::read($path), [self::class, 'isWriter'], self::HOLDERS) as $violation) {
                $violations[] = $relative.':'.$violation;
            }
        }

        self::assertSame([], $violations, 'Read the value from the domain object that computes it.');
    }

    public function testEachFileKeepsItsCountOfMixedArraysAtItsCeiling(): void
    {
        $counts = [];
        foreach (self::sourceFiles() as $relative => $path) {
            $count = preg_match_all(self::MIXED_MAP, self::read($path));
            if ($count > 0 || isset(self::CEILINGS[$relative])) {
                $counts[$relative] = $count;
            }
        }

        $ceilings = self::CEILINGS;
        ksort($ceilings);
        self::assertSame($ceilings, $counts, 'A count above its ceiling needs a typed object. A count below it lowers the ceiling here.');
    }

    public function testTheCeilingCountsEachSpellingOfAMixedArray(): void
    {
        $annotations = '@var array<string, mixed> @var array<array-key,mixed> @var array<mixed> @var array<mixed, mixed> @var array<string, int> @var array<string>';

        self::assertSame(4, preg_match_all(self::MIXED_MAP, $annotations));
    }

    public static function isWriter(string $class): bool
    {
        foreach (self::WRITER_NAMESPACES as $namespace) {
            if (strncmp($class, $namespace, \strlen($namespace)) === 0) {
                return true;
            }
        }

        return \in_array($class, self::WRITERS, true);
    }

    /**
     * @param list<string> $expected each violation as "line: what the code does"
     *
     * @dataProvider sources
     */
    #[DataProvider('sources')]
    public function testTheScanAllowsAWrittenArrayOnlyInAWriterPosition(string $body, array $expected): void
    {
        $source = "<?php\nnamespace Lockrot\\Domain;\nfinal class Reader\n{\n    private array \$kept = [];\n".$body."\n}\n";

        self::assertSame($expected, WrittenArrayReads::inSource($source, [self::class, 'isWriter'], ['Lockrot\Domain\Holder' => ['Lockrot\Domain\Reader']]));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function sources(): iterable
    {
        $outside = 'uses a written array outside a return, an array item or a writer';
        $returns = 'returns a written array from a method that is not a writer';
        yield 'a key of the call itself' => ['    public function a($o) { return $o->toArray()[\'id\']; }', ['6: '.$outside]];
        yield 'a variable and array_values()' => ['    public function a($o) { $rows = array_values($o->findingRows()); return $rows[0]; }', ['6: '.$outside]];
        yield 'a foreach' => ["    public function a(\$o) {\n        foreach (\$o->toArray() as \$row) {\n            echo \$row['id'];\n        }\n    }", ['7: '.$outside]];
        yield 'the array argument of array_map()' => ['    public function a($o) { return array_map(static fn (array $row) => $row[\'id\'], $o->toArray()); }', ['6: '.$outside]];
        yield 'an argument of an own method' => ["    public function a(\$o) { return \$this->b(1, \$o->toArray()); }\n    private function b(int \$n, array \$row) { return \$row['id']; }", ['6: '.$outside]];
        yield 'a call of jsonSerialize()' => ['    public function a($o) { return \\count($o->jsonSerialize()); }', ['6: calls jsonSerialize(): only the encoder walks the tree']];
        yield 'a return of a method that is not a writer' => ["    public function a(\$o) { return self::b(\$o)['id']; }\n    private static function b(\$o) { return \$o->toArray() ?? []; }", ['7: '.$returns]];
        yield 'a property' => ["    public function a(\$o) { \$this->kept = \$o->toArray(); }\n    public function b() { return \$this->kept['id']; }", ['6: '.$outside]];
        yield 'a static call to a class that is not a writer' => ['    public function a($o) { return Other::count($o->toArray()); }', ['6: passes a written array to Lockrot\Domain\Other']];
        yield 'an array item of a new object of a class that is not a writer' => ['    public function a($o) { return new Other([\'row\' => $o->toArray()]); }', ['6: passes a written array to Lockrot\Domain\Other']];
        yield 'an element of a list in a variable' => ['    public function a($fs) { $rows = []; foreach ($fs as $f) { $rows[] = $f->toArray(); } return $rows[0][\'id\']; }', ['6: '.$outside]];
        yield 'a list mapped into a variable and array_push()' => ["    public function a(\$fs) { \$rows = array_map(static fn (\$f) => \$f->toArray(), \$fs); \$more = []; array_push(\$more, \$fs[0]->toArray()); return \$rows[0]['id'].\$more[0]['id']; }", ['6: '.$outside, '6: '.$outside]];
        yield 'a method of another object' => ['    public function a($o, $other) { return $other->count($o->toArray()); }', ['6: passes a written array to ->count()']];
        yield 'a call by the class name' => ["    public function a(\$o) { return Reader::b(\$o->toArray()); }\n    private static function b(array \$row) { return \$row['id']; }", ['6: '.$outside]];
        yield 'a new object of the class itself' => ["    private array \$row;\n    private function __construct(array \$row) { \$this->row = \$row; }\n    public static function of(\$o) { return new static(\$o->toArray()); }\n    public function id() { return \$this->row['id']; }", ['8: passes a written array to static']];
        yield 'a yield' => ["    public function a(\$o) { yield \$o->toArray(); }\n    public function b(\$o) { foreach (\$this->a(\$o) as \$row) { return \$row['id']; } }", ['6: '.$outside]];
        yield 'json_encode() and json_decode()' => ['    public function a($o) { return json_decode(json_encode($o->toArray()), true)[\'id\']; }', ['6: '.$outside]];
        yield 'a key compared in a foreach' => ['    public function a($o) { foreach ($o->toArray() as $k => $v) { if ($k === \'id\') { return $v; } } }', ['6: '.$outside]];
        yield 'an ArrayObject' => ['    public function a($o) { return (new \ArrayObject($o->toArray()))[\'id\']; }', ['6: passes a written array to ArrayObject']];
        yield 'an object cast' => ['    public function a($o) { return ((object) $o->toArray())->id; }', ['6: '.$outside]];
        yield 'a property of another object' => ['    public function a($o) { $b = new \stdClass(); $b->rows = $o->toArray(); return $b->rows[\'id\']; }', ['6: '.$outside]];
        yield 'a by-reference out parameter' => ["    public function a(\$o) { \$this->b(\$o, \$rows); return \$rows['id']; }\n    private function b(\$o, &\$out) { \$out = \$o->toArray(); }", ['7: '.$outside]];
        yield 'a closure in a variable' => ['    public function a($o) { $f = static fn (array $r) => $r[\'id\']; return $f($o->toArray()); }', ['6: '.$outside]];
        yield 'call_user_func()' => ["    public function a(\$o) { return call_user_func([\$this, 'b'], \$o->toArray()); }\n    private function b(array \$row) { return \$row['id']; }", ['6: '.$outside]];
        yield 'array_walk_recursive()' => ['    public function a($o) { $rows = $o->toArray(); array_walk_recursive($rows, static function ($v, $k) { if ($k === \'id\') { echo $v; } }); }', ['6: '.$outside]];
        yield 'value reads' => ['    public function a($o) { return [in_array(\'abandoned\', $o->toArray(), true), isset(array_flip($o->toArray())[\'x\']), array_diff_assoc($o->toArray(), []), array_intersect_assoc($o->toArray(), [])]; }', ['6: '.$outside, '6: '.$outside, '6: '.$outside, '6: '.$outside]];
        yield 'a callable array of a writer method' => ['    public function a($os) { return array_map([$os[0], \'toArray\'], $os)[0][\'x\']; }', ['6: '.$outside]];
        yield 'a list of method names is no callable' => ['    public function a($m) { return [in_array($m, [\'toArray\', \'toListArray\'], true), Other::f([\'name\' => \'x\', \'method\' => \'jsonSerialize\'])]; }', []];
        yield 'a writer method returns a list mapped by a callable array' => ['    public function toListArray($os) { return array_map([$this, \'toRowArray\'], $os); }', []];
        yield 'a writer method by the naming rule' => ['    public function a($e) { return $e->toDetailsArray()[\'lock\']; }', ['6: '.$outside]];
        yield 'a listed holder can get it' => ['    public function a($o) { return new Holder($o->toArray()); }', []];
        yield 'a writer can get it' => ['    public function a($o) { return \Lockrot\Output\X::f($o->toArray()); }', []];
        yield 'a writer method returns it in an array, a ternary and a mapped list' => ['    public function toArray($o, $fs) { return [\'a\' => $o->toArray(), \'b\' => $o ? $o->findingRows() : null, \'c\' => array_map(static fn ($f) => $f->toArray(), $fs), \'d\' => array_map(static function ($f) { return $f->toArray(); }, $fs)]; }', []];
        yield 'a writer method returns array_merge() and a union' => ['    public function toListArray($o) { return array_merge([\'x\' => 1], $o->toArray() ?? []) + [\'y\' => $o->toArray()]; }', []];
        yield 'an array that the code built is no written array' => ['    public function a($o) { $row = [\'id\' => $o->id()]; return $row[\'id\']; }', []];
    }

    /**
     * @param list<string> $expected each violation as "line: what the code does"
     *
     * @dataProvider writerSources
     */
    #[DataProvider('writerSources')]
    public function testAWriterHandsAWrittenArrayOnlyToAWriter(string $body, array $expected): void
    {
        $source = "<?php\nnamespace Lockrot\\Output;\nuse Lockrot\\Domain\\Other;\nfinal class W\n{\n".$body."\n}\n";

        self::assertSame($expected, WrittenArrayReads::inSource($source, [self::class, 'isWriter']));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function writerSources(): iterable
    {
        $other = 'passes a written array to Lockrot\Domain\Other';
        $returns = 'returns a written array from a method that is not a writer';
        yield 'the call, a variable and another object' => ["    public function a(\$o, \$other) { \$rows = \$o->toArray(); echo \$rows['id']; return [Other::f(\$rows), \$other->g(\$o->toArray()), \$this->h(\$rows), \\Lockrot\\Json\\JsonWriter::encode(\$rows)]; }", ['6: '.$other, '6: passes a written array to ->g()']];
        yield 'a key of the call' => ["    public function a(\$o) { return Other::f(\$o->toArray()['findings']); }", ['6: '.$other]];
        yield 'a key of a variable' => ["    public function a(\$o) { \$rows = \$o->toArray(); return Other::f(\$rows['findings']); }", ['6: '.$other]];
        yield 'a variable that holds a key' => ["    public function a(\$o) { \$rows = \$o->toArray()['findings']; return Other::f(\$rows); }", ['6: '.$other]];
        yield 'a public method that is not a writer' => ["    public function rows(\$o): array { return \$o->toArray(); }", ['6: '.$returns]];
        yield 'a public method that returns a key' => ["    public function rowOf(\$o): array { \$rows = \$o->findingRows(); return \$rows['x']; }", ['6: '.$returns]];
        yield 'a private method can return it' => ["    private function rows(\$o): array { return \$o->toArray(); }", []];
        yield 'a copy that array_values() makes' => ["    public function rows(\$o): array { return array_values(\$o->toArray()); }", ['6: '.$returns]];
        yield 'a copy of a key' => ["    public function a(\$o) { return Other::f(array_values(\$o->toArray()['findings'])); }", ['6: '.$other]];
        yield 'a destructured key' => ["    public function a(\$o) { ['findings' => \$f] = \$o->toArray(); return Other::f(\$f); }", ['6: '.$other]];
        yield 'an element of a list' => ["    public function rows(\$fs): array { \$rows = []; foreach (\$fs as \$x) { \$rows[] = \$x->toArray(); } return \$rows; }", ['6: '.$returns]];
        yield 'a union' => ["    public function a(\$o) { \$rows = []; \$rows += \$o->toArray(); return Other::f(\$rows); }", ['6: '.$other]];
        yield 'a yield' => ["    public function rows(\$fs): iterable { foreach (\$fs as \$f) { yield \$f->toArray(); } }", ['6: '.$returns]];
        yield 'a parameter of an arrow function with the same name' => ["    public function a(\$o, \$items) { \$row = \$o->toArray(); echo \$row['id']; return array_map(static fn (\$row) => Other::f(\$row), \$items); }", []];
    }

    /** @return array<string, string> each .php file under src/ by its path below src/, sorted */
    private static function sourceFiles(): array
    {
        $src = realpath(__DIR__.'/../../src');
        self::assertIsString($src);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[str_replace(\DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), \strlen($src) + 1))] = $file->getPathname();
            }
        }
        ksort($files);
        self::assertNotSame([], $files);

        return $files;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, $path);

        return $contents;
    }
}
