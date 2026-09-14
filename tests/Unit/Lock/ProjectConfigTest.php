<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Lock;

use Lockrot\Exception\ConfigException;
use Lockrot\Lock\ProjectConfig;
use PHPUnit\Framework\TestCase;

final class ProjectConfigTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    public function testReadsRequiresExtraAndPlatform(): void
    {
        $cfg = ProjectConfig::fromFile(__DIR__.'/../../fixtures/mini/composer.json');
        self::assertSame(['vendor/direct'], $cfg->directRequires());
        self::assertSame(['vendor/devtool'], $cfg->directDevRequires());
        self::assertSame('silent', $cfg->lockrotExtra()['fail-on']);
        self::assertSame('8.1.0', $cfg->platformPhp());
    }

    public function testPlatformPackagesAreExcludedFromRequires(): void
    {
        $cfg = ProjectConfig::fromArray([
            'require' => [
                'php' => '^7.4 || ^8.0',
                'ext-json' => '*',
                'composer-plugin-api' => '^2.0',
                'a/b' => '^1.0',
            ],
        ]);
        self::assertSame(['a/b'], $cfg->directRequires());
    }

    public function testEmptyConfig(): void
    {
        $cfg = ProjectConfig::empty();
        self::assertSame([], $cfg->directRequires());
        self::assertSame([], $cfg->lockrotExtra());
        self::assertNull($cfg->platformPhp());
    }

    public function testMissingFileGivesEmptyConfig(): void
    {
        self::assertSame([], ProjectConfig::fromFile('/nonexistent/composer.json')->directRequires());
    }

    public function testMalformedJsonIsRejected(): void
    {
        $path = $this->tempComposerJson('{broken');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('{composer\.json is not valid JSON: .+}');
        ProjectConfig::fromFile($path);
    }

    public function testJsonScalarIsRejected(): void
    {
        $path = $this->tempComposerJson('"just a string"');

        $this->expectException(ConfigException::class);
        ProjectConfig::fromFile($path);
    }

    public function testNonArrayLockrotExtraIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('extra.lockrot must be an object');
        ProjectConfig::fromArray(['extra' => ['lockrot' => 'not-an-object']]);
    }

    public function testListShapedLockrotExtraIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('extra.lockrot must be an object');
        ProjectConfig::fromArray(['extra' => ['lockrot' => ['fail-on', 'silent']]]);
    }

    public function testEmptyArrayLockrotExtraIsStillAccepted(): void
    {
        $cfg = ProjectConfig::fromArray(['extra' => ['lockrot' => []]]);
        self::assertSame([], $cfg->lockrotExtra());
    }

    public function testSchemaInvalidLockrotExtraIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('extra.lockrot is invalid:');
        ProjectConfig::fromArray(['extra' => ['lockrot' => ['fail-on' => 'dead']]]);
    }

    private function tempComposerJson(string $contents): string
    {
        $dir = sys_get_temp_dir().'/lockrot-projectconfig-'.uniqid();
        mkdir($dir);
        $path = $dir.'/composer.json';
        file_put_contents($path, $contents);
        $this->tempDirs[] = $dir;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            @unlink($dir.'/composer.json');
            @rmdir($dir);
        }
        $this->tempDirs = [];
    }
}
