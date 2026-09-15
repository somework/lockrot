<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Lockrot\Signal\Rule\AbandonedRule;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class AbandonedRuleTest extends TestCase
{
    public function testFlaggedWithReplacement(): void
    {
        $signal = (new AbandonedRule())->evaluate(F::facts(F::package(), F::metadata([['1.0.0', '2020-01-01']], true, 'other/pkg')));
        self::assertNotNull($signal);
        self::assertSame(Signal::S1, $signal->id());
        self::assertSame(Signal::LEVEL_HIGH, $signal->level());
        self::assertSame('marked abandoned by its repository, replacement: other/pkg', $signal->summary());
        self::assertSame('other/pkg', $signal->data()['replacement']);
    }

    public function testNotFlagged(): void
    {
        self::assertNull((new AbandonedRule())->evaluate(F::facts(F::package(), F::metadata([['1.0.0', '2020-01-01']]))));
    }

    public function testFallsBackToLockWhenNoMetadata(): void
    {
        $signal = (new AbandonedRule())->evaluate(F::facts(F::package(['abandonedInLock' => true])));
        self::assertNotNull($signal);
        self::assertStringContainsString('in composer.lock', $signal->summary());
        self::assertNull((new AbandonedRule())->evaluate(F::facts(F::package())));
    }

    public function testFallsBackToLockWithStringReplacement(): void
    {
        $signal = (new AbandonedRule())->evaluate(F::facts(F::package(['abandonedInLock' => 'other/pkg'])));
        self::assertNotNull($signal);
        self::assertSame(Signal::S1, $signal->id());
        self::assertSame('marked abandoned in composer.lock, replacement: other/pkg', $signal->summary());
        self::assertSame(['replacement' => 'other/pkg'], $signal->data());
    }
}
