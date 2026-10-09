<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit;

use Lockrot\Tests\Support\WrittenArrayReads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Arrays exist only at the JSON boundary: a domain object computes each value, and only a writer
 * reads an array that lockrot wrote ({@see WrittenArrayReads}). The count of `array<string, mixed>`
 * in each file of src/ stays at its ceiling. Lower a ceiling when the count drops, and never raise
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
        'Analyzer/Libyears.php' => 1,
        'Analyzer/Report.php' => 2,
        'Analyzer/Report2Root.php' => 1,
        'Analyzer/RunNote.php' => 4,
        'Analyzer/RunSettings.php' => 3,
        'Baseline/Baseline.php' => 4,
        'Baseline/BaselineComparison.php' => 1,
        'Baseline/BaselineFile.php' => 1,
        'Baseline/BaselineSchema.php' => 3,
        'Clock.php' => 1,
        'Composer/AnalyzerBootstrap.php' => 1,
        'Composer/LockrotCommand.php' => 1,
        'Config/ConfigSchema.php' => 1,
        'Config/LockrotConfig.php' => 18,
        'Data/Abandoned/ComposerAbandonedPolicyReader.php' => 1,
        'Data/Forge/ForgeApi.php' => 1,
        'Data/Forge/Tokens.php' => 2,
        'Data/Http/HttpResult.php' => 4,
        'Explain/Explanation.php' => 3,
        'Html/ReportDocument.php' => 2,
        'Json/JsonReader.php' => 2,
        'Legacy/SignalData013.php' => 1,
        'Lock/LockFile.php' => 1,
        'Lock/ProjectConfig.php' => 6,
        'Output/GitlabFormatter.php' => 2,
        'Output/HtmlFormatter.php' => 1,
        'Output/SarifFormatter.php' => 4,
        'Signal/Rule/AdvisoryRule.php' => 3,
        'Signal/Rule/PinnedRule.php' => 1,
        'Signal/Signal.php' => 3,
        'Signal/Thresholds.php' => 1,
        'Verdict/Finding.php' => 8,
        'Verdict/FindingDetails.php' => 9,
        'Verdict/FlagSentence.php' => 4,
        'Verdict/ScoreModel.php' => 6,
    ];

    /** Any spacing of `array<string, mixed>`. */
    private const MIXED_MAP = '/array<\\s*string\\s*,\\s*mixed\\s*>/';

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

    public function testEachFileKeepsItsCountOfStringKeyedMixedArraysAtItsCeiling(): void
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
    public function testTheScanFollowsAWrittenArrayToEachRead(string $body, array $expected): void
    {
        $source = "<?php\nnamespace Lockrot\\Domain;\nfinal class Reader\n{\n    private array \$kept = [];\n".$body."\n}\n";

        self::assertSame($expected, WrittenArrayReads::inSource($source, [self::class, 'isWriter'], ['Lockrot\Domain\Holder' => ['Lockrot\Domain\Reader']]));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function sources(): iterable
    {
        yield 'a key of the call itself' => ['    public function a($o) { return $o->toArray()[\'id\']; }', ['6: reads a key of a written array']];
        yield 'a key through a variable and array_values()' => ['    public function a($o) { $rows = array_values($o->findingRows()); return $rows[0]; }', ['6: reads a key of a written array']];
        yield 'a key of each row of a foreach' => ["    public function a(\$o) {\n        foreach (\$o->toArray() as \$row) {\n            echo \$row['id'];\n        }\n    }", ['8: reads a key of a written array']];
        yield 'a key in a callback' => ['    public function a($o) { return array_map(static fn (array $row) => $row[\'id\'], $o->toArray()); }', ['6: reads a key of a written array']];
        yield 'a key in an own method that gets the array' => ["    public function a(\$o) { return \$this->b(1, \$o->toArray()); }\n    private function b(int \$n, array \$row) { return \$row['id']; }", ['7: reads a key of a written array']];
        yield 'a call of jsonSerialize()' => ['    public function a($o) { return \\count($o->jsonSerialize()); }', ['6: calls jsonSerialize(): only the encoder walks the tree']];
        yield 'a key of an own method that returns the array' => ["    public function a(\$o) { return self::b(\$o)['id']; }\n    private static function b(\$o) { return \$o->toArray() ?? []; }", ['6: reads a key of a written array']];
        yield 'a key of a property that holds the array' => ["    public function a(\$o) { \$this->kept = \$o->toArray(); }\n    public function b() { return \$this->kept['id']; }", ['7: reads a key of a written array']];
        yield 'a key function and a keyed destructuring' => ['    public function a($o) { $t = $o->toArray(); [\'id\' => $id] = $t; return array_column($t, \'id\'); }', ['6: destructures a written array', '6: passes a written array to array_column()']];
        yield 'a static call to a class that is not a writer' => ['    public function a($o) { return Other::count($o->toArray()); }', ['6: passes a written array to Lockrot\Domain\Other']];
        yield 'a new object of a class that is not a writer' => ['    public function a($o) { return new Other([\'row\' => $o->toArray()]); }', ['6: passes a written array to Lockrot\Domain\Other']];
        yield 'a written array as an element of a list' => ['    public function a($fs) { $rows = []; foreach ($fs as $f) { $rows[] = $f->toArray(); } return $rows[0][\'id\']; }', ['6: reads a key of a written array']];
        yield 'a list mapped from the objects and array_push()' => ["    public function a(\$fs) { \$rows = array_map(static fn (\$f) => \$f->toArray(), \$fs); \$more = []; array_push(\$more, \$fs[0]->toArray()); return \$rows[0]['id'].\$more[0]['id']; }", ['6: reads a key of a written array', '6: reads a key of a written array']];
        yield 'a keyed destructuring in a foreach' => ['    public function a($o) { foreach ($o->findingRows() as [\'package\' => $name]) { echo $name; } }', ['6: destructures a written array']];
        yield 'an instance call of another object' => ['    public function a($o, $other) { return $other->count($o->toArray()); }', ['6: passes a written array to ->count()']];
        yield 'a call by the class name is an own call' => ["    public function a(\$o) { return Reader::b(\$o->toArray()); }\n    private static function b(array \$row) { return \$row['id']; }", ['7: reads a key of a written array']];
        yield 'a callable array of an own method' => ["    public function a(\$o) { return array_map([self::class, 'b'], \$o->findingRows()); }\n    private static function b(array \$row) { return \$row['package']; }", ['7: reads a key of a written array']];
        yield 'a callable array of the object and usort()' => ["    public function a(\$o) { \$rows = \$o->toArray(); usort(\$rows, [\$this, 'b']); return \$rows; }\n    private function b(array \$x, array \$y) { return \$x['score'] <=> \$y['score']; }", ['7: reads a key of a written array', '7: reads a key of a written array']];
        yield 'a callable array of another class' => ['    public function a($o) { return array_map([Other::class, \'id\'], $o->toArray()); }', ['6: passes a written array to Lockrot\Domain\Other']];
        yield 'a new object of the class itself' => ["    private array \$row;\n    private function __construct(array \$row) { \$this->row = \$row; }\n    public static function of(\$o) { return new static(\$o->toArray()); }\n    public function id() { return \$this->row['id']; }", ['9: reads a key of a written array']];
        yield 'a generator yields a written array' => ["    public function a(\$o) { yield \$o->toArray(); }\n    public function b(\$o) { foreach (\$this->a(\$o) as \$row) { return \$row['id']; } }", ['7: reads a key of a written array']];
        yield 'a listed holder can get it' => ['    public function a($o) { return new Holder($o->toArray()); }', []];
        yield 'a writer can get it' => ['    public function a($o) { return \Lockrot\Output\X::f($o->toArray()); }', []];
        yield 'a key written into the array is no read' => ['    public function a($o) { $row = $o->toArray(); $row[\'extra\'] = 1; unset($row[\'id\']); return $row; }', []];
        yield 'the encoded string is no array' => ['    public function a($o) { $json = json_encode($o->toArray()); return $json[0].Other::save($json); }', []];
        yield 'an array that the code built is no written array' => ['    public function a($o) { $row = [\'id\' => $o->id()]; return $row[\'id\']; }', []];
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
