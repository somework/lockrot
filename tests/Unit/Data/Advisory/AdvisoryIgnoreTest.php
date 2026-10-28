<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Advisory;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\Config;
use Composer\Semver\Constraint\MatchAllConstraint;
use Lockrot\Data\Advisory\AdvisoryIgnore;
use Lockrot\Data\Advisory\AdvisoryIgnoreMatch;
use PHPUnit\Framework\TestCase;

final class AdvisoryIgnoreTest extends TestCase
{
    /** @var string|false */
    private $composerPolicy;

    protected function setUp(): void
    {
        $this->composerPolicy = getenv('COMPOSER_POLICY');
    }

    protected function tearDown(): void
    {
        putenv($this->composerPolicy === false ? 'COMPOSER_POLICY' : 'COMPOSER_POLICY='.$this->composerPolicy);
    }

    private function full(string $id, ?string $cve, ?string $severity, string $remoteId = 'GHSA-remote', string $package = 'vendor/pkg'): SecurityAdvisory
    {
        return new SecurityAdvisory($package, $id, new MatchAllConstraint(), 'Title', [['name' => 'GitHub', 'remoteId' => $remoteId]], new \DateTimeImmutable('2024-01-01T00:00:00+00:00'), $cve, null, $severity);
    }

    /** @param array<string, mixed> $config the `config` section of composer.json */
    private static function read(array $config): AdvisoryIgnore
    {
        $composer = new Config(false);
        $composer->merge(['config' => $config]);

        return AdvisoryIgnore::fromConfig($composer);
    }

    /** @return array{string, string, ?string, string}|null kind, key, reason and by */
    private static function record(?AdvisoryIgnoreMatch $match): ?array
    {
        return $match === null ? null : [$match->kind(), $match->key(), $match->reason(), $match->by()];
    }

    public function testNoneIgnoresNothing(): void
    {
        self::assertNull(AdvisoryIgnore::none()->match('vendor/pkg', $this->full('PKSA-1', 'CVE-2024-0001', 'critical')));
        self::assertNull(AdvisoryIgnore::none()->whyUnreadable());
        self::assertNull(AdvisoryIgnore::none()->disabledBy());
    }

    public function testEachKindAuditMatchesOnIsARecordWithItsReason(): void
    {
        $advisory = $this->full('PKSA-1', 'CVE-2024-0001', 'low', 'GHSA-abcd');
        $ignore = static fn (array $list, array $severities = []): AdvisoryIgnore => AdvisoryIgnore::fromRaw($list, $severities);

        self::assertSame([AdvisoryIgnoreMatch::ID, 'PKSA-1', 'id reason', AdvisoryIgnoreMatch::BY_AUDIT], self::record($ignore(['PKSA-1' => 'id reason'])->match('vendor/pkg', $advisory)));
        self::assertSame([AdvisoryIgnoreMatch::CVE, 'CVE-2024-0001', null, AdvisoryIgnoreMatch::BY_AUDIT], self::record($ignore(['CVE-2024-0001'])->match('vendor/pkg', $advisory)));
        self::assertSame([AdvisoryIgnoreMatch::REMOTE_ID, 'GHSA-abcd', 'remote', AdvisoryIgnoreMatch::BY_AUDIT], self::record($ignore(['GHSA-abcd' => 'remote'])->match('vendor/pkg', $advisory)));
        self::assertSame([AdvisoryIgnoreMatch::PACKAGE, 'vendor/pkg', 'whole package', AdvisoryIgnoreMatch::BY_AUDIT], self::record($ignore(['vendor/pkg' => 'whole package'])->match('vendor/pkg', $advisory)));
        self::assertSame([AdvisoryIgnoreMatch::SEVERITY, 'low', 'noise', AdvisoryIgnoreMatch::BY_AUDIT_SEVERITY], self::record($ignore([], ['low' => 'noise'])->match('vendor/pkg', $advisory)));
        self::assertNull($ignore(['PKSA-2' => null, 'other/pkg' => null], ['high'])->match('vendor/pkg', $advisory));
    }

