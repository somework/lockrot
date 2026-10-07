<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Abandoned;

use Composer\Config;
use Composer\Policy\AbandonedPolicyConfig;
use Lockrot\Data\Abandoned\AbandonedIgnore;
use Lockrot\Data\Abandoned\AbandonedIgnoreMatch;
use Lockrot\Data\Abandoned\AbandonedPolicyReader;
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

    private static function needsPolicyApi(): void
    {
        if (!class_exists(AbandonedPolicyConfig::class)) {
            self::markTestSkipped('Composer without the policy API (2.10 and later)');
        }
    }

    /** @param array<string, mixed> $config the `config` section of composer.json */
    private static function read(array $config): AbandonedIgnore
    {
        $composer = new Config(false);
        $composer->merge(['config' => $config]);

        return AbandonedIgnore::fromConfig($composer);
    }

    /** @return list<array{pattern: string, reason: ?string, constraints: list<string>}>|null */
    private static function rules(AbandonedIgnore $ignore, string $name): ?array
    {
        $match = $ignore->match($name);

        return $match === null ? null : $match->rules();
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
        self::needsPolicyApi();

        $ignoreList = self::read(['policy' => ['abandoned' => ['ignore' => $ignore]]]);

        self::assertSame($expected, self::rules($ignoreList, $name));
        $match = $ignoreList->match($name);
        self::assertNotNull($match);
        self::assertSame(AbandonedIgnoreMatch::BY_POLICY, $match->by());
        self::assertNull($ignoreList->whyUnreadable());
    }

    public function testARuleOnlyForBlockingIsNotApplied(): void
    {
        self::needsPolicyApi();

        self::assertNull(self::read(['policy' => ['abandoned' => ['ignore' => ['a/pkg' => ['on-audit' => false]]]]])->match('a/pkg'));
    }

    public function testANameOutsideEveryPatternHasNoMatch(): void
    {
        self::needsPolicyApi();
        $ignore = self::read(['policy' => ['abandoned' => ['ignore' => ['a/*' => null, 'b/pkg' => null]]]]);

        self::assertNull($ignore->match('b/other'));
        self::assertNull($ignore->match('ab/pkg'), 'the wildcard stays inside the vendor');
        self::assertNull($ignore->match('b/pkg-extra'), 'the pattern is anchored at the end');
    }

    public function testTheLegacyListIsReadUnder210WithItsOwnSetting(): void
    {
        self::needsPolicyApi();
        $ignore = self::read(['audit' => ['ignore-abandoned' => [
            'a/plain' => 'legacy reason',
            'a/block' => ['apply' => 'block', 'reason' => 'blocking only'],
            'a/audit' => ['apply' => 'audit', 'reason' => 'audit only'],
        ]]]);

        $match = $ignore->match('a/plain');
        self::assertNotNull($match);
        self::assertSame(AbandonedIgnoreMatch::BY_AUDIT, $match->by());
        self::assertSame([['pattern' => 'a/plain', 'reason' => 'legacy reason', 'constraints' => []]], $match->rules());
        self::assertNull($ignore->match('a/block'));
        self::assertSame([['pattern' => 'a/audit', 'reason' => 'audit only', 'constraints' => []]], self::rules($ignore, 'a/audit'));
    }

    public function testThePolicyListWinsOverTheLegacyOne(): void
    {
        self::needsPolicyApi();
        $ignore = self::read([
            'policy' => ['abandoned' => ['ignore' => ['a/policy' => 'policy']]],
            'audit' => ['ignore-abandoned' => ['a/legacy' => 'legacy']],
        ]);

        self::assertSame([['pattern' => 'a/policy', 'reason' => 'policy', 'constraints' => []]], self::rules($ignore, 'a/policy'));
        self::assertNull($ignore->match('a/legacy'));
    }

    public function testADisabledPolicyGivesNoList(): void
    {
        self::needsPolicyApi();
        $legacy = ['audit' => ['ignore-abandoned' => ['a/pkg' => null]]];

        self::assertNull(self::read(['policy' => ['abandoned' => false]] + $legacy)->match('a/pkg'), 'policy.abandoned: false');
        self::assertNull(self::read(['policy' => false] + $legacy)->match('a/pkg'), 'policy: false');
        self::assertNull(self::read(['policy' => false] + $legacy)->whyUnreadable());
    }

    /** The list is read on its own, so another section Composer rejects cannot empty it. */
    public function testABrokenMalwareSectionOrAReservedListNameDoesNotEmptyTheList(): void
    {
        self::needsPolicyApi();
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
        self::needsPolicyApi();

        $ignore = self::read(['policy' => ['abandoned' => ['ignore' => ['a/pkg' => 42]]]]);

        self::assertNull($ignore->match('a/pkg'));
        $why = $ignore->whyUnreadable();
        self::assertNotNull($why);
        self::assertStringContainsString('a/pkg', $why);
        self::assertStringNotContainsString("\n", $why);
    }

    public function testABadConstraintMakesTheListUnreadable(): void
    {
        self::needsPolicyApi();

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
        self::needsPolicyApi();

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
        self::needsPolicyApi();
        putenv('COMPOSER_POLICY=0');

        $ignore = self::read(['policy' => ['abandoned' => ['ignore' => ['a/pkg' => null]]]]);

        self::assertNull($ignore->match('a/pkg'));
        self::assertNull($ignore->whyUnreadable());
    }

    public function testAnUnreadableEnvironmentMakesTheListUnreadable(): void
    {
        self::needsPolicyApi();
        putenv('COMPOSER_POLICY=maybe');

        $ignore = self::read(['policy' => ['abandoned' => ['ignore' => ['a/pkg' => null]]]]);

        self::assertNull($ignore->match('a/pkg'));
        self::assertNotNull($ignore->whyUnreadable());
    }

    /**
     * @param AbandonedPolicyReader::POLICY|AbandonedPolicyReader::AUDIT_CONFIG|AbandonedPolicyReader::NONE $api
     * @param array<mixed>|\Throwable                                                                     $list what Composer 2.9's AuditConfig holds
     */
    private static function reader(string $api, $list = []): AbandonedPolicyReader
    {
        return new class ($api, $list) implements AbandonedPolicyReader {
            /** @var AbandonedPolicyReader::POLICY|AbandonedPolicyReader::AUDIT_CONFIG|AbandonedPolicyReader::NONE */
            private string $api;
            /** @var array<mixed>|\Throwable */
            private $list;

            /**
             * @param AbandonedPolicyReader::POLICY|AbandonedPolicyReader::AUDIT_CONFIG|AbandonedPolicyReader::NONE $api
             * @param array<mixed>|\Throwable $list
             */
            public function __construct(string $api, $list)
            {
                $this->api = $api;
                $this->list = $list;
            }

            public function api(): string
            {
                return $this->api;
            }

            public function policyRules(array $policy, array $audit): array
            {
                throw new \LogicException('not the policy API');
            }

            public function auditConfigList(Config $config): array
            {
                if ($this->list instanceof \Throwable) {
                    throw $this->list;
                }

                return $this->list;
            }
        };
    }

    public function testComposer29ReadsAListsValues(): void
    {
        $ignore = AbandonedIgnore::fromConfig(new Config(false), self::reader(AbandonedPolicyReader::AUDIT_CONFIG, ['a/pkg', 'b/*']));

        $match = $ignore->match('b/other');
        self::assertNotNull($match);
        self::assertSame(AbandonedIgnoreMatch::BY_AUDIT, $match->by());
        self::assertSame([['pattern' => 'b/*', 'reason' => null, 'constraints' => []]], $match->rules());
        self::assertSame([['pattern' => 'a/pkg', 'reason' => null, 'constraints' => []]], self::rules($ignore, 'a/pkg'));
        self::assertNull($ignore->match('c/pkg'));
    }

    public function testComposer29ReadsAMapsKeysWithTheirReasons(): void
    {
        $ignore = AbandonedIgnore::fromConfig(new Config(false), self::reader(AbandonedPolicyReader::AUDIT_CONFIG, ['a/pkg' => 'why', 'b/pkg' => null]));

        self::assertSame([['pattern' => 'a/pkg', 'reason' => 'why', 'constraints' => []]], self::rules($ignore, 'a/pkg'));
        self::assertSame([['pattern' => 'b/pkg', 'reason' => null, 'constraints' => []]], self::rules($ignore, 'b/pkg'));
        self::assertNull($ignore->match('why'), 'a reason is not a pattern');

        $numeric = AbandonedIgnore::fromConfig(new Config(false), self::reader(AbandonedPolicyReader::AUDIT_CONFIG, ['123' => null]));
        self::assertSame([['pattern' => '123', 'reason' => null, 'constraints' => []]], self::rules($numeric, '123'), 'PHP turns the key into an integer: the pattern stays a string');
    }

    public function testComposer29RejectingTheListGivesNoListAndWhy(): void
    {
        $ignore = AbandonedIgnore::fromConfig(new Config(false), self::reader(AbandonedPolicyReader::AUDIT_CONFIG, new \InvalidArgumentException("Invalid 'apply' value\nmore")));

        self::assertNull($ignore->match('a/pkg'));
        self::assertSame("Invalid 'apply' value", $ignore->whyUnreadable());
    }

    public function testComposerBelow29HasNoList(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['audit' => ['ignore-abandoned' => ['a/pkg']]]]);

        $ignore = AbandonedIgnore::fromConfig($config, self::reader(AbandonedPolicyReader::NONE, ['a/pkg']));

        self::assertNull($ignore->match('a/pkg'));
        self::assertNull($ignore->whyUnreadable());
    }

    public function testNoneMatchesNothing(): void
    {
        self::assertNull(AbandonedIgnore::none()->match('a/pkg'));
        self::assertNull(AbandonedIgnore::none()->whyUnreadable());
    }
}
