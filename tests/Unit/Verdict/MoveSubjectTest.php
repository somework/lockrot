<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

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
}