    /** Composer's Auditor checks package, id, severity, CVE and source id in that order. The last match gives the reason. */
    public function testWhenSeveralRulesMatchTheLastOneComposerChecksWins(): void
    {
        $advisory = $this->full('PKSA-1', 'CVE-2024-0001', 'low', 'GHSA-abcd');

        self::assertSame(AdvisoryIgnoreMatch::ID, self::record(AdvisoryIgnore::fromRaw(['vendor/pkg' => 'p', 'PKSA-1' => 'i'], [])->match('vendor/pkg', $advisory))[0] ?? null);
        self::assertSame(AdvisoryIgnoreMatch::SEVERITY, self::record(AdvisoryIgnore::fromRaw(['PKSA-1' => 'i'], ['low' => 's'])->match('vendor/pkg', $advisory))[0] ?? null);
        self::assertSame(AdvisoryIgnoreMatch::CVE, self::record(AdvisoryIgnore::fromRaw(['CVE-2024-0001' => 'c'], ['low' => 's'])->match('vendor/pkg', $advisory))[0] ?? null);
        self::assertSame(AdvisoryIgnoreMatch::REMOTE_ID, self::record(AdvisoryIgnore::fromRaw(['GHSA-abcd' => 'r', 'CVE-2024-0001' => 'c'], [])->match('vendor/pkg', $advisory))[0] ?? null);
    }

    public function testAnIdRuleAndAPackageRuleStayApart(): void
    {
        $ignore = AdvisoryIgnore::fromRaw(['PKSA-1' => 'this advisory', 'vendor/pkg' => 'this package'], []);

        self::assertSame([AdvisoryIgnoreMatch::PACKAGE, 'vendor/pkg', 'this package', AdvisoryIgnoreMatch::BY_AUDIT], self::record($ignore->match('vendor/pkg', $this->full('PKSA-2', null, null))));
        self::assertSame([AdvisoryIgnoreMatch::ID, 'PKSA-1', 'this advisory', AdvisoryIgnoreMatch::BY_AUDIT], self::record($ignore->match('other/pkg', $this->full('PKSA-1', null, null, 'GHSA-x', 'other/pkg'))));
        self::assertNull($ignore->match('other/pkg', $this->full('PKSA-2', null, null, 'GHSA-x', 'other/pkg')));
    }

    public function testAPartialRecordMatchesOnlyByIdOrPackage(): void
    {
        $partial = new PartialSecurityAdvisory('vendor/pkg', 'PKSA-1', new MatchAllConstraint());

        self::assertSame(AdvisoryIgnoreMatch::ID, self::record(AdvisoryIgnore::fromRaw(['PKSA-1'], [])->match('vendor/pkg', $partial))[0] ?? null);
        self::assertSame(AdvisoryIgnoreMatch::PACKAGE, self::record(AdvisoryIgnore::fromRaw(['vendor/pkg'], [])->match('vendor/pkg', $partial))[0] ?? null);
        self::assertNull(AdvisoryIgnore::fromRaw([], ['low'])->match('vendor/pkg', $partial));
    }

    public function testFromRawAcceptsListsAndMaps(): void
    {
        $ignore = AdvisoryIgnore::fromRaw(['PKSA-1', 'CVE-2024-0002' => 'accepted', 'vendor/pkg' => null, 7 => 42], ['low' => 'noise', 'medium']);

        self::assertSame([AdvisoryIgnoreMatch::ID, 'PKSA-1', null, AdvisoryIgnoreMatch::BY_AUDIT], self::record($ignore->match('other/pkg', $this->full('PKSA-1', null, null))));
        self::assertSame('accepted', self::record($ignore->match('other/pkg', $this->full('PKSA-9', 'CVE-2024-0002', null)))[2] ?? null);
        self::assertNotNull($ignore->match('vendor/pkg', $this->full('PKSA-9', null, null)));
        self::assertSame('noise', self::record($ignore->match('other/pkg', $this->full('PKSA-9', null, 'low')))[2] ?? null);
        self::assertNotNull($ignore->match('other/pkg', $this->full('PKSA-9', null, 'medium')));
        self::assertNull($ignore->match('other/pkg', $this->full('PKSA-9', null, 'high')));
        self::assertNull($ignore->match('other/pkg', $this->full('42', null, null)), 'a non-string value is no rule');
    }

