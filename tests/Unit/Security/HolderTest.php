<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Security;

use Lockrot\Security\Holder;
use Lockrot\Verdict\GradedPackage;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * T4: under prefer-stable, Composer installs the tag that a dev version beside it in the pool shadows.
     *
     * @return iterable<string, array{list<string>, string}>
     */
    public static function versionsOfOneHold(): iterable
    {
        yield 'a dev version, then a tag' => [['7.x-dev', 'v7.14.0'], 'v7.14.0'];
        yield 'a tag, then a dev version' => [['v7.14.0', '7.x-dev'], 'v7.14.0'];
        yield 'two tags' => [['v7.14.0', 'v7.13.0'], 'v7.14.0'];
        yield 'two dev versions' => [['7.x-dev', 'dev-main'], '7.x-dev'];
    }

    /**
     * @dataProvider versionsOfOneHold
     *
     * @param list<string> $versions the hold's versions, in rule order
     */
    #[DataProvider('versionsOfOneHold')]
    public function testOfTwoHoldsThatDifferOnlyInTheVersionATagWinsOverADevVersionElseTheFirst(array $versions, string $kept): void
    {
        $holds = Holder::distinct(array_map(static fn (string $version): Holder => new Holder(Holder::PACKAGE, 'scheb/2fa-email', $version, Holder::REQUIRE, 'self.version', 'scheb/2fa-bundle'), $versions));

        self::assertSame([$kept], array_map(static fn (Holder $hold): ?string => $hold->version(), $holds));
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

    /** wallabag's twig/twig: a hold at v3.28.0 is not the finding of the locked v3.11.3, so it carries none of its grade and flags. */
    public function testAHoldTakesTheHoldersFindingOnlyAtTheLockedVersion(): void
    {
        $twig = new GradedPackage('critical', null, ['vulnerable'], null, null, 'v3.11.3');

        $holds = Holder::filled([
            new Holder(Holder::PACKAGE, 'twig/twig', 'v3.28.0', Holder::PLATFORM, '>=8.1.0', 'php'),
            new Holder(Holder::PACKAGE, 'twig/twig', 'v3.11.3', Holder::REQUIRE, '^5.4', 'symfony/polyfill-mbstring'),
        ], ['twig/twig' => $twig]);

        self::assertSame([null, $twig], [$holds[0]->finding(), $holds[1]->finding()]);
    }

    public function testTheTargetIsWrittenBesideTheLink(): void
    {
        self::assertSame(
            ['source' => 'package', 'package' => 'symfony/yaml', 'version' => 'v5.4.54', 'link' => 'conflict', 'target' => 'symfony/console', 'constraint' => '<5.3', 'holder' => null],
            (new Holder(Holder::PACKAGE, 'symfony/yaml', 'v5.4.54', Holder::CONFLICT, '<5.3', 'symfony/console'))->jsonSerialize()
        );
    }
}
