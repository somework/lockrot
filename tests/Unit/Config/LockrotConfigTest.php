<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Config;

use Lockrot\Analyzer\RunSettings;
use Lockrot\Config\LockrotConfig;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Exception\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LockrotConfigTest extends TestCase
{
    /**
     * A composer.json can omit its own name, and where it has one, the name is not always the one a
     * reader knows the project by: a package inside a monorepo names itself after the package, a
     * private project often after the client. Read from the manifest rather than a flag, because it
     * describes the project.
     */
    public function testTheProjectCanBeCalledSomethingOtherThanItsManifestName(): void
    {
        self::assertSame('Acme internal API', LockrotConfig::fromSources(['project' => 'Acme internal API'], [], [], '8.5.10', null)->project());
        self::assertNull(LockrotConfig::fromSources([], [], [], '8.5.10', null)->project(), 'saying nothing leaves the manifest to answer');
        self::assertNull(LockrotConfig::fromSources(['project' => ''], [], [], '8.5.10', null)->project());
        self::assertNull(LockrotConfig::fromSources(['project' => ['a']], [], [], '8.5.10', null)->project(), 'a name is a string or it is nothing');
    }

    public function testDefaults(): void
    {
        $cfg = LockrotConfig::fromSources([], [], [], '8.5.10', null);
        self::assertSame('none', $cfg->failOn()->value());
        self::assertSame('8.5', $cfg->targetPhp());
        self::assertFalse($cfg->includeDev());
        // A source that names neither flag leaves both off: install time passes an empty $cli, so a
        // default of "offline" silences every install-time repository lookup, and a default of
        // "strict-network" fails an install on the first unreachable repository.
        self::assertFalse($cfg->offline());
        self::assertFalse($cfg->strictNetwork());
        self::assertSame('table', $cfg->format());
        self::assertFalse($cfg->isDisabled());
        self::assertSame(3, $cfg->thresholds()->releaseWarnYears());
        self::assertTrue($cfg->installTime());
        self::assertFalse($cfg->installTimeStrict());
        self::assertSame(5, $cfg->installTimeBudgetSeconds());
        self::assertNull($cfg->baseline());
    }

    public function testTheAdvisoryLookupScopeDefaultsToAllAndNamesItsSource(): void
    {
        $default = LockrotConfig::fromSources([], [], [], '8.5.10', null);
        self::assertSame([AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_DEFAULT], [$default->advisoryLookup(), $default->advisoryLookupSource()]);

        $all = LockrotConfig::fromSources(['advisory-lookup' => 'all'], [], [], '8.5.10', null);
        self::assertSame([AdvisoryCoverage::SCOPE_ALL, AdvisoryCoverage::SOURCE_CONFIG], [$all->advisoryLookup(), $all->advisoryLookupSource()], 'the default value, written: from the config');

        $scoped = LockrotConfig::fromSources(['advisory-lookup' => 'composer-repositories'], [], [], '8.5.10', null);
        self::assertSame([AdvisoryCoverage::SCOPE_COMPOSER_REPOSITORIES, AdvisoryCoverage::SOURCE_CONFIG], [$scoped->advisoryLookup(), $scoped->advisoryLookupSource()]);
    }

    /**
     * @param mixed $value
     *
     * @dataProvider invalidAdvisoryLookups
     */
    #[DataProvider('invalidAdvisoryLookups')]
    public function testAnAdvisoryLookupOutsideBothValuesNamesThem($value, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);
        LockrotConfig::fromSources(['advisory-lookup' => $value], [], [], '8.5.10', null);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidAdvisoryLookups(): iterable
    {
        yield 'the underscore spelling' => ['composer_repositories', 'advisory-lookup must be one of all, composer-repositories; got "composer_repositories"'];
        yield 'an empty string' => ['', 'advisory-lookup must be one of all, composer-repositories; got ""'];
        yield 'not a string' => [true, 'advisory-lookup must be one of all, composer-repositories; got true'];
    }

    public function testBaselinePathFromExtraAndCliWithCliWinning(): void
    {
        self::assertSame(
            'ci/rot.json',
            LockrotConfig::fromSources(['baseline' => 'ci/rot.json'], [], [], '8.5.10', null)->baseline()
        );
        self::assertSame(
            'from-cli.json',
            LockrotConfig::fromSources(['baseline' => 'ci/rot.json'], [], ['baseline' => 'from-cli.json'], '8.5.10', null)->baseline()
        );
        self::assertNull(LockrotConfig::fromSources(['baseline' => ''], [], ['baseline' => null], '8.5.10', null)->baseline());
    }

    /**
     * `--baseline=` reaches here as an empty string. A fall-through to the default path gates
     * silently against a different file than the one the caller named.
     */
    public function testAnEmptyCliBaselineIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('--baseline must not be empty');
        LockrotConfig::fromSources([], [], ['baseline' => ''], '8.5.10', null);
    }

    public function testInstallTimeBudgetFromExtra(): void
    {
        self::assertSame(30, LockrotConfig::fromSources(['install-time-budget' => 30], [], [], '8.5.10', null)->installTimeBudgetSeconds());
    }

    /** {@see invalidInstallTimeBudgets} covers the values outside the range. */
    public function testTheInstallTimeBudgetRangeIsInclusiveAtBothEnds(): void
    {
        self::assertSame(1, LockrotConfig::fromSources(['install-time-budget' => 1], [], [], '8.5.10', null)->installTimeBudgetSeconds());
        self::assertSame(120, LockrotConfig::fromSources(['install-time-budget' => 120], [], [], '8.5.10', null)->installTimeBudgetSeconds());
    }

    /**
     * @param mixed $value
     *
     * @dataProvider invalidInstallTimeBudgets
     */
    #[DataProvider('invalidInstallTimeBudgets')]
    public function testInvalidInstallTimeBudget($value): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/install-time-budget must be an integer between 1 and 120/');
        LockrotConfig::fromSources(['install-time-budget' => $value], [], [], '8.5.10', null);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidInstallTimeBudgets(): iterable
    {
        yield 'below minimum' => [0];
        yield 'above maximum' => [121];
        yield 'digit string' => ['5'];
        yield 'float' => [5.5];
        yield 'boolean' => [true];
    }

    public function testInstallTimeCanBeTurnedOff(): void
    {
        self::assertFalse(LockrotConfig::fromSources(['install-time' => 'off'], [], [], '8.5.10', null)->installTime());
        self::assertTrue(LockrotConfig::fromSources(['install-time' => 'on'], [], [], '8.5.10', null)->installTime());
    }

    public function testInstallTimeStrictComesFromExtraOnly(): void
    {
        self::assertTrue(LockrotConfig::fromSources(['install-time-strict' => true], [], [], '8.5.10', null)->installTimeStrict());
        self::assertFalse(LockrotConfig::fromSources(['install-time-strict' => false], [], [], '8.5.10', null)->installTimeStrict());
    }

    public function testInvalidInstallTime(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('install-time must be one of on, off; got "summary"');
        LockrotConfig::fromSources(['install-time' => 'summary'], [], [], '8.5.10', null);
    }

    public function testAFailOnListFromTheConfigIsReadAndAnEmptyOneIsTheDefault(): void
    {
        self::assertSame('left-behind,vulnerable-high', LockrotConfig::fromSources(['fail-on' => ['vulnerable-high', 'left-behind']], [], [], '8.5.10', null)->failOn()->value());
        self::assertSame(LockrotConfig::fromSources([], [], [], '8.5.10', null)->failOn()->value(), LockrotConfig::fromSources(['fail-on' => []], [], [], '8.5.10', null)->failOn()->value());
    }

    public function testAFailOnListWithAnItemThatIsNoStringIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('extra.lockrot.fail-on: every item of the list must be a string');
        LockrotConfig::fromSources(['fail-on' => ['high', 5]], [], [], '8.5.10', null);
    }

    public function testPlatformPhpBeatsRuntime(): void
    {
        self::assertSame('8.1', LockrotConfig::fromSources([], [], [], '8.5.10', '8.1.0')->targetPhp());
    }

    /** Composer reads `v8.4.1` and `V8.4` as 8.4 releases, so the default target reads them too. */
    public function testAVPrefixedPlatformPhpSetsTheDefaultTargetMinor(): void
    {
        self::assertSame('8.4', LockrotConfig::fromSources([], [], [], '8.5.10', 'v8.4.1')->targetPhp());
        self::assertSame('8.4', LockrotConfig::fromSources([], [], [], '8.5.10', 'V8.4')->targetPhp());
        self::assertSame(RunSettings::SOURCE_PLATFORM, LockrotConfig::fromSources([], [], [], '8.5.10', 'v8.4.1')->targetPhpSource());
    }

    /**
     * Ruling R15-3: only the option, the variable and the config key refuse an unknown minor. A
     * `config.platform.php` is Composer's own setting, so the run takes its minor without a refusal.
     *
     * @dataProvider platformPhpsInAnUnlistedMinor
     */
    #[DataProvider('platformPhpsInAnUnlistedMinor')]
    public function testAPlatformPhpInAnUnlistedMinorSetsTheTargetWithoutARefusal(string $platformPhp, string $minor): void
    {
        $config = LockrotConfig::fromSources([], ['LOCKROT_TODAY' => '2019-06-01'], [], '8.5.10', $platformPhp);

        self::assertSame($minor, $config->targetPhp());
        self::assertSame(RunSettings::SOURCE_PLATFORM, $config->targetPhpSource());
    }

    /** @return iterable<string, array{string, string}> */
    public static function platformPhpsInAnUnlistedMinor(): iterable
    {
        yield 'below the first known minor' => ['5.2.17', '5.2'];
        yield 'between two known minors' => ['7.5.0', '7.5'];
    }

    /**
     * Composer's comparator reads `8.04.1` as 8.4.1, so the target is the minor 8.4 and no second,
     * adjacent minor 8.04 reaches the PHP window.
     *
     * @dataProvider platformPhpsWithALeadingZeroSegment
     */
    #[DataProvider('platformPhpsWithALeadingZeroSegment')]
    public function testAPlatformPhpWithALeadingZeroSegmentReadsAsComposerReadsIt(string $platformPhp, string $minor): void
    {
        self::assertSame($minor, LockrotConfig::fromSources([], [], [], '8.5.10', $platformPhp)->targetPhp());
    }

    /** @return iterable<string, array{string, string}> */
    public static function platformPhpsWithALeadingZeroSegment(): iterable
    {
        yield 'a zero in the minor' => ['8.04.1', '8.4'];
        yield 'a zero in the major' => ['08.4.1', '8.4'];
        yield 'a zero minor stays' => ['8.0.1', '8.0'];
        yield 'a double zero minor' => ['8.00.1', '8.0'];
    }

    /**
     * Ruling R8-37: Composer refuses such a `config.platform.php`, so the run continues at the
     * running PHP and the move check reads `platform_config_invalid`.
     *
     * @dataProvider platformPhpsThatComposerRefuses
     */
    #[DataProvider('platformPhpsThatComposerRefuses')]
    public function testAPlatformPhpThatComposerRefusesLeavesTheDefaultTargetToTheRunningPhp(string $platformPhp): void
    {
        $config = LockrotConfig::fromSources([], [], [], '8.5.10', $platformPhp);

        self::assertSame(['8.5', RunSettings::SOURCE_RUNTIME], [$config->targetPhp(), $config->targetPhpSource()]);
    }

    /** @return iterable<string, array{string}> */
    public static function platformPhpsThatComposerRefuses(): iterable
    {
        yield 'a constraint' => ['^8.2'];
        yield 'a word' => ['eight'];
    }

    /** Composer's version parser keeps a `dev-` branch name, so a platform php can reach the target as no version. */
    public function testAPlatformPhpThatIsABranchNameIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('target-php must look like "8.4"; got "dev-main"');
        LockrotConfig::fromSources([], [], [], '8.5.10', 'dev-main');
    }

    public function testAVPrefixedTargetPhpOptionIsStillRefused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('--target-php takes a major, a minor or a patch version, such as "8.4" or "8.4.2", with nothing before or after it; got "v8.4"');
        LockrotConfig::fromSources([], [], ['target-php' => 'v8.4'], '8.5.10', 'v8.4.1');
    }

    /** @dataProvider targetPhpsWithTrailingText */
    #[DataProvider('targetPhpsWithTrailingText')]
    public function testATargetPhpOptionWithTextAfterTheVersionIsRefused(string $value): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('--target-php takes a major, a minor or a patch version, such as "8.4" or "8.4.2", with nothing before or after it; got "'.$value.'"');
        LockrotConfig::fromSources([], [], ['target-php' => $value], '8.5.10', null);
    }

    /** @dataProvider targetPhpsWithTrailingText */
    #[DataProvider('targetPhpsWithTrailingText')]
    public function testALockrotTargetPhpWithTextAfterTheVersionIsRefused(string $value): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('LOCKROT_TARGET_PHP takes a major, a minor or a patch version, such as "8.4" or "8.4.2", with nothing before or after it; got "'.$value.'"');
        LockrotConfig::fromSources([], ['LOCKROT_TARGET_PHP' => $value], [], '8.5.10', null);
    }

    /** @return iterable<string, array{string}> */
    public static function targetPhpsWithTrailingText(): iterable
    {
        yield 'letters' => ['8.4foo'];
        yield 'a constraint' => ['8.4 || 8.5'];
        yield 'an image tag' => ['8.4-fpm'];
        yield 'a wildcard' => ['8.4.*'];
        yield 'a distribution build' => ['8.1.2-1ubuntu2.14'];
        yield 'a fourth part' => ['8.4.1.0'];
        yield 'a trailing newline' => ["8.4\n"];
    }

    /** @dataProvider targetPhpsWithTrailingText */
    #[DataProvider('targetPhpsWithTrailingText')]
    public function testAConfigTargetPhpWithTextAfterTheVersionIsRefusedLikeTheOption(string $value): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('extra.lockrot.target-php takes a major, a minor or a patch version, such as "8.4" or "8.4.2", with nothing before or after it; got "'.$value.'"');
        LockrotConfig::fromSources(['target-php' => $value], [], ['target-php' => '8.4'], '8.5.10', null);
    }

    /**
     * Ruling R14-2: a target minor that the PHP release data does not list and that lies below its
     * newest minor sits on no window minor. `6` reads as 6.0, and `7.5.3` as 7.5. The data, not the
     * clock, sets the known minors, so the refusal holds on every date.
     *
     * @dataProvider targetPhpsThatAreNoPhpMinor
     *
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $env
     * @param array<string, mixed> $cli
     */
    #[DataProvider('targetPhpsThatAreNoPhpMinor')]
    public function testATargetPhpThatIsNoKnownPhpMinorIsRefused(array $extra, array $env, array $cli, string $setting, string $minor, string $value): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($setting.' names PHP '.$minor.', which is not a PHP minor that lockrot knows. The known minors are 5.3, 5.4, 5.5, 5.6, 7.0, 7.1, 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 and 8.6, and lockrot accepts a minor above 8.6. Got "'.$value.'"');
        LockrotConfig::fromSources($extra, ['LOCKROT_TODAY' => '2019-06-01'] + $env, $cli, '8.5.10', null);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, array<string, mixed>, string, string, string}> */
    public static function targetPhpsThatAreNoPhpMinor(): iterable
    {
        yield 'the option at 7.5' => [[], [], ['target-php' => '7.5'], '--target-php', '7.5', '7.5'];
        yield 'the option at a 7.5 patch' => [[], [], ['target-php' => '7.5.3'], '--target-php', '7.5', '7.5.3'];
        yield 'the option at 6.0' => [[], [], ['target-php' => '6.0'], '--target-php', '6.0', '6.0'];
        yield 'the option at the major 6' => [[], [], ['target-php' => '6'], '--target-php', '6.0', '6'];
        yield 'the option below the first known minor' => [[], [], ['target-php' => '5.2'], '--target-php', '5.2', '5.2'];
        yield 'the option at a PHP 4 minor' => [[], [], ['target-php' => '4.4'], '--target-php', '4.4', '4.4'];
        yield 'the option at a minor above the newest one that the clock dates' => [[], [], ['target-php' => '7.9'], '--target-php', '7.9', '7.9'];
        yield 'the variable at 7.5' => [[], ['LOCKROT_TARGET_PHP' => '7.5'], [], 'LOCKROT_TARGET_PHP', '7.5', '7.5'];
        yield 'the variable under an option that wins' => [[], ['LOCKROT_TARGET_PHP' => '6.0'], ['target-php' => '8.4'], 'LOCKROT_TARGET_PHP', '6.0', '6.0'];
        yield 'the config key at 6.0' => [['target-php' => '6.0'], [], [], 'extra.lockrot.target-php', '6.0', '6.0'];
        yield 'the config key at 7.5 under an option that wins' => [['target-php' => '7.5'], [], ['target-php' => '8.4'], 'extra.lockrot.target-php', '7.5', '7.5'];
        yield 'the config key at 7.5 under a variable that wins' => [['target-php' => '7.5'], ['LOCKROT_TARGET_PHP' => '8.4'], [], 'extra.lockrot.target-php', '7.5', '7.5'];
    }

    /**
     * Finding 12: a config value that is no version is refused, also when an option or a variable
     * sets the target.
     *
     * @dataProvider configTargetPhpsThatAreNoVersion
     *
     * @param array<string, mixed> $env
     * @param array<string, mixed> $cli
     */
    #[DataProvider('configTargetPhpsThatAreNoVersion')]
    public function testAConfigTargetPhpThatIsNoVersionIsRefusedUnderEverySource(array $env, array $cli): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('target-php must look like "8.4"; got "banana"');
        LockrotConfig::fromSources(['target-php' => 'banana'], $env, $cli, '8.5.10', null);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function configTargetPhpsThatAreNoVersion(): iterable
    {
        yield 'the config key alone' => [[], []];
        yield 'under an option that wins' => [[], ['target-php' => '8.4']];
        yield 'under a variable that wins' => [['LOCKROT_TARGET_PHP' => '8.3'], []];
    }

    /**
     * Ruling R12-4 keeps a minor above the newest known one: a PHP that the release data does not
     * list yet. The check reads no clock, so a LOCKROT_TODAY that is no date does not stop it.
     *
     * @dataProvider targetPhpsThatAreKnownOrNewer
     */
    #[DataProvider('targetPhpsThatAreKnownOrNewer')]
    public function testATargetPhpThatIsAKnownOrANewerMinorIsAccepted(string $value, string $minor): void
    {
        $env = ['LOCKROT_TODAY' => 'not a date'];

        self::assertSame(
            [$minor, $minor, $minor],
            [
                LockrotConfig::fromSources([], $env, ['target-php' => $value], '8.5.10', null)->targetPhp(),
                LockrotConfig::fromSources([], $env + ['LOCKROT_TARGET_PHP' => $value], [], '8.5.10', null)->targetPhp(),
                LockrotConfig::fromSources(['target-php' => $value], $env, [], '8.5.10', null)->targetPhp(),
            ]
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function targetPhpsThatAreKnownOrNewer(): iterable
    {
        yield 'the first known minor' => ['5.3', '5.3'];
        yield 'a patch of a known minor' => ['8.5.2', '8.5'];
        yield 'the newest minor that the data lists, dated after the run' => ['8.6', '8.6'];
        yield 'a minor above the newest known one' => ['8.9', '8.9'];
        yield 'a major above the newest known minor' => ['9', '9.0'];
    }

    public function testATargetPhpOptionOrVariableReadsTheMinorOfAMajorMinorOrPatch(): void
    {
        self::assertSame(
            ['8.0', '8.4', '8.4', '8.3'],
            [
                LockrotConfig::fromSources([], [], ['target-php' => '8'], '8.5.10', null)->targetPhp(),
                LockrotConfig::fromSources([], [], ['target-php' => '8.4'], '8.5.10', null)->targetPhp(),
                LockrotConfig::fromSources([], [], ['target-php' => '8.4.1'], '8.5.10', null)->targetPhp(),
                LockrotConfig::fromSources([], ['LOCKROT_TARGET_PHP' => '8.3.12'], [], '8.5.10', null)->targetPhp(),
            ]
        );
    }

    /** PHP_VERSION of a distribution build carries the distribution's suffix. */
    public function testTheRunningPhpOfADistributionBuildSetsTheDefaultTargetMinor(): void
    {
        self::assertSame('8.1', LockrotConfig::fromSources([], [], [], '8.1.2-1ubuntu2.14', null)->targetPhp());
    }

    public function testFailOnAcceptsAPriorityFromEverySource(): void
    {
        self::assertSame('high', LockrotConfig::fromSources([], [], ['fail-on' => 'high'], '8.5.10', null)->failOn()->value());
        self::assertSame('low', LockrotConfig::fromSources([], ['LOCKROT_FAIL_ON' => 'low'], [], '8.5.10', null)->failOn()->value());
        self::assertSame('critical', LockrotConfig::fromSources(['fail-on' => 'critical'], [], [], '8.5.10', null)->failOn()->value());
        self::assertSame('medium', LockrotConfig::fromSources(['fail-on' => 'medium'], [], [], '8.5.10', null)->failOn()->value());
    }

    public function testAnEmptyCliFailOnIsRejectedRatherThanFallingThrough(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('--fail-on must not be empty');
        LockrotConfig::fromSources(['fail-on' => 'silent'], [], ['fail-on' => ''], '8.5.10', null);
    }

    public function testAnEmptyCliTargetPhpIsRejectedRatherThanFallingThrough(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('--target-php must not be empty');
        LockrotConfig::fromSources(['target-php' => '8.2'], [], ['target-php' => ''], '8.5.10', null);
    }

    /** `none` is the absence of a threshold, not a priority level a run can fail on. */
    public function testThePriorityNoneIsTheSameNoneAsAlways(): void
    {
        self::assertSame(LockrotConfig::FAIL_ON_NONE, LockrotConfig::fromSources([], [], ['fail-on' => 'none'], '8.5.10', null)->failOn()->value());
    }

    public function testPrecedenceCliOverEnvOverExtra(): void
    {
        $cfg = LockrotConfig::fromSources(['fail-on' => 'stale', 'target-php' => '8.2'], ['LOCKROT_FAIL_ON' => 'silent', 'LOCKROT_TARGET_PHP' => '8.3'], ['fail-on' => 'abandoned'], '8.5.10', null);
        self::assertSame('abandoned', $cfg->failOn()->value());
        self::assertSame('8.3', $cfg->targetPhp());
        $cfg2 = LockrotConfig::fromSources(['fail-on' => 'stale'], ['LOCKROT_FAIL_ON' => 'silent'], [], '8.5.10', null);
        self::assertSame('silent', $cfg2->failOn()->value());
    }

    /**
     * One rule for every source: extra.lockrot is validated in full whatever overrides it, and an
     * environment variable whenever it is set — not only on the runs where no option outranks it.
     *
     * @param array<string, string> $env
     *
     * @dataProvider invalidEnvironmentUnderAnOption
     */
    #[DataProvider('invalidEnvironmentUnderAnOption')]
    public function testAnInvalidVariableIsAnErrorEvenWhereAnOptionOverridesIt(array $env, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);
        LockrotConfig::fromSources([], $env, ['fail-on' => 'silent', 'target-php' => '8.4'], '8.5.10', null);
    }

    /** @return iterable<string, array{0: array<string, string>, 1: string}> */
    public static function invalidEnvironmentUnderAnOption(): iterable
    {
        yield 'LOCKROT_FAIL_ON' => [['LOCKROT_FAIL_ON' => 'dead'], 'LOCKROT_FAIL_ON: unknown value "dead"'];
        yield 'LOCKROT_TARGET_PHP' => [['LOCKROT_TARGET_PHP' => 'latest'], 'LOCKROT_TARGET_PHP takes a major, a minor or a patch version, such as "8.4" or "8.4.2", with nothing before or after it; got "latest"'];
    }

    public function testAnInvalidVariableIsNamedWhereItWouldHaveWon(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('LOCKROT_FAIL_ON: unknown value');
        LockrotConfig::fromSources(['fail-on' => 'silent'], ['LOCKROT_FAIL_ON' => 'dead'], [], '8.5.10', null);
    }

    /** A variable set to nothing is a variable not set: CI systems pass empty inputs through as empty strings. */
    public function testAnEmptyVariableIsTreatedAsUnset(): void
    {
        $cfg = LockrotConfig::fromSources(['fail-on' => 'silent', 'target-php' => '8.2'], ['LOCKROT_FAIL_ON' => '', 'LOCKROT_TARGET_PHP' => ''], [], '8.5.10', null);

        self::assertSame('silent', $cfg->failOn()->value());
        self::assertSame('8.2', $cfg->targetPhp());
    }

    public function testAValidVariableUnderAnOptionStillLoses(): void
    {
        $cfg = LockrotConfig::fromSources([], ['LOCKROT_FAIL_ON' => 'high', 'LOCKROT_TARGET_PHP' => '8.1'], ['fail-on' => 'silent', 'target-php' => '8.4'], '8.5.10', null);

        self::assertSame('silent', $cfg->failOn()->value());
        self::assertSame('8.4', $cfg->targetPhp());
    }

    public function testCliFlags(): void
    {
        $cfg = LockrotConfig::fromSources([], [], ['dev' => true, 'offline' => true, 'strict-network' => true, 'format' => 'json'], '8.5.10', null);
        self::assertTrue($cfg->includeDev());
        self::assertTrue($cfg->offline());
        self::assertTrue($cfg->strictNetwork());
        self::assertSame('json', $cfg->format());
    }

    public function testDisableEnv(): void
    {
        self::assertTrue(LockrotConfig::fromSources([], ['LOCKROT_DISABLE' => '1'], [], '8.5.10', null)->isDisabled());
        self::assertFalse(LockrotConfig::fromSources([], ['LOCKROT_DISABLE' => '0'], [], '8.5.10', null)->isDisabled());
    }

    /**
     * @dataProvider networkDisabledValues
     */
    public function testComposerDisableNetworkReadsAsOffline(?string $value, bool $offline): void
    {
        $env = $value === null ? [] : ['COMPOSER_DISABLE_NETWORK' => $value];

        self::assertSame($offline, LockrotConfig::fromSources([], $env, [], '8.5.10', null)->offline());
    }

    /**
     * @return iterable<string, array{0: ?string, 1: bool}>
     */
    public static function networkDisabledValues(): iterable
    {
        yield 'unset' => [null, false];
        yield 'empty' => ['', false];
        yield 'zero' => ['0', false];
        yield 'prime' => ['prime', true];
        yield 'one' => ['1', true];
        yield 'false is still a non-empty string' => ['false', true];
        yield 'any other value' => ['true', true];
    }

    public function testInvalidFailOn(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('--fail-on: unknown value "dead"');
        LockrotConfig::fromSources([], [], ['fail-on' => 'dead'], '8.5.10', null);
    }

    /** @dataProvider formats */
    #[DataProvider('formats')]
    public function testEveryDocumentedFormatIsAccepted(string $format): void
    {
        self::assertSame($format, LockrotConfig::fromSources([], [], ['format' => $format], '8.5.10', null)->format());
        self::assertSame($format, LockrotConfig::fromSources(['format' => $format], [], [], '8.5.10', null)->format());
    }

    /** @return iterable<string, array{string}> */
    public static function formats(): iterable
    {
        foreach (LockrotConfig::FORMATS as $format) {
            yield $format => [$format];
        }
    }

    public function testFormatsCoverTheNewOutputs(): void
    {
        self::assertSame(['table', 'json', 'github', 'sarif', 'gitlab', 'markdown', 'html'], LockrotConfig::FORMATS);
    }

    /**
     * The rejection must carry both halves a user acts on: the value that was refused, and the
     * list of the ones that work.
     */
    public function testInvalidFormat(): void
    {
        $thrown = null;
        try {
            LockrotConfig::fromSources([], [], ['format' => 'xml'], '8.5.10', null);
        } catch (ConfigException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(ConfigException::class, $thrown);
        self::assertStringStartsWith('format must be one of ', $thrown->getMessage());
        self::assertStringContainsString(implode(', ', LockrotConfig::FORMATS), $thrown->getMessage());
        self::assertStringEndsWith('; got "xml"', $thrown->getMessage());
    }

    public function testIncludeDevFromExtraAndCli(): void
    {
        self::assertFalse(LockrotConfig::fromSources([], [], [], '8.5.10', null)->includeDev());
        self::assertTrue(LockrotConfig::fromSources(['include-dev' => true], [], [], '8.5.10', null)->includeDev());
        self::assertTrue(LockrotConfig::fromSources([], [], ['dev' => true], '8.5.10', null)->includeDev());
    }
}
