<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Allowlist;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Exception\ConfigException;
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

    /** A path given explicitly is the file that is read; the bundled list is only the default. */
    public function testAnExplicitPathIsReadInsteadOfTheBundledList(): void
    {
        $path = $this->tempJson(['entries' => [['pattern' => 'only/here', 'reason' => 'named by the given file']]]);
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
     * Every entry needs both a pattern and a reason: a pattern with no reason would produce a
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
        yield 'a reason with no pattern' => [['reason' => 'interfaces only']];
        yield 'a pattern with no reason' => [['pattern' => 'psr/*']];
        yield 'a non-string pattern' => [['pattern' => 7, 'reason' => 'interfaces only']];
        yield 'a non-string reason' => [['pattern' => 'psr/*', 'reason' => ['interfaces only']]];
    }

    /** @param array<string, mixed> $document */
    private function tempJson(array $document): string
    {
        $path = sys_get_temp_dir().'/lockrot-builtin-allowlist-'.uniqid('', true).'.json';
        file_put_contents($path, (string) json_encode($document));
        $this->tempPaths[] = $path;

        return $path;
    }
}
