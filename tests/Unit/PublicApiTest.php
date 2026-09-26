<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit;

use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP classes are not lockrot's public interface (CONTRIBUTING.md, "Backward compatibility"),
 * and this is where the code says so: every class, interface, trait and enum under src/ carries an
 * `@internal` tag. PHPStan reports a use of one from code outside the `Lockrot\` root namespace, and
 * IDEs flag it.
 *
 * The declarations are read by nikic/php-parser rather than by loading or reflecting src/, and the
 * parser is asked for the newest PHP it knows on every matrix row, so PHP 7.4 reads attributes,
 * enums and qualified names the way 8.x does.
 */
final class PublicApiTest extends TestCase
{
    private const MARK = '/^\s*(?:\/\*\*|\*)\s*@internal\b/m';

    private const RESERVED = 'Lockrot\\Extension\\';

    private const SEE = 'see CONTRIBUTING.md, "Backward compatibility"';

    public function testEveryClassInterfaceTraitAndEnumUnderSrcIsMarkedInternal(): void
    {
        $unmarked = [];
        foreach (self::sourceFiles() as $file) {
            foreach (self::declarations(self::read($file)) as $name => $marked) {
                if (!$marked) {
                    $unmarked[] = $name;
                }
            }
        }
        sort($unmarked);

        self::assertSame([], $unmarked, 'Add an @internal tag to the docblock of each class above; '.self::SEE.'.');
    }

    /**
     * Keeps the check above from passing on a scan that finds nothing: every file under src/ has to
     * yield exactly the one declaration its PSR-4 path names.
     */
    public function testEveryFileUnderSrcDeclaresTheOneClassItsPathNames(): void
    {
        $src = self::src();
        $files = self::sourceFiles();
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $relative = substr($file, \strlen($src) + 1, -\strlen('.php'));
            $expected = 'Lockrot\\'.str_replace(['/', '\\'], '\\', $relative);

            self::assertSame([$expected], array_keys(self::declarations(self::read($file))), $file);
        }
    }

    public function testNothingIsDeclaredInTheReservedExtensionNamespace(): void
    {
        self::assertSame([], self::reservedDirectories(self::src()), self::RESERVED.' is reserved; '.self::SEE.'.');

        $reserved = [];
        foreach (self::sourceFiles() as $file) {
            foreach (array_keys(self::declarations(self::read($file))) as $name) {
                if (self::isReserved($name)) {
                    $reserved[] = $name;
                }
            }
        }
        self::assertSame([], $reserved, self::RESERVED.' is reserved; '.self::SEE.'.');

        self::assertStringContainsString('`'.self::RESERVED.'`', self::read(__DIR__.'/../../CONTRIBUTING.md'));
    }

    /**
     * PHP matches a namespace without regard to case, so `namespace Lockrot\extension;` declares
     * into the reserved namespace as surely as the spelling CONTRIBUTING.md uses.
     *
     * @dataProvider names
     */
    #[DataProvider('names')]
    public function testTheReservedNamespaceIsMatchedWithoutRegardToCase(string $name, bool $reserved): void
    {
        self::assertSame($reserved, self::isReserved($name));
    }

    /** @return array<string, array{string, bool}> */
    public static function names(): array
    {
        return [
            'as documented' => ['Lockrot\\Extension\\Hook', true],
            'lower case' => ['Lockrot\\extension\\Hook', true],
            'upper case' => ['LOCKROT\\EXTENSION\\Hook', true],
            'a namespace below it' => ['Lockrot\\Extension\\Output\\Hook', true],
            'a class named Extension' => ['Lockrot\\Extension', false],
            'a longer word' => ['Lockrot\\Extensions\\Hook', false],
            'deeper in lockrot' => ['Lockrot\\Composer\\Extension\\Hook', false],
            'another vendor' => ['Acme\\Lockrot\\Extension\\Hook', false],
        ];
    }

    /**
     * On a case-sensitive filesystem src/extension/ and src/Extension/ are different directories,
     * and PSR-4 maps either one onto the reserved namespace.
     */
    public function testAReservedDirectoryIsFoundInAnyCase(): void
    {
        $dir = sys_get_temp_dir().'/lockrot-public-api-'.uniqid('', true);
        $children = ['extension', 'Extensions', 'Composer'];
        foreach ($children as $child) {
            self::assertTrue(mkdir($dir.'/'.$child, 0777, true));
        }
        self::assertNotFalse(file_put_contents($dir.'/Extension.php', "<?php\n"));

        try {
            self::assertSame(['extension'], self::reservedDirectories($dir));
        } finally {
            unlink($dir.'/Extension.php');
            foreach ($children as $child) {
                rmdir($dir.'/'.$child);
            }
            rmdir($dir);
        }
    }

    /**
     * @param array<string, bool> $expected
     *
     * @dataProvider sources
     */
    #[DataProvider('sources')]
    public function testTheScanReadsDeclarationsAndTheirMark(string $source, array $expected): void
    {
        self::assertSame($expected, self::declarations($source));
    }

    /** @return array<string, array{string, array<string, bool>}> */
    public static function sources(): array
    {
        return [
            'no docblock' => ["<?php\nnamespace A\\B;\n\nfinal class C {}\n", ['A\\B\\C' => false]],
            'one-line tag' => ["<?php\nnamespace A\\B;\n\n/** @internal */\nfinal class C {}\n", ['A\\B\\C' => true]],
            'tag after prose' => [
                "<?php\nnamespace A;\n\n/**\n * Does a thing.\n *\n * @internal\n */\nfinal class C {}\n",
                ['A\\C' => true],
            ],
            'abstract class' => ["<?php\nnamespace A;\n/** @internal */\nabstract class C {}\n", ['A\\C' => true]],
            'plain class' => ["<?php\nnamespace A;\n/** @internal */\nclass C {}\n", ['A\\C' => true]],
            'interface' => ["<?php\nnamespace A;\n\ninterface I {}\n", ['A\\I' => false]],
            'trait' => ["<?php\nnamespace A;\n/** @internal */\ntrait T {}\n", ['A\\T' => true]],
            'enum' => ["<?php\nnamespace A;\n/** @internal */\nenum E: string { case X = 'x'; }\n", ['A\\E' => true]],
            'global namespace' => ["<?php\n/** @internal */\nfinal class C {}\n", ['C' => true]],
            'the word in prose' => ["<?php\nnamespace A;\n/** The internal cache. */\nfinal class C {}\n", ['A\\C' => false]],
            'an inline tag' => ["<?php\nnamespace A;\n/** See {@internal x}. */\nfinal class C {}\n", ['A\\C' => false]],
            'a longer word' => ["<?php\nnamespace A;\n/** @internalized */\nfinal class C {}\n", ['A\\C' => false]],
            'a plain comment' => ["<?php\nnamespace A;\n/* @internal */\nfinal class C {}\n", ['A\\C' => false]],
            // PHP itself keeps the docblock across a comment or an attribute (ReflectionClass reads
            // it), so the scan does too.
            'a comment in between' => ["<?php\nnamespace A;\n/** @internal */\n// why\nfinal class C {}\n", ['A\\C' => true]],
            'an attribute in between' => [
                "<?php\nnamespace A;\n/** @internal */\n#[\\Attribute(\\Attribute::TARGET_CLASS)]\nfinal class C {}\n",
                ['A\\C' => true],
            ],
            'the docblock of a function before it' => [
                "<?php\nnamespace A;\n/** @internal */\nfunction f() {}\nfinal class C {}\n",
                ['A\\C' => false],
            ],
            'the docblock of a use statement before it' => [
                "<?php\nnamespace A;\n/** @internal */\nuse B\\D;\nfinal class C {}\n",
                ['A\\C' => false],
            ],
            '::class and an anonymous class are not declarations' => [
                "<?php\nnamespace A;\n/** @internal */\nfinal class C\n{\n    public function f(): object\n    {\n"
                ."        \$name = self::class;\n        return new class {};\n    }\n}\n",
                ['A\\C' => true],
            ],
            'a call by a namespace-relative name is not a namespace' => [
                "<?php\nnamespace A;\nnamespace\\f();\n/** @internal */\nfinal class C {}\n",
                ['A\\C' => true],
            ],
            'methods named by reserved words are not declarations' => [
                "<?php\nnamespace A;\n/** @internal */\nfinal class C\n{\n"
                ."    public function class(): void {}\n    public function trait(): void {}\n"
                ."    public function interface(): void {}\n    public function enum(): void {}\n}\n",
                ['A\\C' => true],
            ],
            'two classes in one file' => [
                "<?php\nnamespace A;\n/** @internal */\nfinal class C {}\n\nfinal class D {}\n",
                ['A\\C' => true, 'A\\D' => false],
            ],
            'two namespaces in one file' => [
                "<?php\nnamespace A {\n/** @internal */\nfinal class C {}\n}\nnamespace B\\E {\nfinal class C {}\n}\n",
                ['A\\C' => true, 'B\\E\\C' => false],
            ],
        ];
    }

    /**
     * @return array<string, bool> the fully qualified name of each class, interface, trait and enum
     *                             the source declares, anonymous classes aside, and whether its
     *                             docblock carries an `@internal` block tag
     */
    private static function declarations(string $source): array
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        self::assertNotNull($statements);
        $statements = (new NodeTraverser(new NameResolver()))->traverse($statements);

        $declarations = [];
        foreach ((new NodeFinder())->findInstanceOf($statements, ClassLike::class) as $declaration) {
            if ($declaration->namespacedName === null) {
                continue;
            }
            $docComment = $declaration->getDocComment();
            $declarations[$declaration->namespacedName->toString()] = $docComment !== null
                && preg_match(self::MARK, $docComment->getText()) === 1;
        }

        return $declarations;
    }

    private static function isReserved(string $name): bool
    {
        return strncasecmp($name, self::RESERVED, \strlen(self::RESERVED)) === 0;
    }

    /** @return list<string> each directory right under $dir that PSR-4 maps onto the reserved namespace */
    private static function reservedDirectories(string $dir): array
    {
        $entries = scandir($dir);
        self::assertIsArray($entries, $dir);

        $reserved = [];
        foreach ($entries as $entry) {
            if (is_dir($dir.\DIRECTORY_SEPARATOR.$entry) && self::isReserved('Lockrot\\'.$entry.'\\')) {
                $reserved[] = $entry;
            }
        }

        return $reserved;
    }

    /** @return list<string> every .php file under src/, sorted so a failure lists them stably */
    private static function sourceFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::src(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private static function src(): string
    {
        $src = realpath(__DIR__.'/../../src');
        self::assertIsString($src);

        return $src;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, $path);

        return $contents;
    }
}
