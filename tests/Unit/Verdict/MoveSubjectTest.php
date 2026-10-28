<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Lock\ProjectConfig;
use Lockrot\Verdict\MoveSubject;
use PHPUnit\Framework\TestCase;

final class MoveSubjectTest extends TestCase
{
    public function testTheRequirerIsThePackageBeforeThisOneOnAChainOfThreeOrMore(): void
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', false, [], '8.4', null);

        self::assertSame(
            [null, null, 'acme/mid', 'acme/app-lib'],
            [$subject->requirer(), $subject->withChain(['acme/app-lib', 'acme/lib'])->requirer(), $subject->withChain(['acme/app-lib', 'acme/mid', 'acme/lib'])->requirer(), $subject->withChain(['acme/app-lib', 'acme/lib'])->chainHead()],
        );
    }

    public function testRequireWinsOverRequireDevWhenBothSectionsListThePackage(): void
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', false, [], '8.4', null);

        self::assertSame(
            [[false, false], [true, true], [true, false]],
            [
                [$subject->isListed(), $subject->isListedInRequireDevOnly()],
                [$subject->withNamedIn(['require-dev'])->isListed(), $subject->withNamedIn(['require-dev'])->isListedInRequireDevOnly()],
                [$subject->withNamedIn(['require-dev', 'require'])->isListed(), $subject->withNamedIn(['require-dev', 'require'])->isListedInRequireDevOnly()],
            ],
        );
    }

    public function testTheCopiesLeaveTheSubjectAsItWas(): void
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', false, [], '8.4', null);

        $subject->withNamedIn(['require-dev']);
        $subject->withChain(['acme/app-lib', 'acme/mid', 'acme/lib']);

        self::assertSame([false, null, null], [$subject->isListed(), $subject->requirer(), $subject->chainHead()]);
    }

    public function testTheLiteralKeysChooseTheSectionOptionAndTheArgumentOfARequire(): void
    {
        $subject = new MoveSubject('vendor/pkg', '1.2.0', true, [], '8.4', null);
        $require = [ProjectConfig::REQUIRE => 'vendor/pkg'];
        $dev = [ProjectConfig::REQUIRE_DEV => 'Vendor/Pkg'];

        self::assertSame(
            [
                [[], 'vendor/pkg', true, false],
                [['--dev'], 'vendor/pkg', true, true],
                [['--no-interaction'], 'Vendor/Pkg', true, false],
                [[], 'vendor/pkg', true, false],
            ],
            array_map(static fn (MoveSubject $listed): array => [$listed->requireOptions(), $listed->requireArgument(), $listed->isListed(), $listed->isListedInRequireDevOnly()], [
                $subject->withKeys($require, []),
                $subject->withKeys($dev, []),
                $subject->withKeys($require + $dev, []),
                $subject->withKeys([], []),
            ]),
        );
    }

    public function testALiteralKeyWinsOverTheNamedInSectionsOfAProvidedName(): void
    {
        $provided = (new MoveSubject('monolog/monolog', '1.2.0', false, ['a/b'], '8.4', null))->withNamedIn([ProjectConfig::REQUIRE]);

        self::assertSame(
            [[[], false], [['--dev'], true]],
            [
                [$provided->requireOptions(), $provided->isListedInRequireDevOnly()],
                [$provided->withKeys([ProjectConfig::REQUIRE_DEV => 'monolog/monolog'], [])->requireOptions(), $provided->withKeys([ProjectConfig::REQUIRE_DEV => 'monolog/monolog'], [])->isListedInRequireDevOnly()],
            ],
        );
    }

    public function testThePhpRaiseTakesNoInteractionAndTheRequireDevSpellingWhenRequireDevListsPhp(): void
    {
        $subject = new MoveSubject('vendor/pkg', '1.2.0', true, [], '8.4', null);

        self::assertSame(
            [[[], 'php'], [[], 'php'], [['--no-interaction'], 'PHP'], [['--no-interaction'], 'php']],
            array_map(static fn (array $keys): array => [$subject->withKeys([], $keys)->phpRaiseOptions(), $subject->withKeys([], $keys)->phpRaiseArgument()], [
                [],
                [ProjectConfig::REQUIRE => 'PHP'],
                [ProjectConfig::REQUIRE => 'php', ProjectConfig::REQUIRE_DEV => 'PHP'],
                [ProjectConfig::REQUIRE_DEV => 'php'],
            ]),
        );
    }
}
