<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit;

use Lockrot\Composer\LockrotPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP classes are not lockrot's public interface (CONTRIBUTING.md, "Backward compatibility"),
 * and this is where the code says so: every class, interface and trait under src/ carries an
 * `@internal` tag, which PHPStan and IDEs report when another package uses it, except the one class
 * Composer loads by name.
 *
 * The scan reads tokens rather than reflecting classes, so it loads nothing from src/ and runs the
 * same on PHP 7.4 and 8.x, whose tokenizers spell a namespace differently.
 */
final class PublicApiTest extends TestCase
{
    private const MARK = '/^\s*(?:\/\*\*|\*)\s*@internal\b/m';

    private const RESERVED = 'Lockrot\\Extension\\';

    private const SEE = 'see CONTRIBUTING.md, "Backward compatibility"';

    public function testEveryClassInterfaceAndTraitUnderSrcIsMarkedInternal(): void
    {
        $plugin = self::pluginClass();
        $unmarked = [];
        foreach (self::sourceFiles() as $file) {
            foreach (self::declarations(self::read($file)) as $name => $marked) {
                if (!$marked && $name !== $plugin) {
                    $unmarked[] = $name;
                }
            }
        }
        sort($unmarked);

        self::assertSame([], $unmarked, 'Add an @internal tag to the docblock of each class above; '.self::SEE.'.');
    }

    /**
     * Keeps the check above from passing on a scanner that finds nothing: every file under src/
     * has to yield exactly the one declaration its PSR-4 path names. It also proves the namespace
     * is read right by whichever tokenizer the matrix row runs.
     */
    public function testEveryFileUnderSrcDeclaresTheOneClassItsPathNames(): void
    {
        $src = self::src();
        $files = self::sourceFiles();
        self::assertContains($src.\DIRECTORY_SEPARATOR.'Composer'.\DIRECTORY_SEPARATOR.'LockrotPlugin.php', $files);

        foreach ($files as $file) {
            $relative = substr($file, \strlen($src) + 1, -\strlen('.php'));
            $expected = 'Lockrot\\'.str_replace(['/', '\\'], '\\', $relative);

            self::assertSame([$expected], array_keys(self::declarations(self::read($file))), $file);
        }
    }

    /**
     * The exemption is not a list: it is whatever composer.json's extra.class names, which is the
     * reason for it. Tagging the plugin, or pointing extra.class somewhere else, fails here.
     */
    public function testTheComposerPluginIsTheOneClassLeftUnmarked(): void
    {
        self::assertSame(LockrotPlugin::class, self::pluginClass());

        $file = self::src().\DIRECTORY_SEPARATOR.'Composer'.\DIRECTORY_SEPARATOR.'LockrotPlugin.php';
        self::assertSame([LockrotPlugin::class => false], self::declarations(self::read($file)));
    }

    /**
     * The plugin's name is public; what it can be asked is Composer's plugin interfaces. A public
     * method none of them declares — the install-time handler Composer reaches through
     * getSubscribedEvents() — carries its own `@internal` tag.
     */
    public function testThePluginsPublicMethodsAreComposersOrMarkedInternal(): void
    {
        $plugin = new \ReflectionClass(LockrotPlugin::class);
        $unmarked = [];
        foreach ($plugin->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (self::declaredByAnInterfaceOf($plugin, $method->getName())) {
                continue;
            }
            $docComment = $method->getDocComment();
            if ($docComment === false || preg_match(self::MARK, $docComment) !== 1) {
                $unmarked[] = $method->getName();
            }
        }

        self::assertSame([], $unmarked, 'Add an @internal tag to each method above; '.self::SEE.'.');
    }

