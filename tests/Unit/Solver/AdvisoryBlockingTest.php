<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Config;
use Composer\Package\CompletePackage;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Composer\Util\Platform;
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

    protected function tearDown(): void
    {
        Platform::clearEnv('COMPOSER_NO_BLOCKING');
        Platform::clearEnv('COMPOSER_NO_SECURITY_BLOCKING');
    }

    /**
     * @dataProvider variablesThatComposer210Reads
     *
     * @param list<string> $set
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('variablesThatComposer210Reads')]
    public function testOnComposer210EitherVariableTurnsOffTheBlockingOfAdvisoriesAndAbandonedPackages(array $set): void
    {
        if (version_compare(ComposerFeatures::version(), '2.10.0-dev', '<')) {
            self::markTestSkipped('Composer 2.10 and later read COMPOSER_NO_BLOCKING');
        }
        foreach ($set as $name) {
            Platform::putEnv($name, '1');
        }
        $abandoned = new CompletePackage('acme/old', '1.0.0.0', '1.0.0');
        $abandoned->setAbandoned(true);

        $blocking = AdvisoryBlocking::fromConfig(self::config(['block-abandoned' => true]));

        self::assertSame($set, $blocking->disabledBy());
        self::assertFalse($blocking->blocks());
        self::assertSame([], self::ranges($blocking, 'high'));
        self::assertFalse($blocking->blocksAbandoned($abandoned));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function variablesThatComposer210Reads(): iterable
    {
        yield 'COMPOSER_NO_BLOCKING' => [['COMPOSER_NO_BLOCKING']];
        yield 'COMPOSER_NO_SECURITY_BLOCKING' => [['COMPOSER_NO_SECURITY_BLOCKING']];
        yield 'both, in the list order' => [['COMPOSER_NO_BLOCKING', 'COMPOSER_NO_SECURITY_BLOCKING']];
    }

    public function testOnComposer29OnlyComposerNoSecurityBlockingTurnsTheBlockingOff(): void
    {
        if (version_compare(ComposerFeatures::version(), '2.9.2', '<') || version_compare(ComposerFeatures::version(), '2.10.0-dev', '>=')) {
            self::markTestSkipped('Composer 2.9.2 to 2.9.x read COMPOSER_NO_SECURITY_BLOCKING alone');
        }
        Platform::putEnv('COMPOSER_NO_BLOCKING', '1');
        self::assertSame([], AdvisoryBlocking::fromConfig(self::config([]))->disabledBy());
        self::assertTrue(AdvisoryBlocking::fromConfig(self::config([]))->blocks());

        Platform::putEnv('COMPOSER_NO_SECURITY_BLOCKING', '1');
        $blocking = AdvisoryBlocking::fromConfig(self::config([]));

        self::assertSame(['COMPOSER_NO_SECURITY_BLOCKING'], $blocking->disabledBy());
        self::assertFalse($blocking->blocks());
        self::assertSame([], self::ranges($blocking, 'high'));
    }

    /**
     * @dataProvider falseValues
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('falseValues')]
    public function testAVariableWithAFalseValueLeavesTheBlockingOn(string $value): void
    {
        Platform::putEnv('COMPOSER_NO_BLOCKING', $value);
        Platform::putEnv('COMPOSER_NO_SECURITY_BLOCKING', $value);

        $blocking = AdvisoryBlocking::fromConfig(self::config([]));

        self::assertSame([], $blocking->disabledBy());
        self::assertTrue($blocking->blocks());
        self::assertSame(['acme/lib' => ['>=1.0.0,<1.1.0']], self::ranges($blocking, 'high'));
    }

    /** @return iterable<string, array{string}> */
    public static function falseValues(): iterable
    {
        yield 'zero' => ['0'];
        yield 'empty' => [''];
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
