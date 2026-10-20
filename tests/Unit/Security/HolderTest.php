<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Security;

use Lockrot\Security\Holder;
use PHPUnit\Framework\TestCase;

final class HolderTest extends TestCase
{
    /** D3: holds are distinct, the root first, then by package, then by link, then by target. */
    public function testDistinctHoldsKeepTheFirstOfEachAndSortTheRootFirst(): void
    {
        $holds = Holder::distinct([
            new Holder(Holder::PACKAGE, 'symfony/yaml', 'v5.4.54', Holder::CONFLICT, '<5.3', 'symfony/console'),
            new Holder(Holder::PACKAGE, 'acme/handler', 'v2.1.5', Holder::REQUIRE, '^2.3'),
            new Holder(Holder::ROOT, 'acme/app', null, Holder::ROOT_REQUIRE, '^4.4.49', 'symfony/console'),
            new Holder(Holder::PACKAGE, 'symfony/yaml', 'v5.4.52', Holder::CONFLICT, '<5.3', 'symfony/console'),
            new Holder(Holder::PACKAGE, 'symfony/yaml', 'v5.4.54', Holder::REQUIRE, '^8.1', 'php'),
            new Holder(Holder::ROOT, 'acme/app', null, Holder::REQUIRE, '^5.3'),
        ]);

        self::assertSame(
            [
                ['root', 'require', null, null],
                ['root', 'root-require', null, 'symfony/console'],
                ['package', 'require', 'acme/handler', null],
                ['package', 'require', 'symfony/yaml', 'php'],
                ['package', 'conflict', 'symfony/yaml', 'symfony/console'],
            ],
            array_map(static fn (Holder $hold): array => [$hold->source(), $hold->link(), $hold->source() === Holder::ROOT ? null : $hold->package(), $hold->target()], $holds)
        );
        self::assertSame('v5.4.54', $holds[4]->version(), 'the first of two that differ only in the version');
    }

    public function testTheLinkOrdersBeforeTheTargetAndEveryFieldKeepsAnEntryApart(): void
    {
        $holds = Holder::distinct([
            new Holder(Holder::PACKAGE, 'acme/x', '1.0.0', Holder::REQUIRE, '^1.0', 'acme/z'),
            new Holder(Holder::PACKAGE, 'acme/x', '1.0.0', Holder::CONFLICT, '<2.0', 'acme/a'),
            new Holder(Holder::PACKAGE, 'acme/x', '1.0.0', Holder::REQUIRE, '^1.0', 'acme/y'),
            new Holder(Holder::PACKAGE, 'acme/x', '1.0.0', Holder::REQUIRE, '^2.0', 'acme/z'),
        ]);

        self::assertSame(
            [['require', 'acme/y', '^1.0'], ['require', 'acme/z', '^1.0'], ['require', 'acme/z', '^2.0'], ['conflict', 'acme/a', '<2.0']],
            array_map(static fn (Holder $hold): array => [$hold->link(), $hold->target(), $hold->constraint()], $holds)
        );
    }

    public function testTheTargetIsWrittenBesideTheLink(): void
    {
        self::assertSame(
            ['source' => 'package', 'package' => 'symfony/yaml', 'version' => 'v5.4.54', 'link' => 'conflict', 'target' => 'symfony/console', 'constraint' => '<5.3', 'holder' => null],
            (new Holder(Holder::PACKAGE, 'symfony/yaml', 'v5.4.54', Holder::CONFLICT, '<5.3', 'symfony/console'))->jsonSerialize()
        );
    }
}
