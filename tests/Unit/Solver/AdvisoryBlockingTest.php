<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Config;
use Composer\Package\CompletePackage;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Lockrot\Solver\AdvisoryBlocking;
use Lockrot\Solver\ComposerFeatures;
use PHPUnit\Framework\TestCase;

/**
 * The settings of Composer's `block` operation, read through the API of the Composer that runs
 * lockrot. Composer before 2.9 blocks nothing, so the tests need a Composer that blocks.
 */
final class AdvisoryBlockingTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ComposerFeatures::blocksAdvisories()) {
            self::markTestSkipped('Composer below 2.9 has no SecurityAdvisoryPoolFilter: it blocks no advisory in an update');
        }
    }

    public function testAnAdvisoryThatTheAuditAloneIgnoresIsBlocked(): void
    {
        $blocking = AdvisoryBlocking::fromConfig(self::config(['ignore' => ['PKSA-aaaa-bbbb-cccc' => ['apply' => 'audit']]]));

        self::assertSame(['acme/lib' => ['>=1.0.0,<1.1.0']], self::ranges($blocking, 'high'));
    }

    public function testAnAdvisoryThatTheBlockOperationIgnoresIsNotBlocked(): void
    {
        $blocking = AdvisoryBlocking::fromConfig(self::config(['ignore' => ['PKSA-aaaa-bbbb-cccc' => ['apply' => 'block']]]));

        self::assertSame([], self::ranges($blocking, 'high'));
    }

    public function testASeverityThatTheBlockOperationIgnoresIsNotBlocked(): void
    {
        $blocking = AdvisoryBlocking::fromConfig(self::config(['ignore-severity' => ['low' => ['apply' => 'block']]]));

        self::assertSame([], self::ranges($blocking, 'low'));
        self::assertSame(['acme/lib' => ['>=1.0.0,<1.1.0']], self::ranges($blocking, 'high'));
    }

    public function testAnAbandonedPackageIsBlockedOnlyWhenTheConfigBlocksAbandonedPackages(): void
    {
        $abandoned = new CompletePackage('acme/old', '1.0.0.0', '1.0.0');
        $abandoned->setAbandoned(true);

        self::assertTrue(AdvisoryBlocking::fromConfig(self::config(['block-abandoned' => true]))->blocksAbandoned($abandoned));
        self::assertFalse(AdvisoryBlocking::fromConfig(self::config([]))->blocksAbandoned($abandoned));
        self::assertFalse(AdvisoryBlocking::fromConfig(self::config(['block-abandoned' => true, 'ignore-abandoned' => ['acme/old' => ['apply' => 'block']]]))->blocksAbandoned($abandoned));
    }

    public function testAConfigThatTurnsTheBlockingOffBlocksNothing(): void
    {
        $blocking = AdvisoryBlocking::fromConfig(self::config(['block-insecure' => false]));

        self::assertFalse($blocking->blocks());
        self::assertSame([], self::ranges($blocking, 'high'));
    }

    public function testAnAdvisoryThatAnIgnoredIdAndAnIgnoredSeverityBothLeaveOutIsNotBlocked(): void
    {
        $blocking = AdvisoryBlocking::fromConfig(self::config(['ignore' => ['PKSA-aaaa-bbbb-cccc' => ['apply' => 'block']], 'ignore-severity' => ['low' => ['apply' => 'block']]]));

        self::assertSame([], self::ranges($blocking, 'high'));
    }

    /** @dataProvider blockingOfEveryName */
    #[\PHPUnit\Framework\Attributes\DataProvider('blockingOfEveryName')]
    public function testTheRangesOfEveryNameAreBlocked(AdvisoryBlocking $blocking): void
    {
        $records = ['acme/a' => [self::advisory('acme/a', 'PKSA-aaaa-aaaa-aaaa', '<1.1', 'high')], 'acme/b' => [self::advisory('acme/b', 'PKSA-bbbb-bbbb-bbbb', '<2.1', 'high')]];

        self::assertSame(['acme/a' => ['<1.1'], 'acme/b' => ['<2.1']], self::pretty($blocking->ranges($records)));
    }

    /** @return iterable<string, array{AdvisoryBlocking}> */
    public static function blockingOfEveryName(): iterable
    {
        yield 'no ignore rule' => [AdvisoryBlocking::everyAdvisory()];
        yield 'an ignore rule that matches no record' => [AdvisoryBlocking::fromConfig(self::config(['ignore' => ['PKSA-cccc-cccc-cccc' => ['apply' => 'block']]]))];
    }

    /** A partial record carries no CVE or GHSA id, so an ignore rule on one needs the complete records. */
    public function testPartialRecordsNeedTheCompleteOnesOnlyForAnIgnoreRuleOnAnIdThatIsNoPksaId(): void
    {
        $records = ['acme/lib' => [PartialSecurityAdvisory::create('acme/lib', ['advisoryId' => 'PKSA-aaaa-bbbb-cccc', 'affectedVersions' => '<1.1'], new VersionParser())]];

        self::assertTrue(AdvisoryBlocking::fromConfig(self::config(['ignore' => ['GHSA-aaaa-bbbb-cccc' => ['apply' => 'block']]]))->needsComplete($records));
        self::assertFalse(AdvisoryBlocking::fromConfig(self::config(['ignore' => ['PKSA-aaaa-bbbb-cccc' => ['apply' => 'block']]]))->needsComplete($records));
    }

    public function testNoAbandonedPackageIsBlockedWhenTheConfigTurnsTheBlockingOffOrByTheDefaultOfATest(): void
    {
        $abandoned = new CompletePackage('acme/old', '1.0.0.0', '1.0.0');
        $abandoned->setAbandoned(true);

        self::assertFalse(AdvisoryBlocking::fromConfig(self::config(['block-insecure' => false]))->blocksAbandoned($abandoned));
        self::assertFalse(AdvisoryBlocking::everyAdvisory()->blocksAbandoned($abandoned));
    }

    /** @param array<string, mixed> $audit */
    private static function config(array $audit): Config
    {
        $config = new Config(false);
        $config->merge(['config' => ['audit' => $audit]]);

        return $config;
    }

    /** @return array<string, list<string>> name => each blocked range as Composer writes it */
    private static function ranges(AdvisoryBlocking $blocking, string $severity): array
    {
        return self::pretty($blocking->ranges(['acme/lib' => [self::advisory('acme/lib', 'PKSA-aaaa-bbbb-cccc', '>=1.0.0,<1.1.0', $severity)]]));
    }

    private static function advisory(string $name, string $id, string $affected, string $severity): PartialSecurityAdvisory
    {
        return PartialSecurityAdvisory::create($name, ['advisoryId' => $id, 'affectedVersions' => $affected, 'title' => 'A flaw', 'sources' => [['name' => 'GitHub', 'remoteId' => 'GHSA-aaaa-bbbb-cccc']], 'reportedAt' => '2026-01-01 00:00:00', 'cve' => null, 'link' => null, 'severity' => $severity], new VersionParser());
    }

    /**
     * @param array<string, list<ConstraintInterface>> $ranges
     *
     * @return array<string, list<string>>
     */
    private static function pretty(array $ranges): array
    {
        return array_map(static fn (array $list): array => array_map(static fn (ConstraintInterface $range): string => $range->getPrettyString(), $list), $ranges);
    }
}
