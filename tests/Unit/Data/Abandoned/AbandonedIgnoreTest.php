<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Abandoned;

use Composer\Config;
use Composer\Util\Platform;
use Lockrot\Data\Abandoned\AbandonedIgnore;
use Lockrot\Data\Abandoned\AbandonedIgnoreMatch;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AbandonedIgnoreTest extends TestCase
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

    /** @param array<string, mixed> $config the `config` section of composer.json */
    private static function read(array $config): AbandonedIgnore
    {
        $composer = new Config(false);
        $composer->merge(['config' => $config]);

        return AbandonedIgnore::fromConfig($composer);
    }

    /** @return array<mixed>|null each matching rule as the report writes it */
    private static function rules(AbandonedIgnore $ignore, string $name): ?array
    {
        $match = $ignore->match($name);

        return $match === null ? null : JsonPath::decoded($match->rules());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, list<array{pattern: string, reason: ?string, constraints: list<string>}>}>
     */
    public static function policyShapes(): iterable
    {
        yield 'null' => [['a/pkg' => null], 'a/pkg', [['pattern' => 'a/pkg', 'reason' => null, 'constraints' => []]]];
        yield 'a reason' => [['a/pkg' => 'migration planned'], 'a/pkg', [['pattern' => 'a/pkg', 'reason' => 'migration planned', 'constraints' => []]]];
        yield 'a rule object' => [['a/pkg' => ['constraint' => '^1.0', 'reason' => 'only 1.x']], 'a/pkg', [['pattern' => 'a/pkg', 'reason' => 'only 1.x', 'constraints' => ['^1.0']]]];
        yield 'a list of rule objects, reasons merged' => [
            ['a/pkg' => [['constraint' => '^1.0', 'reason' => 'a'], ['constraint' => '^2.0', 'reason' => 'b']]],
            'a/pkg',
            [['pattern' => 'a/pkg', 'reason' => 'a; b', 'constraints' => ['^1.0', '^2.0']]],
        ];
        yield 'a wildcard' => [['a/*' => 'the whole vendor'], 'a/pkg', [['pattern' => 'a/*', 'reason' => 'the whole vendor', 'constraints' => []]]];
        yield 'a name in another case' => [['A/Pkg' => null], 'a/pkg', [['pattern' => 'A/Pkg', 'reason' => null, 'constraints' => []]]];
        yield 'two patterns, in config order, each with its reason' => [
            ['a/*' => 'vendor', 'a/pkg' => 'package'],
            'a/pkg',
            [['pattern' => 'a/*', 'reason' => 'vendor', 'constraints' => []], ['pattern' => 'a/pkg', 'reason' => 'package', 'constraints' => []]],
        ];
        yield 'an on-audit: false rule beside an audit rule' => [
            ['a/pkg' => [['constraint' => '^1.0', 'reason' => 'block only', 'on-audit' => false], ['reason' => 'audit']]],
            'a/pkg',
            [['pattern' => 'a/pkg', 'reason' => 'audit', 'constraints' => []]],
        ];
    }

    /**
     * @param array<string, mixed>                                                    $ignore
     * @param list<array{pattern: string, reason: ?string, constraints: list<string>}> $expected
     *
     * @dataProvider policyShapes
     */
    #[DataProvider('policyShapes')]
    public function testEveryPolicyShapeGivesItsPatternReasonAndConstraints(array $ignore, string $name, array $expected): void
    {

        $ignoreList = self::read(['policy' => ['abandoned' => ['ignore' => $ignore]]]);

        self::assertSame($expected, self::rules($ignoreList, $name));
        $match = $ignoreList->match($name);
        self::assertNotNull($match);
        self::assertSame(AbandonedIgnoreMatch::BY_POLICY, $match->by());
        self::assertNull($ignoreList->whyUnreadable());
    }

    /** @return iterable<string, array{array<string, mixed>, ?array{policy_key: string, value: bool|string}}> */
    public static function auditSettings(): iterable
    {
        yield 'no setting' => [[], null];
        yield 'the policy off' => [['policy' => false], ['policy_key' => 'policy', 'value' => false]];
        yield 'the abandoned policy off' => [['policy' => ['abandoned' => false]], ['policy_key' => 'policy.abandoned', 'value' => false]];
        yield 'the abandoned audit ignored' => [['policy' => ['abandoned' => ['audit' => 'ignore']]], ['policy_key' => 'policy.abandoned.audit', 'value' => 'ignore']];
        yield 'the abandoned audit failing' => [['policy' => ['abandoned' => ['audit' => 'fail']]], null];
        yield 'the legacy audit ignored' => [['audit' => ['abandoned' => 'ignore']], ['policy_key' => 'audit.abandoned', 'value' => 'ignore']];
        yield 'the legacy audit reported' => [['audit' => ['abandoned' => 'report']], null];
    }

    /**
     * @param array<string, mixed>                                  $config
     * @param ?array{policy_key: string, value: bool|string}        $expected
     *
     * @dataProvider auditSettings
     */
    #[DataProvider('auditSettings')]
    public function testTheSettingThatStopsTheAbandonedAuditIsNamed(array $config, ?array $expected): void
    {
        $off = self::read($config)->notAuditedBy();
        self::assertSame($expected, $off === null ? null : ['policy_key' => $off->policyKey(), 'value' => $off->value()]);
    }

    public function testComposerPolicyZeroNamesTheVariable(): void
    {
        Platform::putEnv('COMPOSER_POLICY', '0');
        try {
            $off = self::read([])->notAuditedBy();
            self::assertNotNull($off);
            self::assertSame(['COMPOSER_POLICY', false], [$off->policyKey(), $off->value()]);
        } finally {
            Platform::clearEnv('COMPOSER_POLICY');
        }
    }

    /** Composer's PolicyConfig returns early on `policy: false`, before it reads the audit variable. */
    public function testThePolicyOffWinsOverTheAuditVariable(): void
    {
        Platform::putEnv('COMPOSER_AUDIT_ABANDONED', 'report');
        try {
            $off = self::read(['policy' => false])->notAuditedBy();
            self::assertNotNull($off);
            self::assertSame(['policy', false], [$off->policyKey(), $off->value()]);
        } finally {
            Platform::clearEnv('COMPOSER_AUDIT_ABANDONED');
        }
    }

    public function testTheEnvironmentVariableWinsOverTheConfig(): void
    {
        Platform::putEnv('COMPOSER_AUDIT_ABANDONED', 'ignore');
        try {
            $off = self::read(['policy' => ['abandoned' => ['audit' => 'fail']]])->notAuditedBy();
            self::assertNotNull($off);
            self::assertSame(['COMPOSER_AUDIT_ABANDONED', 'ignore'], [$off->policyKey(), $off->value()]);
        } finally {
            Platform::clearEnv('COMPOSER_AUDIT_ABANDONED');
        }
    }

    public function testARuleOnlyForBlockingIsNotApplied(): void
    {

        self::assertNull(self::read(['policy' => ['abandoned' => ['ignore' => ['a/pkg' => ['on-audit' => false]]]]])->match('a/pkg'));
    }

    public function testANameOutsideEveryPatternHasNoMatch(): void
    {
        $ignore = self::read(['policy' => ['abandoned' => ['ignore' => ['a/*' => null, 'b/pkg' => null]]]]);

        self::assertNull($ignore->match('b/other'));
        self::assertNull($ignore->match('ab/pkg'), 'the wildcard stays inside the vendor');
        self::assertNull($ignore->match('b/pkg-extra'), 'the pattern is anchored at the end');
    }

    public function testTheLegacyListIsReadUnder210WithItsOwnSetting(): void
    {
        $ignore = self::read(['audit' => ['ignore-abandoned' => [
            'a/plain' => 'legacy reason',
            'a/block' => ['apply' => 'block', 'reason' => 'blocking only'],
            'a/audit' => ['apply' => 'audit', 'reason' => 'audit only'],
        ]]]);

        $match = $ignore->match('a/plain');
        self::assertNotNull($match);
        self::assertSame(AbandonedIgnoreMatch::BY_AUDIT, $match->by());
        self::assertSame([['pattern' => 'a/plain', 'reason' => 'legacy reason', 'constraints' => []]], JsonPath::decoded($match->rules()));
        self::assertNull($ignore->match('a/block'));
        self::assertSame([['pattern' => 'a/audit', 'reason' => 'audit only', 'constraints' => []]], self::rules($ignore, 'a/audit'));
    }

    public function testThePolicyListWinsOverTheLegacyOne(): void
    {
        $ignore = self::read([
            'policy' => ['abandoned' => ['ignore' => ['a/policy' => 'policy']]],
            'audit' => ['ignore-abandoned' => ['a/legacy' => 'legacy']],
        ]);

        self::assertSame([['pattern' => 'a/policy', 'reason' => 'policy', 'constraints' => []]], self::rules($ignore, 'a/policy'));
        self::assertNull($ignore->match('a/legacy'));
    }

    public function testADisabledPolicyGivesNoList(): void
    {
        $legacy = ['audit' => ['ignore-abandoned' => ['a/pkg' => null]]];

        self::assertNull(self::read(['policy' => ['abandoned' => false]] + $legacy)->match('a/pkg'), 'policy.abandoned: false');
        self::assertNull(self::read(['policy' => false] + $legacy)->match('a/pkg'), 'policy: false');
        self::assertNull(self::read(['policy' => false] + $legacy)->whyUnreadable());
    }

    /** The list is read on its own, so another section Composer rejects cannot empty it. */
    public function testABrokenMalwareSectionOrAReservedListNameDoesNotEmptyTheList(): void
    {
        foreach ([
            'a broken malware section' => ['malware' => ['ignore' => ['vendor/x' => 42]]],
            'a reserved custom list name' => ['licenses' => ['allow' => ['MIT']]],
        ] as $label => $other) {
            $ignore = self::read(['policy' => $other + ['abandoned' => ['ignore' => ['a/pkg' => 'why']]]]);

            self::assertNull($ignore->whyUnreadable(), $label);
            self::assertSame([['pattern' => 'a/pkg', 'reason' => 'why', 'constraints' => []]], self::rules($ignore, 'a/pkg'), $label);
        }
    }

    public function testAListComposerRejectsGivesNoListAndTheFirstLineOfWhy(): void
    {

        $ignore = self::read(['policy' => ['abandoned' => ['ignore' => ['a/pkg' => 42]]]]);

        self::assertNull($ignore->match('a/pkg'));
        $why = $ignore->whyUnreadable();
        self::assertNotNull($why);
        self::assertStringContainsString('a/pkg', $why);
        self::assertStringNotContainsString("\n", $why);
    }

    public function testABadConstraintMakesTheListUnreadable(): void
    {

        $ignore = self::read(['policy' => ['abandoned' => ['ignore' => ['a/pkg' => ['constraint' => 'not a constraint']]]]]);

        self::assertNull($ignore->match('a/pkg'));
        self::assertNotNull($ignore->whyUnreadable());
    }

    /**
     * Composer's default `policy` is `true`, and the policy API types it `array`: lockrot must
     * normalise it as Composer does, or every default run loses the list.
     */
    public function testARealComposerConfigWithoutAPolicyKeyReadsTheLegacyList(): void
    {

        $none = AbandonedIgnore::fromConfig(new Config(false));
        self::assertNull($none->whyUnreadable(), 'no policy key');
        self::assertNull($none->match('a/pkg'));

        $policyTrue = self::read(['policy' => true, 'audit' => ['ignore-abandoned' => ['a/pkg' => 'why']]]);
        self::assertNull($policyTrue->whyUnreadable(), 'policy: true');
        self::assertSame([['pattern' => 'a/pkg', 'reason' => 'why', 'constraints' => []]], self::rules($policyTrue, 'a/pkg'));

        $legacyOnly = self::read(['audit' => ['ignore-abandoned' => ['a/pkg']]]);
        self::assertNull($legacyOnly->whyUnreadable(), 'only audit.ignore-abandoned');
        self::assertSame([['pattern' => 'a/pkg', 'reason' => null, 'constraints' => []]], self::rules($legacyOnly, 'a/pkg'));
    }

    public function testComposerPolicyZeroInTheEnvironmentGivesNoList(): void
    {
        putenv('COMPOSER_POLICY=0');

        $ignore = self::read(['policy' => ['abandoned' => ['ignore' => ['a/pkg' => null]]]]);

        self::assertNull($ignore->match('a/pkg'));
        self::assertNull($ignore->whyUnreadable());
    }

    public function testAnUnreadableEnvironmentMakesTheListUnreadable(): void
    {
        putenv('COMPOSER_POLICY=maybe');

        $ignore = self::read(['policy' => ['abandoned' => ['ignore' => ['a/pkg' => null]]]]);

        self::assertNull($ignore->match('a/pkg'));
        self::assertNotNull($ignore->whyUnreadable());
    }

    public function testNoneMatchesNothing(): void
    {
        self::assertNull(AbandonedIgnore::none()->match('a/pkg'));
        self::assertNull(AbandonedIgnore::none()->whyUnreadable());
    }
}
