<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Config;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Lockrot\Solver\AdvisoryBlocking;
use Lockrot\Solver\PolicyRemovals;
use Lockrot\Solver\PoolAdvisories;
use Lockrot\Solver\PoolAdvisoryFeed;
use PHPUnit\Framework\TestCase;

/** The removals of Composer's own pool filter, read the same on each Composer minor that lockrot supports. */
final class PolicyRemovalsTest extends TestCase
{
    public function testAPoolThatNoFilterRanHasNoRemovals(): void
    {
        self::assertSame([], PolicyRemovals::of(new Pool([new CompletePackage('acme/lib', '1.0.0.0', '1.0.0')])));
    }

    public function testAVersionThatAnAdvisoryBlocksIsRemovedForAdvisories(): void
    {
        $pool = new Pool([new CompletePackage('acme/lib', '1.0.0.0', '1.0.0'), new CompletePackage('acme/lib', '1.1.0.0', '1.1.0')]);

        self::assertSame(['acme/lib' => ['1.0.0.0' => PolicyRemovals::ADVISORIES]], PolicyRemovals::of(self::filtered(AdvisoryBlocking::everyAdvisory(), $pool, ['acme/lib' => [self::advisory('acme/lib', '<1.1')]])));
    }

    public function testAnAbandonedPackageIsRemovedForAbandonedUnderEachNameThatItReplaces(): void
    {
        $fork = new CompletePackage('acme/fork', '1.0.0.0', '1.0.0');
        $fork->setAbandoned(true);
        $fork->setReplaces(['acme/lib' => new Link('acme/fork', 'acme/lib', new Constraint('==', '1.0.0.0'), Link::TYPE_REPLACE, '1.0.0')]);
        $blocking = AdvisoryBlocking::fromConfig(self::config(['block-abandoned' => true]));

        self::assertSame(['acme/fork' => ['1.0.0.0' => PolicyRemovals::ABANDONED], 'acme/lib' => ['1.0.0.0' => PolicyRemovals::ABANDONED]], PolicyRemovals::of(self::filtered($blocking, new Pool([$fork]), [])));
    }

    public function testEachRemovedVersionKeepsItsOwnPolicy(): void
    {
        $old = new CompletePackage('acme/old', '2.0.0.0', '2.0.0');
        $old->setAbandoned(true);
        $pool = new Pool([new CompletePackage('acme/lib', '1.0.0.0', '1.0.0'), $old]);
        $blocking = AdvisoryBlocking::fromConfig(self::config(['block-abandoned' => true]));

        self::assertSame(['acme/lib' => ['1.0.0.0' => PolicyRemovals::ADVISORIES], 'acme/old' => ['2.0.0.0' => PolicyRemovals::ABANDONED]], PolicyRemovals::of(self::filtered($blocking, $pool, ['acme/lib' => [self::advisory('acme/lib', '<1.1')]])));
    }

    public function testTheRemovedVersionsOfANameAreInVersionOrder(): void
    {
        $pool = new Pool([new CompletePackage('acme/lib', '1.1.0.0', '1.1.0'), new CompletePackage('acme/lib', '1.0.0.0', '1.0.0')]);

        self::assertSame(['acme/lib' => ['1.0.0.0' => PolicyRemovals::ADVISORIES, '1.1.0.0' => PolicyRemovals::ADVISORIES]], PolicyRemovals::of(self::filtered(AdvisoryBlocking::everyAdvisory(), $pool, ['acme/lib' => [self::advisory('acme/lib', '<2.0')]])));
    }

    /** @param array<string, list<PartialSecurityAdvisory>> $records */
    private static function filtered(AdvisoryBlocking $blocking, Pool $pool, array $records): Pool
    {
        $filter = $blocking->poolFilter();
        self::assertNotNull($filter, 'Composer\'s pool filter: '.($blocking->filterError() === null ? 'blocking is off' : $blocking->filterError()->getMessage()));
        $feed = new PoolAdvisoryFeed(new PoolAdvisories($blocking, static fn (array $names): array => array_intersect_key($records, array_flip($names))));

        return $filter->filter($pool, [$feed], new Request());
    }

    /** @param array<string, mixed> $audit */
    private static function config(array $audit): Config
    {
        $config = new Config(false);
        $config->merge(['config' => ['audit' => $audit]]);

        return $config;
    }

    private static function advisory(string $name, string $affected): PartialSecurityAdvisory
    {
        return PartialSecurityAdvisory::create($name, ['advisoryId' => 'PKSA-aaaa-bbbb-cccc', 'affectedVersions' => $affected, 'title' => 'A flaw', 'sources' => [['name' => 'GitHub', 'remoteId' => 'GHSA-aaaa-bbbb-cccc']], 'reportedAt' => '2026-01-01 00:00:00', 'cve' => null, 'link' => null, 'severity' => 'high'], new VersionParser());
    }
}
