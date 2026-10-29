<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Lockrot\Solver\SolveRequest;
use PHPUnit\Framework\TestCase;

final class SolveRequestTest extends TestCase
{
    public function testTheAllowListNamesEachNameAsKeyAndValue(): void
    {
        self::assertSame([SolveRequest::BASELINE_NAME => SolveRequest::BASELINE_NAME], SolveRequest::baseline()->allow());
        self::assertSame(['acme/a' => 'acme/a', 'acme/b' => 'acme/b'], SolveRequest::update(['acme/a' => '^1.1', 'acme/b' => '^2.0'])->allow());
        self::assertSame(['acme/a' => 'acme/a'], SolveRequest::require('acme/a', ['acme/a' => '^2.0', 'php' => '>=8.1'], false)->allow());
    }

    /** A name with an empty constraint is on the allow list and gets no inline constraint. */
    public function testAnUpdateTakesNoInlineConstraintForANameThatItListsPlain(): void
    {
        $request = SolveRequest::update(['acme/plain' => '', 'acme/capped' => '^1.2']);

        self::assertSame(['acme/plain' => 'acme/plain', 'acme/capped' => 'acme/capped'], $request->allow());
        self::assertSame(['acme/capped' => '^1.2'], $request->inline());
    }

    /** `run.php_window.target_fix`: no inline constraint, and each hold keeps its target. */

    public function testAnUnlockAllowsEachNameWithNoConstraintAndMovesNoName(): void
    {
        $request = SolveRequest::unlock(['acme/a', 'acme/b']);

        self::assertSame(['acme/a' => 'acme/a', 'acme/b' => 'acme/b'], $request->allow());
        self::assertSame([[], [], SolveRequest::LISTED], [$request->inline(), $request->rootChanges(), $request->transitive()]);
        self::assertSame([], $request->moved());
        self::assertSame([], $request->withTransitive(SolveRequest::WITH_ALL_DEPENDENCIES)->moved());
        self::assertSame(['acme/a' => 'acme/a'], SolveRequest::update(['acme/a' => '^1.1'])->moved());
    }

    public function testACopyWithOtherRootChangesOrAnotherTransitiveModeLeavesTheRequestAsItWas(): void
    {
        $request = SolveRequest::update(['acme/a' => '^1.1']);

        $changed = $request->withRootChanges(['php' => '>=8.1']);
        $transitive = $request->withTransitive(SolveRequest::WITH_ALL_DEPENDENCIES);

        self::assertSame([[], SolveRequest::LISTED], [$request->rootChanges(), $request->transitive()]);
        self::assertSame([['php' => '>=8.1'], SolveRequest::LISTED], [$changed->rootChanges(), $changed->transitive()]);
        self::assertSame([[], SolveRequest::WITH_ALL_DEPENDENCIES], [$transitive->rootChanges(), $transitive->transitive()]);
    }

    /** `composer require --dev` moves the package's root requirement to `require-dev`. */
    public function testARequireNamesThePackageAsADevChangeOnlyForRequireDev(): void
    {
        self::assertSame(['acme/a' => 'acme/a'], SolveRequest::require('acme/a', ['acme/a' => '^2.0'], true)->devChanges());
        self::assertSame([], SolveRequest::require('acme/a', ['acme/a' => '^2.0'], false)->devChanges());
    }

    public function testACopyWithJoinedHoldersAllowsThemAndLeavesTheRequestAsItWas(): void
    {
        $request = SolveRequest::update(['acme/a' => '^1.1']);

        $joined = $request->withJoined(['acme/holder']);

        self::assertSame(['acme/a' => 'acme/a'], $request->allow());
        self::assertSame(['acme/a' => 'acme/a', 'acme/holder' => 'acme/holder'], $joined->allow());
    }
}
