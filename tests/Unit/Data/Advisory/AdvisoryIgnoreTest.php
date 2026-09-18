<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Advisory;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\Config;
use Composer\Policy\PolicyConfig;
use Composer\Semver\Constraint\MatchAllConstraint;
use Lockrot\Data\Advisory\AdvisoryIgnore;
use PHPUnit\Framework\TestCase;

final class AdvisoryIgnoreTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(SecurityAdvisory::class)) {
            self::markTestSkipped('Composer without the advisory API');
        }
    }

    private function full(string $id, ?string $cve, ?string $severity, string $remoteId = 'GHSA-remote'): SecurityAdvisory
    {
        return new SecurityAdvisory('vendor/pkg', $id, new MatchAllConstraint(), 'Title', [['name' => 'GitHub', 'remoteId' => $remoteId]], new \DateTimeImmutable('2024-01-01T00:00:00+00:00'), $cve, null, $severity);
    }

    public function testNoneIgnoresNothing(): void
    {
        $ignore = AdvisoryIgnore::none();

        self::assertTrue($ignore->isEmpty());
        self::assertFalse($ignore->ignores('vendor/pkg', $this->full('PKSA-1', 'CVE-2024-0001', 'critical')));
    }

    public function testEveryKeyAuditMatchesOn(): void
    {
        $advisory = $this->full('PKSA-1', 'CVE-2024-0001', 'low', 'GHSA-abcd');

        self::assertTrue((new AdvisoryIgnore(['PKSA-1']))->ignores('vendor/pkg', $advisory), 'advisory id');
        self::assertTrue((new AdvisoryIgnore(['CVE-2024-0001']))->ignores('vendor/pkg', $advisory), 'CVE');
        self::assertTrue((new AdvisoryIgnore(['GHSA-abcd']))->ignores('vendor/pkg', $advisory), 'source id');
        self::assertTrue((new AdvisoryIgnore(['vendor/pkg']))->ignores('vendor/pkg', $advisory), 'package');
        self::assertTrue((new AdvisoryIgnore([], ['low']))->ignores('vendor/pkg', $advisory), 'severity');
        self::assertFalse((new AdvisoryIgnore(['PKSA-2', 'other/pkg'], ['high']))->ignores('vendor/pkg', $advisory));
    }

    public function testAPartialRecordMatchesOnlyByIdOrPackage(): void
    {
        $partial = new PartialSecurityAdvisory('vendor/pkg', 'PKSA-1', new MatchAllConstraint());

        self::assertTrue((new AdvisoryIgnore(['PKSA-1']))->ignores('vendor/pkg', $partial));
        self::assertTrue((new AdvisoryIgnore(['vendor/pkg']))->ignores('vendor/pkg', $partial));
        self::assertFalse((new AdvisoryIgnore([], ['low']))->ignores('vendor/pkg', $partial));
    }

    public function testFromRawAcceptsListsAndMaps(): void
    {
        $ignore = AdvisoryIgnore::fromRaw(['PKSA-1', 'CVE-2024-0002' => 'accepted', 'vendor/pkg' => null, 7 => 42], ['low' => 'noise', 'medium']);

        self::assertTrue($ignore->ignores('other/pkg', $this->full('PKSA-1', null, null)));
        self::assertTrue($ignore->ignores('other/pkg', $this->full('PKSA-9', 'CVE-2024-0002', null)));
        self::assertTrue($ignore->ignores('vendor/pkg', $this->full('PKSA-9', null, null)));
        self::assertTrue($ignore->ignores('other/pkg', $this->full('PKSA-9', null, 'low')));
        self::assertTrue($ignore->ignores('other/pkg', $this->full('PKSA-9', null, 'medium')));
        self::assertFalse($ignore->ignores('other/pkg', $this->full('PKSA-9', null, 'high')));
    }

    /** What phpmyadmin's composer.json does: `config.policy.advisories.ignore-id`. */
    public function testFromConfigReadsThePolicyIgnoreListAndTheLegacyAuditOne(): void
    {
        if (!class_exists(PolicyConfig::class)) {
            self::markTestSkipped('Composer without the policy object');
        }
        $config = new Config(false);
        $config->merge(['config' => ['policy' => ['advisories' => ['ignore-id' => ['PKSA-policy'], 'ignore-severity' => ['low']]]]]);
        $ignore = AdvisoryIgnore::fromConfig($config);

        self::assertTrue($ignore->ignores('vendor/pkg', $this->full('PKSA-policy', null, null)));
        self::assertTrue($ignore->ignores('vendor/pkg', $this->full('PKSA-9', null, 'low')));
        self::assertFalse($ignore->ignores('vendor/pkg', $this->full('PKSA-9', null, 'high')));

        $legacy = new Config(false);
        $legacy->merge(['config' => ['audit' => ['ignore' => ['CVE-2024-0001' => 'accepted']]]]);
        $ignore = AdvisoryIgnore::fromConfig($legacy);

        self::assertTrue($ignore->ignores('vendor/pkg', $this->full('PKSA-9', 'CVE-2024-0001', null)));
        self::assertFalse($ignore->ignores('vendor/pkg', $this->full('PKSA-9', 'CVE-2024-0002', null)));
    }

    public function testIsEmptyNeedsBothListsEmpty(): void
    {
        self::assertFalse((new AdvisoryIgnore([], ['low']))->isEmpty());
        self::assertFalse((new AdvisoryIgnore(['PKSA-1'], []))->isEmpty());
        self::assertTrue((new AdvisoryIgnore([], []))->isEmpty());
    }

    /** A policy Composer rejects — a section reserved for a later version — costs the ignore list, never the report. */
    public function testAPolicyComposerRejectsIgnoresNothingInsteadOfThrowing(): void
    {
        if (!class_exists(PolicyConfig::class)) {
            self::markTestSkipped('Composer without the policy object');
        }
        $config = new Config(false);
        $config->merge(['config' => ['policy' => ['licenses' => ['allow' => ['MIT']], 'advisories' => ['ignore-id' => ['PKSA-policy']]]]]);

        $ignore = AdvisoryIgnore::fromConfig($config);

        self::assertTrue($ignore->isEmpty());
    }

    public function testAnEmptyConfigIgnoresNothing(): void
    {
        self::assertTrue(AdvisoryIgnore::fromConfig(new Config(false))->isEmpty());
    }
}
