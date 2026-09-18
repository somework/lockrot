<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Advisory;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\VersionParser;
use Lockrot\Data\Advisory\Advisory;
use PHPUnit\Framework\TestCase;

final class AdvisoryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(SecurityAdvisory::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
    }

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
            'affected_versions' => '*',
        ], $advisory->toArray());
    }

    /** swiftmailer 6.1.3 under CVE-2024-28859 (`<6.2.5`): 6.3.0 is out of the range, so the fix is out. */
    public function testTheAffectedRangeSaysWhetherALaterReleaseCarriesTheFix(): void
    {
        $range = (new VersionParser())->parseConstraints('>=4.0.0,<6.0.0|>=6.0.0,<6.2.5');
        $advisory = new Advisory('PKSA-1', 'CVE-2024-28859', null, null, null, null, $range);

        self::assertTrue($advisory->affects('6.1.3.0'));
        self::assertFalse($advisory->affects('6.3.0.0'));
        self::assertSame('>=4.0.0,<6.0.0|>=6.0.0,<6.2.5', $advisory->toArray()['affected_versions']);
        self::assertNull((new Advisory('PKSA-2', null, null, null, null, null))->affects('6.3.0.0'), 'no range, no answer');
    }

    public function testAPartialAdvisoryIsNamedByItsIdAndCarriesNothingElse(): void
    {
        $advisory = Advisory::fromComposer(new PartialSecurityAdvisory('vendor/pkg', 'GHSA-xxxx-yyyy-zzzz', new MatchAllConstraint()));

        self::assertSame('GHSA-xxxx-yyyy-zzzz', $advisory->label());
        self::assertNull($advisory->cve());
        self::assertNull($advisory->title());
        self::assertNull($advisory->reportedAt());
        self::assertSame(['id' => 'GHSA-xxxx-yyyy-zzzz', 'cve' => null, 'title' => null, 'link' => null, 'severity' => null, 'reported_at' => null, 'affected_versions' => '*'], $advisory->toArray());
        self::assertTrue($advisory->affects('9.9.9.0'), 'the partial form still carries the range');
    }

    public function testAFullAdvisoryWithoutACveIsNamedByItsId(): void
    {
        $composer = new SecurityAdvisory('vendor/pkg', 'PKSA-abcd-1234', new MatchAllConstraint(), 'Title', [['name' => 'FriendsOfPHP/security-advisories', 'remoteId' => 'x']], new \DateTimeImmutable('2024-03-01T12:00:00+00:00'));

        self::assertSame('PKSA-abcd-1234', Advisory::fromComposer($composer)->label());
    }
}