    public function testNothingIsDeclaredInTheReservedExtensionNamespace(): void
    {
        self::assertDirectoryDoesNotExist(self::src().\DIRECTORY_SEPARATOR.'Extension');

        $reserved = [];
        foreach (self::sourceFiles() as $file) {
            foreach (array_keys(self::declarations(self::read($file))) as $name) {
                if (strpos($name, self::RESERVED) === 0) {
                    $reserved[] = $name;
                }
            }
        }
        self::assertSame([], $reserved, self::RESERVED.' is reserved; '.self::SEE.'.');

        self::assertStringContainsString('`'.self::RESERVED.'`', self::read(__DIR__.'/../../CONTRIBUTING.md'));
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
            'global namespace' => ["<?php\n/** @internal */\nfinal class C {}\n", ['C' => true]],
            'the word in prose' => ["<?php\nnamespace A;\n/** The internal cache. */\nfinal class C {}\n", ['A\\C' => false]],
            'an inline tag' => ["<?php\nnamespace A;\n/** See {@internal x}. */\nfinal class C {}\n", ['A\\C' => false]],
            'a longer word' => ["<?php\nnamespace A;\n/** @internalized */\nfinal class C {}\n", ['A\\C' => false]],
            'a plain comment' => ["<?php\nnamespace A;\n/* @internal */\nfinal class C {}\n", ['A\\C' => false]],
            // PHP itself keeps the docblock across a comment or an attribute (ReflectionClass reads
            // it), so the scan does too. On 7.4 the attribute line is a comment.
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
     * @return array<string, bool> the fully qualified name of each class, interface and trait the
     *                             source declares, and whether its docblock carries an `@internal`
     *                             block tag
     */
    private static function declarations(string $source): array
    {
        $declarations = [];
        $namespace = '';
        $docComment = null;
        $previous = null;
        $tokens = token_get_all($source);
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                $docComment = null;
                $previous = $token;
                continue;
            }
            $id = $token[0];
            if ($id === \T_WHITESPACE || $id === \T_COMMENT) {
                continue;
            }
            if ($id === \T_DOC_COMMENT) {
                $docComment = $token[1];
                continue;
            }
            if (self::opensAttribute($id)) {
                $i = self::attributeEnd($tokens, $i);
                continue;
            }
            if ($id === \T_NAMESPACE) {
                $namespace = self::nameFrom($tokens, $i + 1);
            } elseif (self::declares($id) && $previous !== \T_DOUBLE_COLON && $previous !== \T_NEW) {
                $name = self::nameFrom($tokens, $i + 1);
                $declarations[$namespace === '' ? $name : $namespace.'\\'.$name] = $docComment !== null
                    && preg_match(self::MARK, $docComment) === 1;
            }
            if ($id !== \T_FINAL && $id !== \T_ABSTRACT) {
                $docComment = null;
            }
            $previous = $id;
        }

        return $declarations;
    }

    private static function declares(int $id): bool
    {
        return $id === \T_CLASS || $id === \T_INTERFACE || $id === \T_TRAIT;
    }

    /** PHP 8.0 made `#[` a token of its own; on 7.4 the line is a T_COMMENT and is skipped as one. */
    private static function opensAttribute(int $id): bool
    {
        return \defined('T_ATTRIBUTE') && $id === \constant('T_ATTRIBUTE');
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return int the index of the `]` that closes the attribute opened at $start
     */
    private static function attributeEnd(array $tokens, int $start): int
    {
        $depth = 1;
        $count = \count($tokens);
        for ($i = $start + 1; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === '[' || (\is_array($token) && self::opensAttribute($token[0]))) {
                ++$depth;
            } elseif ($token === ']' && --$depth === 0) {
                return $i;
            }
        }

        return $count;
    }

    /**
     * The name that starts at $start, up to `;`, `{` or the next whitespace after it: 7.4 spells a
     * qualified name as T_STRING and T_NS_SEPARATOR tokens, 8.x as one T_NAME_QUALIFIED.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private static function nameFrom(array $tokens, int $start): string
    {
        $name = '';
        $count = \count($tokens);
        for ($i = $start; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                break;
            }
            if ($token[0] === \T_WHITESPACE) {
                if ($name !== '') {
                    break;
                }
                continue;
            }
            $name .= $token[1];
        }

        return $name;
    }

    private static function pluginClass(): string
    {
        $composer = json_decode(self::read(__DIR__.'/../../composer.json'), true);
        self::assertIsArray($composer);
        self::assertIsArray($composer['extra'] ?? null);
        self::assertIsString($composer['extra']['class'] ?? null);

        return $composer['extra']['class'];
    }

    /** @param \ReflectionClass<LockrotPlugin> $class */
    private static function declaredByAnInterfaceOf(\ReflectionClass $class, string $method): bool
    {
        foreach ($class->getInterfaces() as $interface) {
            if ($interface->hasMethod($method)) {
                return true;
            }
        }

        return false;
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
