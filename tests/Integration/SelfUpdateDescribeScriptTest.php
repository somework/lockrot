<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\SelfUpdate\ReleaseDescription;
use Lockrot\Tests\Support\GitHubReleases;
use Lockrot\Tests\Support\SigningKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * `build/selfupdate-signature.php describe` writes the `lockrot.phar.meta.json` every release
 * publishes. The release workflow runs it; the archive reads its output. This runs the one against
 * the other so the two formats cannot drift apart.
 *
 * `verify` is the workflow's check that archives in the field can follow a release: it is signed
 * with the key the previous release carries. The test keys stand in for an old and a new key.
 */
final class SelfUpdateDescribeScriptTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';
    private const RELEASE_KEY = self::ROOT.'/tests/fixtures/signing/release-key.pub';
    private const OTHER_KEY = self::ROOT.'/tests/fixtures/signing/other-key.pub';

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

    /** @param list<string> $arguments */
    private static function script(array $arguments): Process
    {
        $process = new Process(array_merge([\PHP_BINARY, self::ROOT.'/build/selfupdate-signature.php'], $arguments));
        $process->setTimeout(60)->run();

        return $process;
    }

    private function tempFile(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'lockrot-verify-');
        $this->tempFiles[] = $path;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * `verify` for an archive signed by $signer ('release' or 'other'), with $previous as the key the
     * previous release carries and $carried as the key this build carries.
     */
    private function verify(string $signer, string $previous, string $carried): Process
    {
        $archive = 'the bytes of a release';
        $signature = $signer === 'release' ? SigningKeys::releaseSignatureFile($archive) : SigningKeys::otherSignatureFile($archive);

        return self::script(['verify', $this->tempFile($archive), $this->tempFile($signature), $previous, $carried]);
    }

    public function testAnOrdinaryReleaseIsSignedWithTheKeyThePreviousOneCarries(): void
    {
        $process = $this->verify('release', self::RELEASE_KEY, self::RELEASE_KEY);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('verified with the key of the previous release, which this archive carries too', $process->getOutput());
    }

    /**
     * The rotation done in one step — the new key everywhere at once — is what no archive in the
     * field could follow, so the release build fails on it.
     */
    public function testARotationWithoutATransitionReleaseFailsTheBuild(): void
    {
        $process = $this->verify('other', self::RELEASE_KEY, self::OTHER_KEY);

        self::assertSame(1, $process->getExitCode());
        self::assertSame('', $process->getOutput());
        self::assertStringContainsString('the key of the previous release does not verify this one, so no archive in the field could install it', $process->getErrorOutput());
        self::assertStringContainsString('A rotation needs a transition release signed with the old key', $process->getErrorOutput());
    }

    /** The transition release: signed with the old key, carrying the new one. It passes, and says so. */
    public function testATransitionReleasePassesAndSaysWhatItIs(): void
    {
        $process = $this->verify('release', self::RELEASE_KEY, self::OTHER_KEY);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('this is the transition release of a rotation', $process->getOutput());
        self::assertStringContainsString(GitHubReleases::fingerprint(SigningKeys::otherPublicPem()), $process->getOutput());
    }

    /** The release after it is signed with the new key, which the transition release carries. */
    public function testTheReleaseAfterATransitionIsSignedWithTheNewKey(): void
    {
        self::assertSame(0, $this->verify('other', self::OTHER_KEY, self::OTHER_KEY)->getExitCode());
        self::assertSame(1, $this->verify('release', self::OTHER_KEY, self::OTHER_KEY)->getExitCode(), 'the old key after the transition');
    }

    /** Without a fourth argument the carried key is the one built into the source tree. */
    public function testTheCarriedKeyIsTheBuiltInOneByDefault(): void
    {
        $archive = $this->tempFile('the bytes of a release');
        $signature = $this->tempFile(SigningKeys::releaseSignatureFile('the bytes of a release'));

        $process = self::script(['verify', $archive, $signature, self::RELEASE_KEY]);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('this archive carries another (sha256:ec3ca71b1a3ced86f871b89cff7973b58454e5694136683680b72b18070a8f87)', $process->getOutput());
    }

    /** @return iterable<string, array{0: string}> */
    public static function floorsInAnotherSpelling(): iterable
    {
        yield 'a leading v' => ['v7.4.0'];
        yield 'two numbers' => ['7.4'];
        yield 'four numbers' => ['7.4.0.0'];
    }

    /**
     * Composer accepts each of these as the platform, and each equals the declared floor once
     * normalised; an archive reads none of them as a floor, so the release would never be offered.
     *
     * @dataProvider floorsInAnotherSpelling
     */
    #[DataProvider('floorsInAnotherSpelling')]
    public function testAPlatformNotSpelledMajorMinorPatchFailsTheRelease(string $platform): void
    {
        $manifest = $this->composerJson([
            'require' => ['php' => '^7.4 || ^8.0'],
            'config' => ['platform' => ['php' => $platform]],
        ]);

        $process = self::describe($manifest, self::RELEASE_KEY);

        self::assertSame(1, $process->getExitCode());
        self::assertSame('', $process->getOutput());
        self::assertStringContainsString('config.platform.php '.$platform.' is not spelled major.minor.patch', $process->getErrorOutput());
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
