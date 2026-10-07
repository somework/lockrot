<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Security;

use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\Holder;
use Lockrot\Security\LinkIndex;
use PHPUnit\Framework\TestCase;

final class LinkIndexTest extends TestCase
{
    /** Composer compares package names in lower case. */
    public function testItFindsTheLinksOfANameInAnyCase(): void
    {
        $index = LinkIndex::of(
            LockFile::fromArray(['packages' => [['name' => 'c/c', 'version' => '1.0.0', 'require' => ['A/B' => '^1.0']]]]),
            ProjectConfig::empty()
        );

        $holders = $index->excluding('A/B', '2.0.0.0');
        self::assertCount(1, $holders);
        self::assertSame([Holder::PACKAGE, 'c/c', Holder::REQUIRE], [$holders[0]->source(), $holders[0]->package(), $holders[0]->link()]);
        self::assertSame([], $index->excluding('a/b', '1.5.0.0'));
    }
}
