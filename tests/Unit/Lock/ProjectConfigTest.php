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
        self::assertSame('^7.4 || ^8.0', $cfg->requirePhp());
    }

    /** `require.php` is read as written; a project that promises no PHP, or an unreadable promise, has none. */
    public function testThePhpRequirementIsReadAsWrittenOrIsNothing(): void
    {
        self::assertSame('>=7.2.5', ProjectConfig::fromArray(['require' => ['php' => '>=7.2.5']])->requirePhp());
        self::assertNull(ProjectConfig::fromArray(['require' => ['a/b' => '^1.0']])->requirePhp());
        self::assertNull(ProjectConfig::fromArray(['require' => ['php' => '']])->requirePhp());
        self::assertNull(ProjectConfig::fromArray(['require' => ['php' => ['^8.2']]])->requirePhp());
        self::assertNull(ProjectConfig::fromArray(['require' => 'nonsense'])->requirePhp());
        self::assertNull(ProjectConfig::fromArray([])->requirePhp());
        self::assertNull(ProjectConfig::empty()->requirePhp());
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

    /**
     * json_decode(..., true) turns an object key that looks like an integer into an int key, and
     * `require` is a nested object, so such a key reaches this far. The reported names stay
     * strings whatever the key was.
     */
    public function testANumericRequireKeyIsReportedAsAStringName(): void
    {
        $cfg = ProjectConfig::fromArray(['require' => [123 => '^1.0', 'a/b' => '^1.0']]);

        self::assertSame(['123', 'a/b'], $cfg->directRequires());
    }

    /**
     * The one thing that says which project a report is about: every lock in the world is called
     * composer.lock, so the name has to come from the manifest. An application is not required to
     * name itself, and a name that is there but empty is not a name.
     */
    public function testTheProjectNamesItselfOrDoesNot(): void
    {
        self::assertSame('acme/shop', ProjectConfig::fromArray(['name' => 'acme/shop'])->name());
        self::assertNull(ProjectConfig::fromArray([])->name(), 'an application need not name itself');
        self::assertNull(ProjectConfig::fromArray(['name' => ''])->name());
        self::assertNull(ProjectConfig::fromArray(['name' => ['acme/shop']])->name(), 'a name is a string or it is nothing');
        self::assertNull(ProjectConfig::empty()->name());
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
