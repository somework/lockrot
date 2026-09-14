<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Lockrot\Signal\Rule\PinnedRule;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class PinnedRuleTest extends TestCase
{
    public function testBranchSnapshot(): void
    {
        $signal = (new PinnedRule())->evaluate(F::facts(F::package(['version' => 'dev-master'])));
        self::assertNotNull($signal);
        self::assertSame(Signal::S6, $signal->id());
        self::assertSame(Signal::LEVEL_WARN, $signal->level());
        self::assertSame('pinned to branch snapshot dev-master', $signal->summary());
        self::assertSame(['version' => 'dev-master'], $signal->data());
    }

    public function testNoTaggedRelease(): void
    {
        $signal = (new PinnedRule())->evaluate(F::facts(F::package(['version' => '1.0.0']), F::metadata([['dev-master', '2015-01-01']])));
        self::assertNotNull($signal);
        self::assertSame('no tagged release on Packagist', $signal->summary());
        self::assertSame(['version' => '1.0.0'], $signal->data());
    }

    public function testTaggedPackageIsNull(): void
    {
        self::assertNull((new PinnedRule())->evaluate(F::facts(F::package(), F::metadata([['1.0.0', '2020-01-01']]))));
        self::assertNull((new PinnedRule())->evaluate(F::facts(F::package())));
    }
}
