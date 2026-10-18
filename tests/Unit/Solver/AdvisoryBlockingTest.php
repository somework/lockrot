<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Config;
use Composer\Package\CompletePackage;
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
        $advisory = PartialSecurityAdvisory::create('acme/lib', ['advisoryId' => 'PKSA-aaaa-bbbb-cccc', 'affectedVersions' => '>=1.0.0,<1.1.0', 'title' => 'A flaw', 'sources' => [['name' => 'GitHub', 'remoteId' => 'GHSA-aaaa-bbbb-cccc']], 'reportedAt' => '2026-01-01 00:00:00', 'cve' => null, 'link' => null, 'severity' => $severity], new VersionParser());

        return array_map(static fn (array $ranges): array => array_map(static fn ($range): string => $range->getPrettyString(), $ranges), $blocking->ranges(['acme/lib' => [$advisory]]));
    }
}
