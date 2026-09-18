<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Lockrot\Clock;
use Lockrot\Signal\Rule\LeftBehindRule;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class LeftBehindRuleTest extends TestCase
{
    private function rule(): LeftBehindRule
    {
        return new LeftBehindRule(Clock::fixed(F::NOW), new Thresholds());
    }

    public function testHighWhenTheInstalledMajorStoppedFiveYearsAgoAndANewerMajorReleased(): void
    {
        $meta = F::metadata([['3.4.1', '2026-06-01'], ['3.0.0', '2024-01-10'], ['2.2.0', '2021-05-05'], ['1.9.2', '2019-03-02'], ['1.0.0', '2015-01-01']]);

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => 'v1.8.0']), $meta));

        self::assertNotNull($signal);
        self::assertSame(Signal::S8, $signal->id());
        self::assertSame(Signal::LEVEL_HIGH, $signal->level());
        self::assertSame('branch 1.x last released 2019-03-02 (7.5 years ago); upstream moved on to 3.4.1 (2026-06-01)', $signal->summary());
        self::assertSame([
            'branch' => '1.x',
            'branch_last_release' => '2019-03-02T00:00:00+00:00',
            'branch_last_version' => '1.9.2',
            'years' => 7.5,
            'newest_version' => '3.4.1',
            'newest_release' => '2026-06-01T00:00:00+00:00',
        ], $signal->data());
    }

    public function testWarnBetweenThreeAndFiveYears(): void
    {
        $meta = F::metadata([['2.0.0', '2024-01-10'], ['1.9.2', '2022-08-01']]);

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta));

        self::assertNotNull($signal);
        self::assertSame(Signal::LEVEL_WARN, $signal->level());
    }

    public function testExactlyAtTheWarnThresholdIsWarn(): void
    {
        $meta = F::metadata([['2.0.0', '2024-01-10'], ['1.9.2', '2023-09-14T00:00:00+00:00']]);

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta));

        self::assertNotNull($signal);
        self::assertSame(Signal::LEVEL_WARN, $signal->level());
    }

    public function testJustPastTheHighThresholdIsHigh(): void
    {
        // Five calendar years back is 1826 days, a hair under 5 × 365.25; one more day crosses it.
        $meta = F::metadata([['2.0.0', '2024-01-10'], ['1.9.2', '2021-09-13T00:00:00+00:00']]);

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta));

        self::assertNotNull($signal);
        self::assertSame(Signal::LEVEL_HIGH, $signal->level());
    }

    public function testABranchStillReleasingIsNull(): void
    {
        $meta = F::metadata([['2.0.0', '2024-01-10'], ['1.9.9', '2025-01-01'], ['1.9.2', '2019-03-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta)));
    }

    public function testNoHigherBranchIsNull(): void
    {
        // The whole package is old: S2 speaks, not S8.
        $meta = F::metadata([['1.9.2', '2019-03-02'], ['1.0.0', '2015-01-01']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta)));
    }

    public function testAHigherBranchThatStoppedBeforeOursIsNull(): void
    {
        // A 2.0 that was abandoned before 1.x got its last release is not the upstream moving on.
        $meta = F::metadata([['2.0.0-beta1', '2017-01-01'], ['2.0.0', '2017-06-01'], ['1.9.2', '2019-03-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta)));
    }

    public function testAHigherBranchWithoutADateIsNotEvidence(): void
    {
        $meta = F::metadata([['2.0.0', null], ['1.9.2', '2019-03-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta)));
    }

    public function testTheInstalledBranchWithoutADateIsNull(): void
    {
        $meta = F::metadata([['2.0.0', '2024-01-10'], ['1.9.2', null]]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta)));
    }

    public function testALowerBranchStillReleasingDoesNotCount(): void
    {
        // Installed 2.x went quiet, 1.x LTS still gets releases: nothing moved on from 2.x.
        $meta = F::metadata([['2.0.0', '2019-03-02'], ['1.9.9', '2026-01-01']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '2.0.0']), $meta)));
    }

    public function testTheNewestOfSeveralHigherBranchesIsNamed(): void
    {
        $meta = F::metadata([['3.0.0', '2023-01-01'], ['2.5.0', '2026-02-02'], ['1.9.2', '2019-03-02']]);

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta));

        self::assertNotNull($signal);
        self::assertStringEndsWith('upstream moved on to 2.5.0 (2026-02-02)', $signal->summary());
    }

    /** A branch listed first that is not above ours must not end the search for one that is. */
    public function testALowerBranchListedFirstDoesNotHideAHigherOne(): void
    {
        $meta = F::metadata([['0.9.0', '2015-01-01'], ['2.0.0', '2026-01-01'], ['1.9.2', '2019-03-02']]);

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta));

        self::assertNotNull($signal);
        self::assertStringEndsWith('upstream moved on to 2.0.0 (2026-01-01)', $signal->summary());
    }

    public function testZeroDotBranchesArePerMinor(): void
    {
        $meta = F::metadata([['0.5.0', '2026-01-01'], ['0.3.9', '2019-03-02']]);

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => '0.3.1']), $meta));

        self::assertNotNull($signal);
        self::assertSame('branch 0.3.x last released 2019-03-02 (7.5 years ago); upstream moved on to 0.5.0 (2026-01-01)', $signal->summary());
    }

    public function testADevInstalledVersionIsNull(): void
    {
        $meta = F::metadata([['2.0.0', '2026-01-01'], ['1.9.2', '2019-03-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => 'dev-master']), $meta)));
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.x-dev']), $meta)));
    }

    public function testAnInstalledBranchAbsentFromTheMetadataIsNull(): void
    {
        $meta = F::metadata([['2.0.0', '2026-01-01'], ['1.9.2', '2019-03-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '0.9.0']), $meta)));
    }

    /** A lock written against a tag the repository no longer lists: the branch's last date says nothing about it. */
    public function testAnInstalledVersionAboveEverythingListedOnItsBranchIsNull(): void
    {
        $meta = F::metadata([['2.0.0', '2026-01-01'], ['1.9.2', '2019-03-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => 'v1.9.5']), $meta)));
        self::assertNotNull($this->rule()->evaluate(F::facts(F::package(['version' => 'v1.9.2']), $meta)), 'the listed version itself is measured');
        self::assertNotNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.0.0']), $meta)), 'an older install on the branch is measured by the branch');
    }

    public function testNoMetadataIsNull(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.0.0']))));
    }
}
