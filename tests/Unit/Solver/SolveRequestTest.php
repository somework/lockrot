<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Lockrot\Solver\SolveRequest;
use PHPUnit\Framework\TestCase;

final class SolveRequestTest extends TestCase
{
    /** Composer before 2.6 reads the allow list's keys, and later versions its values. */
    public function testTheAllowListNamesEachNameAsKeyAndValue(): void
    {
        self::assertSame([SolveRequest::BASELINE_NAME => SolveRequest::BASELINE_NAME], SolveRequest::baseline()->allow());
        self::assertSame(['acme/a' => 'acme/a', 'acme/b' => 'acme/b'], SolveRequest::update(['acme/a' => '^1.1', 'acme/b' => '^2.0'])->allow());
        self::assertSame(['acme/a' => 'acme/a'], SolveRequest::require('acme/a', ['acme/a' => '^2.0', 'php' => '>=8.1'])->allow());
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
}
