<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\SelfUpdate\ReleaseDescription;
use Lockrot\Tests\Support\GitHubReleases;
use Lockrot\Tests\Support\SigningKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * `build/selfupdate-signature.php describe` writes the `lockrot.phar.meta.json` every release
 * publishes. The release workflow runs it; the archive reads its output. This runs the one against
 * the other so the two formats cannot drift apart.
 */
final class SelfUpdateDescribeScriptTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';
    private const RELEASE_KEY = self::ROOT.'/tests/fixtures/signing/release-key.pub';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    private static function describe(string $composerJson, string $publicKey): Process
    {
        $process = new Process([\PHP_BINARY, self::ROOT.'/build/selfupdate-signature.php', 'describe', $composerJson, $publicKey]);
        $process->setTimeout(60)->run();

        return $process;
    }

    /** @param array<string, mixed> $manifest */
    private function composerJson(array $manifest): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'lockrot-describe-');
        $this->tempFiles[] = $path;
        file_put_contents($path, json_encode($manifest, \JSON_THROW_ON_ERROR));

        return $path;
    }

    public function testTheWorkflowsDescriptionIsWhatTheArchiveReads(): void
    {
        $process = self::describe(self::ROOT.'/build/phar/composer.json', self::RELEASE_KEY);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $description = ReleaseDescription::fromJson($process->getOutput(), 'describe');
        self::assertSame('7.4.0', $description->phpFloor());
        self::assertSame(GitHubReleases::fingerprint(SigningKeys::releasePublicPem()), $description->signingKey());
    }

    /**
     * The platform PHP the archive's dependencies are resolved for is what the description
     * advertises, so it has to be the floor both manifests declare: a raised `require.php` with a
     * platform left at 7.4.0 would describe a floor the archive cannot run on.
     */
    public function testAPlatformThatIsNotTheDeclaredFloorFailsTheRelease(): void
    {
        $manifest = $this->composerJson([
            'require' => ['php' => '^8.1'],
            'config' => ['platform' => ['php' => '7.4.0']],
        ]);

        $process = self::describe($manifest, self::RELEASE_KEY);

        self::assertSame(1, $process->getExitCode());
        self::assertSame('', $process->getOutput());
        self::assertStringContainsString('config.platform.php 7.4.0 is not the lowest PHP', $process->getErrorOutput());
    }

    public function testAManifestWithoutAPlatformFailsTheRelease(): void
    {
        $process = self::describe($this->composerJson(['require' => ['php' => '^7.4']]), self::RELEASE_KEY);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('has no config.platform.php', $process->getErrorOutput());
    }

    public function testAKeyWithoutAFingerprintFailsTheRelease(): void
    {
        $process = self::describe(self::ROOT.'/build/phar/composer.json', self::ROOT.'/tests/fixtures/signing/release-key.pem');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('is not a PEM "PUBLIC KEY" block', $process->getErrorOutput());
    }
}