    /** What phpmyadmin's composer.json does: `config.policy.advisories.ignore-id`. */
    public function testThePolicyListsKeepTheirReasonsAndSayPolicyAdvisories(): void
    {
        $ignore = self::read(['policy' => ['advisories' => [
            'ignore-id' => ['PKSA-policy' => 'reviewed'],
            'ignore' => ['other/pkg' => 'not shipped', 'wild/*' => 'a pattern'],
            'ignore-severity' => ['low' => 'noise'],
        ]]]);

        self::assertSame([AdvisoryIgnoreMatch::ID, 'PKSA-policy', 'reviewed', AdvisoryIgnoreMatch::BY_POLICY], self::record($ignore->match('vendor/pkg', $this->full('PKSA-policy', null, null))));
        self::assertSame([AdvisoryIgnoreMatch::SEVERITY, 'low', 'noise', AdvisoryIgnoreMatch::BY_POLICY], self::record($ignore->match('vendor/pkg', $this->full('PKSA-9', null, 'low'))));
        self::assertSame([AdvisoryIgnoreMatch::PACKAGE, 'other/pkg', 'not shipped', AdvisoryIgnoreMatch::BY_POLICY], self::record($ignore->match('other/pkg', $this->full('PKSA-9', null, null, 'GHSA-x', 'other/pkg'))));
        self::assertNull($ignore->match('wild/pkg', $this->full('PKSA-9', null, null, 'GHSA-x', 'wild/pkg')), "Composer's Auditor reads a package key as an exact name, never as a pattern");
        self::assertNull($ignore->match('vendor/pkg', $this->full('PKSA-9', null, 'high')));
        self::assertNull($ignore->disabledBy());
    }

    public function testAProjectStillOnAuditIgnoreSaysAuditIgnoreUnder210(): void
    {
        $ignore = self::read(['audit' => ['ignore' => ['CVE-2024-0001' => 'accepted', 'PKSA-block' => ['apply' => 'block']], 'ignore-severity' => ['low']]]);

        self::assertSame([AdvisoryIgnoreMatch::CVE, 'CVE-2024-0001', 'accepted', AdvisoryIgnoreMatch::BY_AUDIT], self::record($ignore->match('vendor/pkg', $this->full('PKSA-9', 'CVE-2024-0001', null))));
        self::assertSame([AdvisoryIgnoreMatch::SEVERITY, 'low', null, AdvisoryIgnoreMatch::BY_AUDIT_SEVERITY], self::record($ignore->match('vendor/pkg', $this->full('PKSA-9', null, 'low'))));
        self::assertNull($ignore->match('vendor/pkg', $this->full('PKSA-block', null, null)), 'a rule for blocking only');
    }

    /** The advisory list is read on its own, so another section Composer rejects cannot empty it. */
    public function testABrokenMalwareSectionOrAReservedListNameDoesNotEmptyTheList(): void
    {
        foreach ([
            'a broken malware section' => ['malware' => ['ignore' => ['vendor/x' => 42]]],
            'a reserved custom list name' => ['licenses' => ['allow' => ['MIT']]],
        ] as $label => $other) {
            $ignore = self::read(['policy' => $other + ['advisories' => ['ignore-id' => ['PKSA-policy' => 'reviewed']]]]);

            self::assertNull($ignore->whyUnreadable(), $label);
            self::assertSame(AdvisoryIgnoreMatch::ID, self::record($ignore->match('vendor/pkg', $this->full('PKSA-policy', null, null)))[0] ?? null, $label);
        }
    }

