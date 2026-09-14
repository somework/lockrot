<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Signal\ConstraintOpenness;
use Lockrot\Signal\Rule\OldPromiseRule;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class OldPromiseRuleTest extends TestCase
{
    private function rule(string $target = '8.4'): OldPromiseRule
    {
        return new OldPromiseRule(new ConstraintOpenness(), PhpReleaseDates::load(), $target);
    }

    public function testOldReleaseWithOpenConstraint(): void
    {
        $signal = $this->rule()->evaluate(F::facts(F::package(['time' => '2015-11-16T16:30:51+00:00', 'php' => '>=5.3.0'])));
        self::assertNotNull($signal);
        self::assertSame(Signal::S5, $signal->id());
        self::assertSame(Signal::LEVEL_WARN, $signal->level());
        self::assertSame('released 2015-11-16, before PHP 8.4 GA (2024-11-21); php constraint ">=5.3.0" has no upper bound', $signal->summary());
        self::assertSame([
            'target_php' => '8.4',
            'ga_date' => '2024-11-21',
            'php_constraint' => '>=5.3.0',
            'released' => '2015-11-16T16:30:51+00:00',
        ], $signal->data());
    }

    public function testReleasedExactlyAtGaIsNotAPromise(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['time' => '2024-11-21T00:00:00+00:00', 'php' => '>=5.3.0']))));
    }

    public function testExplicitMajorIsNotAPromise(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['time' => '2019-01-01', 'php' => '^7.1 || ^8.0']))));
    }

    public function testReleasedAfterGaIsNull(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['time' => '2025-01-01', 'php' => '>=7.4']))));
    }

    public function testMissingDataIsNull(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['php' => '>=5.3']))));
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['time' => '2015-01-01']))));
        self::assertNull($this->rule('9.9')->evaluate(F::facts(F::package(['time' => '2015-01-01', 'php' => '>=5.3']))));
    }

    public function testRandomCompat99100IsAPromiseUntilAllowlisted(): void
    {
        self::assertNotNull($this->rule()->evaluate(F::facts(F::package(['time' => '2020-10-15', 'php' => '>= 7']))));
    }
}
