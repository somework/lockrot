<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Config;

use Lockrot\Config\LockrotConfig;
use Lockrot\Exception\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LockrotConfigTest extends TestCase
{
    /**
     * A composer.json need not name itself, and where it does the name is not always the one to
     * publish: a package inside a monorepo names itself after the package, a private project after
     * the client. Read from the manifest rather than a flag, because it describes the project.
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
        self::assertSame('none', $cfg->failOn());
        self::assertSame('8.5', $cfg->targetPhp());
        self::assertFalse($cfg->includeDev());
        // A source that names neither flag leaves both off: install time passes an empty $cli, so a
        // default of "offline" would silence every install-time repository lookup, and a default of
        // "strict-network" would fail an install on the first unreachable repository.
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
     * `--baseline=` reaches here as an empty string. Falling through to the default path would mean
     * a typo silently gates against a different file than the one the caller named.
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

    /** Both ends of the documented range are accepted; {@see invalidInstallTimeBudgets} covers the values just outside it. */
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

    public function testPlatformPhpBeatsRuntime(): void
    {
        self::assertSame('8.1', LockrotConfig::fromSources([], [], [], '8.5.10', '8.1.0')->targetPhp());
    }

    public function testFailOnAcceptsAPriorityFromEverySource(): void
    {
        self::assertSame('high', LockrotConfig::fromSources([], [], ['fail-on' => 'high'], '8.5.10', null)->failOn());
        self::assertSame('low', LockrotConfig::fromSources([], ['LOCKROT_FAIL_ON' => 'low'], [], '8.5.10', null)->failOn());
        self::assertSame('critical', LockrotConfig::fromSources(['fail-on' => 'critical'], [], [], '8.5.10', null)->failOn());
        self::assertSame('medium', LockrotConfig::fromSources(['fail-on' => 'medium'], [], [], '8.5.10', null)->failOn());
    }

    public function testAnEmptyCliFailOnIsRejectedRatherThanFallingThrough(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('--fail-on must not be empty');
        LockrotConfig::fromSources(['fail-on' => 'silent'], [], ['fail-on' => ''], '8.5.10', null);
    }

    /** `none` is the absence of a threshold, not a priority level a run can fail on. */
    public function testThePriorityNoneIsTheSameNoneAsAlways(): void
    {
        self::assertSame(LockrotConfig::FAIL_ON_NONE, LockrotConfig::fromSources([], [], ['fail-on' => 'none'], '8.5.10', null)->failOn());
    }

    public function testPrecedenceCliOverEnvOverExtra(): void
    {
        $cfg = LockrotConfig::fromSources(['fail-on' => 'stale', 'target-php' => '8.2'], ['LOCKROT_FAIL_ON' => 'silent', 'LOCKROT_TARGET_PHP' => '8.3'], ['fail-on' => 'abandoned'], '8.5.10', null);
        self::assertSame('abandoned', $cfg->failOn());
        self::assertSame('8.3', $cfg->targetPhp());
        $cfg2 = LockrotConfig::fromSources(['fail-on' => 'stale'], ['LOCKROT_FAIL_ON' => 'silent'], [], '8.5.10', null);
        self::assertSame('silent', $cfg2->failOn());
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

    public function testInvalidFailOn(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('fail-on must be one of none, abandoned, silent, pinned, left-behind, old-promise, stale, critical, high, medium, low; got "dead"');
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
     * The rejection has to carry both halves a user acts on: the value that was refused, and the
     * list of the ones that would have worked.
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