    public function testAnAdvisoryListComposerRejectsIgnoresNothingAndSaysWhy(): void
    {

        $ignore = self::read(['policy' => ['advisories' => ['ignore' => ['vendor/pkg' => 42]]]]);

        self::assertNull($ignore->match('vendor/pkg', $this->full('PKSA-1', null, null)));
        $why = $ignore->whyUnreadable();
        self::assertNotNull($why);
        self::assertNotSame('', $why, 'Composer names what it rejected');
        self::assertStringNotContainsString("\n", $why, 'the first line of Composer\'s message');
        self::assertNull($ignore->disabledBy());
    }

    /**
     * Composer's default `policy` is `true`, which the policy API rejects as an argument: without
     * the normalisation every default run loses `config.audit.ignore`.
     */
    public function testARealComposerConfigReadsTheListWithoutANote(): void
    {
        $advisory = $this->full('PKSA-9', 'CVE-2024-0001', null);

        $none = AdvisoryIgnore::fromConfig(new Config(false));
        self::assertNull($none->whyUnreadable(), 'no policy key');
        self::assertNull($none->match('vendor/pkg', $advisory));
        self::assertNull($none->disabledBy());

        foreach (['policy: true' => ['policy' => true], 'only audit.ignore' => []] as $label => $policy) {
            $ignore = self::read($policy + ['audit' => ['ignore' => ['CVE-2024-0001' => 'accepted']]]);
            self::assertNull($ignore->whyUnreadable(), $label);
            self::assertSame([AdvisoryIgnoreMatch::CVE, 'CVE-2024-0001', 'accepted', AdvisoryIgnoreMatch::BY_AUDIT], self::record($ignore->match('vendor/pkg', $advisory)), $label);
            self::assertNull($ignore->disabledBy(), $label);
        }
    }

    public function testComposerPolicyZeroTurnsTheLookupOffAndNamesTheVariable(): void
    {
        putenv('COMPOSER_POLICY=0');

        $ignore = self::read(['audit' => ['ignore' => ['CVE-2024-0001']]]);

        self::assertSame(['policy_key' => 'COMPOSER_POLICY', 'value' => false], $ignore->disabledBy());
        self::assertNull($ignore->match('vendor/pkg', $this->full('PKSA-9', 'CVE-2024-0001', null)));
        self::assertNull($ignore->whyUnreadable());
    }

    public function testThePolicyTurnsAdvisoriesOffByItsKeysAndNeverByTheAuditMode(): void
    {

        self::assertSame(['policy_key' => 'policy', 'value' => false], self::read(['policy' => false])->disabledBy());
        self::assertSame(['policy_key' => 'policy.advisories', 'value' => false], self::read(['policy' => ['advisories' => false]])->disabledBy());
        self::assertNull(self::read(['policy' => ['advisories' => ['audit' => 'ignore']]])->disabledBy(), 'Composer 2.10 reads no audit mode of the advisories list');
        self::assertNull(self::read(['policy' => ['advisories' => ['audit' => 'report']]])->disabledBy());
        $auditOff = self::read(['policy' => ['advisories' => ['audit' => 'ignore', 'ignore-id' => ['PKSA-1' => 'reviewed']]]]);
        self::assertSame(AdvisoryIgnoreMatch::ID, self::record($auditOff->match('vendor/pkg', $this->full('PKSA-1', null, null)))[0] ?? null, 'the lists still apply when the audit mode is ignore');
        self::assertNull(self::read(['policy' => false, 'audit' => ['ignore' => ['PKSA-1']]])->match('vendor/pkg', $this->full('PKSA-1', null, null)), 'policy: false gives Composer no list');
        self::assertNull(self::read(['policy' => false])->whyUnreadable());
        self::assertNull(self::read(['policy' => ['abandoned' => false]])->disabledBy(), 'another list off');
    }
}
