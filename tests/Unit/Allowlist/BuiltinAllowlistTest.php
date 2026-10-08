<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Allowlist;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Exception\ConfigException;
use Lockrot\Tests\Support\CorpusFloor;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BuiltinAllowlistTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            @unlink($path);
        }
        $this->tempPaths = [];
    }

    public function testKnownFinishedPackages(): void
    {
        $list = BuiltinAllowlist::load();
        $now = new \DateTimeImmutable(F::NOW);
        self::assertNotNull($list->match(F::package(['name' => 'ralouphie/getallheaders', 'version' => '3.0.3']), null, $now));
        self::assertNotNull($list->match(F::package(['name' => 'psr/cache', 'version' => '3.0.0']), null, $now));
        self::assertNotNull($list->match(F::package(['name' => 'symfony/polyfill-mbstring', 'version' => 'v1.31.0']), null, $now));
        self::assertNotNull($list->match(F::package(['name' => 'symfony/twig-pack', 'version' => 'v1.0.1']), null, $now));
        self::assertNotNull($list->match(F::package(['name' => 'paragonie/random_compat', 'version' => 'v9.99.100']), null, $now));
        self::assertNull($list->match(F::package(['name' => 'paragonie/random_compat', 'version' => 'v2.0.21']), null, $now));
        self::assertNull($list->match(F::package(['name' => 'phpzip/phpzip', 'version' => '2.0.8']), null, $now));
    }

    /** The reason ids are what a page words lockrot's reasons by: the built-in entries in file order, then the types. */
    public function testTheReasonIdsAreTheBuiltinEntriesThenTheTypes(): void
    {
        self::assertSame(
            ['php-fig-interfaces', 'php-fig-utilities', 'symfony-polyfills', 'symfony-extension-polyfills', 'symfony-packs', 'getallheaders-polyfill', 'random-compat-empty', 'type-metapackage', 'type-symfony-pack'],
            BuiltinAllowlist::reasonIds()
        );
    }

    /**
     * The first match wins, so the PHP polyfills match their own entry before the one for the
     * extension polyfills. Only the PHP polyfills are frozen once the target PHP is covered.
     *
     * @dataProvider polyfills
     */
    #[DataProvider('polyfills')]
    public function testThePolyfillEntrySplitsTheExtensionPolyfillsFromThePhpOnes(string $package, string $id, bool $frozen): void
    {
        $entry = BuiltinAllowlist::load()->match(F::package(['name' => $package, 'version' => 'v1.31.0']), null, new \DateTimeImmutable(F::NOW));

        self::assertNotNull($entry);
        self::assertSame($id, $entry->reasonId());
        self::assertSame(AllowlistEntry::BY_BUILTIN, $entry->by());
        self::assertSame(AllowlistEntry::REASON_BY_LOCKROT, $entry->reasonBy());
        self::assertSame($frozen, strpos($entry->reason(), 'frozen') !== false, $entry->reason());
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function polyfills(): iterable
    {
        yield 'a PHP polyfill' => ['symfony/polyfill-php80', 'symfony-polyfills', true];
        yield 'an extension polyfill' => ['symfony/polyfill-mbstring', 'symfony-extension-polyfills', false];
        yield 'an intl polyfill' => ['symfony/polyfill-intl-idn', 'symfony-extension-polyfills', false];
    }

    /**
     * Over the corpus floor, a reason that calls a package frozen matches only the PHP polyfills,
     * which a target PHP covers. The extension polyfills keep releasing, so a frozen reason is
     * false for them.
     */
    public function testOnTheCorpusFloorOnlyThePhpPolyfillsReadFrozen(): void
    {
        $list = BuiltinAllowlist::load();
        $now = new \DateTimeImmutable(F::NOW);
        $frozen = [];
        $extension = 0;
        foreach (CorpusFloor::reports() as $report) {
            foreach ($report['findings'] as $finding) {
                $entry = $list->match(F::package(['name' => $finding['package'], 'version' => $finding['version']]), null, $now);
                if ($entry !== null && strpos($entry->reason(), 'frozen') !== false) {
                    $frozen[$finding['package']] = true;
                }
                $extension += $entry !== null && $entry->reasonId() === 'symfony-extension-polyfills' ? 1 : 0;
            }
        }

        self::assertNotSame([], $frozen, 'the floor holds PHP polyfills');
        self::assertGreaterThan(0, $extension, 'the floor holds extension polyfills');
        foreach (array_keys($frozen) as $package) {
            self::assertStringStartsWith('symfony/polyfill-php', (string) $package);
        }
    }

    public function testABuiltinEntryWithoutAnIdIsRejected(): void
    {
        $path = $this->tempJson(['entries' => [['pattern' => 'psr/*', 'reason' => 'interfaces only']]]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Invalid built-in allowlist entry in '.$path);
        BuiltinAllowlist::load($path);
    }

    public function testAnExplicitPathIsReadInsteadOfTheBundledList(): void
    {
        $path = $this->tempJson(['entries' => [['id' => 'only-here', 'pattern' => 'only/here', 'reason' => 'named by the given file']]]);
        $now = new \DateTimeImmutable(F::NOW);

        $list = BuiltinAllowlist::load($path);

        $entry = $list->match(F::package(['name' => 'only/here']), null, $now);
        self::assertNotNull($entry);
        self::assertSame('named by the given file', $entry->reason());
        self::assertNull(
            $list->match(F::package(['name' => 'psr/cache', 'version' => '3.0.0']), null, $now),
            'the bundled list was not read as well'
        );
    }

    /**
     * Every entry needs both a pattern and a reason: a pattern with no reason produces a
     * finding nobody can explain, and a reason with no pattern matches nothing.
     *
     * @param array<string, mixed> $entry
     *
     * @dataProvider incompleteEntries
     */
    #[DataProvider('incompleteEntries')]
    public function testAnEntryMissingEitherHalfIsRejected(array $entry): void
    {
        $path = $this->tempJson(['entries' => [$entry]]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Invalid built-in allowlist entry in '.$path);
        BuiltinAllowlist::load($path);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function incompleteEntries(): iterable
    {
        yield 'a reason with no pattern' => [['id' => 'php-fig-interfaces', 'reason' => 'interfaces only']];
        yield 'a pattern with no reason' => [['id' => 'php-fig-interfaces', 'pattern' => 'psr/*']];
        yield 'a non-string pattern' => [['id' => 'php-fig-interfaces', 'pattern' => 7, 'reason' => 'interfaces only']];
        yield 'a non-string reason' => [['id' => 'php-fig-interfaces', 'pattern' => 'psr/*', 'reason' => ['interfaces only']]];
        yield 'a non-string id' => [['id' => 7, 'pattern' => 'psr/*', 'reason' => 'interfaces only']];
    }

    /** @param array<string, mixed> $document */
    private function tempJson(array $document): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lockrot-builtin-allowlist-');
        self::assertIsString($path);
        file_put_contents($path, (string) json_encode($document));
        $this->tempPaths[] = $path;

        return $path;
    }
}
