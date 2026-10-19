<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Lockrot\Solver\AdvisoryBlocking;
use Lockrot\Solver\PoolAdvisories;
use PHPUnit\Framework\TestCase;

final class PoolAdvisoriesTest extends TestCase
{
    /** @var list<array{list<string>, bool}> */
    private array $asked = [];

    protected function setUp(): void
    {
        if (!class_exists(PartialSecurityAdvisory::class)) {
            self::markTestSkipped('Composer below 2.4 has no advisory records');
        }
    }

    public function testOneLookupAsksThePartialRecordsOfEachNameThatTheRunDidNotCoverOnce(): void
    {
        $advisories = $this->advisories()->withRecords(['acme/covered' => [self::advisory('acme/covered', '<1.0')]], ['acme/clean']);

        $ranges = $advisories->ranges(['acme/new', 'acme/covered', 'acme/new', 'acme/clean']);
        $advisories->ranges(['acme/new']);

        self::assertSame([[['acme/new'], false]], $this->asked);
        self::assertSame(['acme/new' => ['<2.0'], 'acme/covered' => ['<1.0']], self::pretty($ranges));
    }

    public function testACopyWithTheRunsRecordsLeavesTheOriginalWithoutThem(): void
    {
        $original = $this->advisories();

        $original->withRecords(['acme/covered' => [self::advisory('acme/covered', '<1.0')]], []);

        self::assertSame(['acme/covered' => ['<2.0']], self::pretty($original->ranges(['acme/covered'])));
        self::assertSame([[['acme/covered'], false]], $this->asked);
    }

    public function testTheRangesOfARecordedNameThatThePoolDoesNotHoldAreLeftOut(): void
    {
        $advisories = $this->advisories()->withRecords(['acme/covered' => [self::advisory('acme/covered', '<1.0')]], ['acme/other']);

        self::assertSame([], $advisories->ranges(['acme/other']));
    }

    private function advisories(): PoolAdvisories
    {
        return new PoolAdvisories(AdvisoryBlocking::everyAdvisory(), function (array $names, bool $complete): array {
            $this->asked[] = [$names, $complete];

            return array_map(static fn (string $name): array => [self::advisory($name, '<2.0')], array_combine($names, $names));
        });
    }

    private static function advisory(string $name, string $affected): PartialSecurityAdvisory
    {
        return PartialSecurityAdvisory::create($name, ['advisoryId' => 'PKSA-1111-2222-3333', 'affectedVersions' => $affected], new VersionParser());
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
