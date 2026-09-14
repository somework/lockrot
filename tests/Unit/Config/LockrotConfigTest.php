<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Config;

use Lockrot\Config\LockrotConfig;
use Lockrot\Exception\ConfigException;
use PHPUnit\Framework\TestCase;

final class LockrotConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $cfg = LockrotConfig::fromSources([], [], [], '8.5.10', null);
        self::assertSame('none', $cfg->failOn());
        self::assertSame('8.5', $cfg->targetPhp());
        self::assertFalse($cfg->includeDev());
        self::assertSame('table', $cfg->format());
        self::assertFalse($cfg->isDisabled());
        self::assertSame(3, $cfg->thresholds()->releaseWarnYears());
        self::assertTrue($cfg->installTime());
        self::assertFalse($cfg->installTimeStrict());
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
        LockrotConfig::fromSources([], [], ['fail-on' => 'dead'], '8.5.10', null);
    }

    public function testInvalidFormat(): void
    {
        $this->expectException(ConfigException::class);
        LockrotConfig::fromSources([], [], ['format' => 'xml'], '8.5.10', null);
    }

    public function testIncludeDevFromExtraAndCli(): void
    {
        self::assertFalse(LockrotConfig::fromSources([], [], [], '8.5.10', null)->includeDev());
        self::assertTrue(LockrotConfig::fromSources(['include-dev' => true], [], [], '8.5.10', null)->includeDev());
        self::assertTrue(LockrotConfig::fromSources([], [], ['dev' => true], '8.5.10', null)->includeDev());
    }
}
