<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Advisory;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\Semver\Constraint\MatchAllConstraint;
use Lockrot\Data\Advisory\Advisory;
use PHPUnit\Framework\TestCase;

final class AdvisoryTest extends TestCase
{
    public function testAFullAdvisoryKeepsWhatTheReportPrints(): void
    {
        $composer = new SecurityAdvisory('vendor/pkg', 'PKSA-abcd-1234', new MatchAllConstraint(), 'Remote code execution', [['name' => 'GitHub', 'remoteId' => 'GHSA-xxxx-yyyy-zzzz']], new \DateTimeImmutable('2024-03-01T12:00:00+00:00'), 'CVE-2024-0001', 'https://example.test/advisory', 'high');

        $advisory = Advisory::fromComposer($composer);

        self::assertSame('PKSA-abcd-1234', $advisory->id());
        self::assertSame('CVE-2024-0001', $advisory->cve());
        self::assertSame('CVE-2024-0001', $advisory->label());
        self::assertSame('Remote code execution', $advisory->title());
        self::assertSame('https://example.test/advisory', $advisory->link());
        self::assertSame('high', $advisory->severity());
        self::assertSame([
            'id' => 'PKSA-abcd-1234',
            'cve' => 'CVE-2024-0001',
            'title' => 'Remote code execution',
            'link' => 'https://example.test/advisory',
            'severity' => 'high',
            'reported_at' => '2024-03-01T12:00:00+00:00',
        ], $advisory->toArray());
    }

    public function testAPartialAdvisoryIsNamedByItsIdAndCarriesNothingElse(): void
    {
        $advisory = Advisory::fromComposer(new PartialSecurityAdvisory('vendor/pkg', 'GHSA-xxxx-yyyy-zzzz', new MatchAllConstraint()));

        self::assertSame('GHSA-xxxx-yyyy-zzzz', $advisory->label());
        self::assertNull($advisory->cve());
        self::assertNull($advisory->title());
        self::assertNull($advisory->reportedAt());
        self::assertSame(['id' => 'GHSA-xxxx-yyyy-zzzz', 'cve' => null, 'title' => null, 'link' => null, 'severity' => null, 'reported_at' => null], $advisory->toArray());
    }

    public function testAFullAdvisoryWithoutACveIsNamedByItsId(): void
    {
        $composer = new SecurityAdvisory('vendor/pkg', 'PKSA-abcd-1234', new MatchAllConstraint(), 'Title', [['name' => 'FriendsOfPHP/security-advisories', 'remoteId' => 'x']], new \DateTimeImmutable('2024-03-01T12:00:00+00:00'));

        self::assertSame('PKSA-abcd-1234', Advisory::fromComposer($composer)->label());
    }
}
