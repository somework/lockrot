<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\Libyears;
use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Analyzer\Report2Root;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Verdict\Finding;
use PHPUnit\Framework\TestCase;

final class LibyearsBlockTest extends TestCase
{
    private static function finding(string $package, LibyearsMeasurement $years): Finding
    {
        return (new FindingBuilder())->withPackage($package)->withChain([$package])->withLibyears($years)->build();
    }

    /** @return list<Finding> */
    private static function findings(): array
    {
        return [
            self::finding('a/measured', LibyearsMeasurement::of(1.0)),
            self::finding('b/bound', LibyearsMeasurement::atLeast(4.06)),
            self::finding('c/snapshot', LibyearsMeasurement::unmeasured(Libyears::BRANCH_SNAPSHOT)),
            self::finding('d/shared', LibyearsMeasurement::unmeasured(Libyears::SHARED_COMMIT)),
            self::finding('e/beta', LibyearsMeasurement::unmeasured(Libyears::NO_STABLE_RELEASE)),
        ];
    }

    public function testTheMeasuredAndEveryUnmeasuredCountAddUpToThePackages(): void
    {
        $findings = self::findings();
        $block = Report2Root::toLibyearsArray(Libyears::fromFindings($findings), $findings);

        self::assertSame($block['packages'], $block['measured'] + array_sum($block['unmeasured']));
        self::assertSame(5, $block['packages']);
    }

    public function testEveryReasonIsWrittenZerosIncluded(): void
    {
        $findings = self::findings();
        $block = Report2Root::toLibyearsArray(Libyears::fromFindings($findings), $findings);

        self::assertSame(['not_from_composer_repository', 'unavailable', 'not_found', 'branch_snapshot', 'shared_commit', 'undated', 'no_stable_release', 'undated_releases'], array_keys($block['unmeasured']));
        self::assertSame(['not_from_composer_repository' => 0, 'unavailable' => 0, 'not_found' => 0, 'branch_snapshot' => 1, 'shared_commit' => 1, 'undated' => 0, 'no_stable_release' => 1, 'undated_releases' => 0], $block['unmeasured']);
    }

    public function testTheLowerBoundsAreCounted(): void
    {
        $findings = self::findings();
        $block = Report2Root::toLibyearsArray(Libyears::fromFindings($findings), $findings);

        self::assertSame(1, $block['at_least_count']);
        self::assertSame(2, $block['measured']);
    }

    public function testTheFurthestBehindSaysWhetherItIsALowerBound(): void
    {
        $findings = self::findings();
        $bound = Report2Root::toLibyearsArray(Libyears::fromFindings($findings), $findings)['furthest_behind'];
        $exact = Report2Root::toLibyearsArray(Libyears::fromFindings([$findings[0]]), [$findings[0]])['furthest_behind'];

        self::assertSame(['package' => 'b/bound', 'version' => '1.0.0', 'libyears' => 4.06, 'at_least' => true], $bound);
        self::assertSame(['package' => 'a/measured', 'version' => '1.0.0', 'libyears' => 1.0, 'at_least' => false], $exact);
    }
}
