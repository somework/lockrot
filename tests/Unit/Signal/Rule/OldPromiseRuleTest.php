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

    /** phpzip/phpzip 2.0.8: written for PHP 5 in 2015, and PHP 8.4 installs it because `>=5.3.0` lets it. */
    public function testOldReleaseWithOpenConstraint(): void
    {
        $signal = $this->rule()->evaluate(F::facts(F::package(['time' => '2015-11-16T16:30:51+00:00', 'php' => '>=5.3.0'])));
        self::assertNotNull($signal);
        self::assertSame(Signal::S5, $signal->id());
        self::assertSame(Signal::LEVEL_WARN, $signal->level());
        self::assertSame('released 2015-11-16 for PHP 5 (php ">=5.3.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested', $signal->summary());
        self::assertSame([
            'target_php' => '8.4',
            'target_major' => '8.0',
            'ga_date' => '2020-11-26',
            'php_constraint' => '>=5.3.0',
            'written_for_php' => 5,
            'released' => '2015-11-16T16:30:51+00:00',
        ], $signal->data());
    }

    /**
     * The line is the GA of the target's major, not of the target minor. geoip2/geoip2 2.13.0
     * (2022-08, `>=7.2`) was written with PHP 8.1 on every CI matrix; it is not an old promise about
     * PHP 8, and differs from a `^7.2 || ^8.0` of the same day in spelling alone. Under the minor
     * line, 156 of the 178 old-promise verdicts on the weekly watch were such releases.
     */
    public function testAReleaseOfTheTargetMajorsEraIsNotAPromiseAboutThatMajor(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['time' => '2022-08-05', 'php' => '>=7.2']))));
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['time' => '2020-11-26T00:00:00+00:00', 'php' => '>=5.3.0']))), 'released on the GA day: PHP 8 existed');
        self::assertNotNull($this->rule()->evaluate(F::facts(F::package(['time' => '2020-11-25T23:59:59+00:00', 'php' => '>=5.3.0']))), 'the day before, it did not');
    }

    public function testExplicitMajorIsNotAPromise(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['time' => '2019-01-01', 'php' => '^7.1 || ^8.0']))));
    }

    /** A constraint with no lower bound was written for no PHP in particular; the sentence says what it was written with. */
    public function testAConstraintWithNoLowerBoundIsSaidAsSuch(): void
    {
        $signal = $this->rule()->evaluate(F::facts(F::package(['time' => '2015-01-01T00:00:00+00:00', 'php' => '*'])));
        self::assertNotNull($signal);
        self::assertSame('released 2015-01-01 with php "*", before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested', $signal->summary());
        self::assertNull($signal->data()['written_for_php']);
    }

    /** The same rule against a target in another major: `>=7.4` from 2019 is an old promise about PHP 8.0 exactly as about 8.4. */
    public function testTheTargetsOwnMinorIsNamedAsWhatIsAdmitted(): void
    {
        $signal = $this->rule('8.0')->evaluate(F::facts(F::package(['time' => '2019-11-28', 'php' => '>=7.4'])));
        self::assertNotNull($signal);
        self::assertStringEndsWith('before PHP 8 existed (8.0 GA 2020-11-26); admits 8.0 untested', $signal->summary());
    }

    /** A target whose major has no GA date on record — PHP 9 today — is nothing to measure against. */
    public function testATargetMajorWithoutAGaDateIsNull(): void
    {
        self::assertNull($this->rule('9.0')->evaluate(F::facts(F::package(['time' => '2024-01-01', 'php' => '>=8.1']))));
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
