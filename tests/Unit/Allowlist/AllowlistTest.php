<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Allowlist;

use Lockrot\Allowlist\Allowlist;
use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class AllowlistTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable(F::NOW);
    }

    public function testWildcardAndExactPatterns(): void
    {
        $list = new Allowlist([new AllowlistEntry('psr/*', null, 'interfaces', null, 'builtin'), new AllowlistEntry('a/b', null, 'r', null, 'builtin')]);
        self::assertNotNull($list->match(F::package(['name' => 'psr/cache']), null, $this->now));
        self::assertNotNull($list->match(F::package(['name' => 'a/b']), null, $this->now));
        self::assertNull($list->match(F::package(['name' => 'a/bc']), null, $this->now));
        self::assertNull($list->match(F::package(['name' => 'psrx/cache']), null, $this->now));
    }

    public function testVersionPinnedEntry(): void
    {
        $list = new Allowlist([new AllowlistEntry('paragonie/random_compat', '9.99.100', 'empty', null, 'builtin')]);
        self::assertNotNull($list->match(F::package(['name' => 'paragonie/random_compat', 'version' => 'v9.99.100']), null, $this->now));
        self::assertNull($list->match(F::package(['name' => 'paragonie/random_compat', 'version' => 'v2.0.21']), null, $this->now));
    }

    public function testExpiredEntryIgnored(): void
    {
        $expired = new AllowlistEntry('a/b', null, 'temporary', new \DateTimeImmutable('2026-01-01'), 'project');
        $valid = new AllowlistEntry('a/c', null, 'temporary', new \DateTimeImmutable('2027-01-01'), 'project');
        $list = new Allowlist([$expired, $valid]);
        self::assertNull($list->match(F::package(['name' => 'a/b']), null, $this->now));
        self::assertNotNull($list->match(F::package(['name' => 'a/c']), null, $this->now));
        self::assertTrue($expired->isExpired($this->now));
    }

    public function testFinishedTypesFromLockOrMetadata(): void
    {
        $list = new Allowlist([]);
        $metapackageEntry = $list->match(F::package(['type' => 'metapackage']), null, $this->now);
        self::assertNotNull($metapackageEntry);
        self::assertSame('type:metapackage', $metapackageEntry->pattern());
        self::assertSame('package type "metapackage" only lists dependencies', $metapackageEntry->reason(), 'the reason names the type that matched');
        self::assertNotNull($list->match(F::package(['name' => 'symfony/twig-pack']), F::metadata([['v1.0.1', '2020-10-19']], false, null, 'symfony-pack'), $this->now));
        self::assertNull($list->match(F::package(), F::metadata([['1.0.0', '2020-01-01']]), $this->now));
    }

    public function testWildcardPrefixPattern(): void
    {
        $list = new Allowlist([new AllowlistEntry('*/foo', null, 'r', null, 'builtin')]);
        self::assertNotNull($list->match(F::package(['name' => 'bar/foo']), null, $this->now));
        self::assertNull($list->match(F::package(['name' => 'bar/foox']), null, $this->now));
    }

    public function testEmptyPatternMatchesNothing(): void
    {
        $list = new Allowlist([new AllowlistEntry('', null, 'r', null, 'builtin')]);
        self::assertNull($list->match(F::package(['name' => 'a/b']), null, $this->now));
        self::assertNull($list->match(F::package(['name' => 'vendor/pkg']), null, $this->now));
    }

    public function testMergeKeepsBothSources(): void
    {
        $a = new Allowlist([new AllowlistEntry('a/*', null, 'r', null, 'builtin')]);
        $b = new Allowlist([new AllowlistEntry('b/*', null, 'r', null, 'project')]);
        $merged = $a->merge($b);
        $bEntry = $merged->match(F::package(['name' => 'b/x']), null, $this->now);
        self::assertNotNull($bEntry);
        self::assertSame('project', $bEntry->source());
        $aEntry = $merged->match(F::package(['name' => 'a/x']), null, $this->now);
        self::assertNotNull($aEntry);
        self::assertSame('builtin', $aEntry->source());
    }

    /**
     * The built-in list carries the finished types and a project ignore list carries none, so the
     * merge has to keep them whichever of the two it is called on.
     */
    public function testMergeKeepsTheFinishedTypesOfBothSides(): void
    {
        $withTypes = new Allowlist([], ['metapackage']);
        $withoutTypes = new Allowlist([], []);
        $metapackage = F::package(['type' => 'metapackage']);

        self::assertNotNull($withTypes->merge($withoutTypes)->match($metapackage, null, $this->now));
        self::assertNotNull($withoutTypes->merge($withTypes)->match($metapackage, null, $this->now));
        self::assertNull($withoutTypes->merge($withoutTypes)->match($metapackage, null, $this->now));
    }

    /**
     * `expires: 2026-06-01` covers the whole of that day: the entry still holds at 23:59:59 and is
     * spent one second later.
     */
    public function testAnEntryIsNotYetExpiredAtItsExpiryInstant(): void
    {
        $at = new \DateTimeImmutable('2026-06-01T23:59:59+00:00');
        $entry = new AllowlistEntry('a/b', null, 'temporary', $at, 'project');

        self::assertFalse($entry->isExpired($at));
        self::assertTrue($entry->isExpired($at->modify('+1 second')));
    }
}
