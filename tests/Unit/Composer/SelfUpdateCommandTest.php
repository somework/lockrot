<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Console\Application;
use Composer\Util\Platform;
use Lockrot\Composer\SelfUpdateCommand;
use Lockrot\Data\Http\HttpResult;
use Lockrot\SelfUpdate\PharValidatorInterface;
use Lockrot\SelfUpdate\ReleaseLocator;
use Lockrot\Tests\Support\FakeHttpClient;
use Lockrot\Tests\Support\SplitStreamOutput;
use Lockrot\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\CommandTester;

final class SelfUpdateCommandTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../fixtures/http/github-releases';
    private const NEWER = '99.0.0';
    private const NEW_PHAR = 'the bytes of a newer lockrot.phar';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $entries = scandir($dir);
            foreach ($entries === false ? [] : $entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($dir.'/'.$entry);
                }
            }
            @rmdir($dir);
        }
        $this->tempDirs = [];
    }

    private function installedPhar(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-selfupdate-cmd-'.uniqid('', true);
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;
        $path = $dir.'/lockrot.phar';
        file_put_contents($path, 'the bytes of the running lockrot.phar');

        return $path;
    }

    /** The recorded releases/latest body, retagged so the test controls whether it is newer. */
    private static function releaseBody(string $tag): string
    {
        $fixture = file_get_contents(self::FIXTURES.'/latest.json');
        self::assertIsString($fixture);

        return str_replace('v0.2.0', $tag, $fixture);
    }

    private static function downloadUrl(string $tag, string $asset): string
    {
        return 'https://github.com/somework/lockrot/releases/download/'.$tag.'/'.$asset;
    }

    /** @param array<string, HttpResult> $extra */
    private function httpFor(string $tag, array $extra = []): FakeHttpClient
    {
        return new FakeHttpClient(array_merge(
            [ReleaseLocator::DEFAULT_URL => FakeHttpClient::ok(ReleaseLocator::DEFAULT_URL, self::releaseBody($tag))],
            $extra
        ));
    }

    /** @param array<string, HttpResult> $extra */
    private function httpWithAssets(string $tag, array $extra = []): FakeHttpClient
    {
        $phar = self::downloadUrl($tag, 'lockrot.phar');
        $checksum = self::downloadUrl($tag, 'lockrot.phar.sha256');

        return $this->httpFor($tag, array_merge([
            $phar => FakeHttpClient::ok($phar, self::NEW_PHAR),
            $checksum => FakeHttpClient::ok($checksum, hash('sha256', self::NEW_PHAR).'  lockrot.phar'."\n"),
        ], $extra));
    }

    private function validator(?string $error = null): PharValidatorInterface
    {
        return new class ($error) implements PharValidatorInterface {
            private ?string $error;

            public function __construct(?string $error)
            {
                $this->error = $error;
            }

            public function validate(string $path): ?string
            {
                return $this->error;
            }
        };
    }

    private function command(FakeHttpClient $http, string $runningPhar): SelfUpdateCommand
    {
        $command = new SelfUpdateCommand(
            static function () use ($http): array {
                return [$http, null];
            },
            $this->validator(),
            $runningPhar
        );
        $app = new Application();
        $app->setAutoExit(false);
        $app->add($command);
        $command->setApplication($app);

        return $command;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runCommand(SelfUpdateCommand $command, array $args): array
    {
        $output = new SplitStreamOutput();
        $code = $command->run(new ArrayInput($args, $command->getDefinition()), $output);

        return [$code, $output->fetch(), $output->fetchErrors()];
    }

    public function testCheckOnAnUpToDateInstallExitsZero(): void
    {
        $http = $this->httpFor('v'.Version::STRING);
        $tester = new CommandTester($this->command($http, $this->installedPhar()));

        $code = $tester->execute(['--check' => true]);

        self::assertSame(0, $code, $tester->getDisplay());
        self::assertStringContainsString('lockrot '.Version::STRING.' is up to date', $tester->getDisplay());
        self::assertSame([ReleaseLocator::DEFAULT_URL], $http->requested(), '--check never downloads the phar');
    }

    public function testCheckWithAnAvailableUpdateExitsOneAndNamesBothVersions(): void
    {
        $http = $this->httpFor('v'.self::NEWER);
        $tester = new CommandTester($this->command($http, $this->installedPhar()));

        $code = $tester->execute(['--check' => true]);

        self::assertSame(1, $code, $tester->getDisplay());
        self::assertStringContainsString(
            'lockrot '.self::NEWER.' is available (installed: '.Version::STRING.'); run lockrot.phar self-update',
            $tester->getDisplay()
        );
        self::assertSame([ReleaseLocator::DEFAULT_URL], $http->requested(), '--check never downloads the phar');
    }

    public function testAnUpdateReplacesThePharAndReportsBothVersions(): void
    {
        $phar = $this->installedPhar();
        $http = $this->httpWithAssets('v'.self::NEWER);

        [$code, $stdout, $stderr] = $this->runCommand($this->command($http, $phar), []);

        self::assertSame(0, $code, $stderr);
        self::assertSame('', $stdout, 'self-update writes nothing to stdout');
        self::assertStringContainsString('lockrot updated from '.Version::STRING.' to '.self::NEWER, $stderr);
        self::assertSame(self::NEW_PHAR, file_get_contents($phar));
    }

    public function testAnUpToDateInstallSaysSoAndLeavesThePharAlone(): void
    {
        $phar = $this->installedPhar();
        $before = (string) file_get_contents($phar);
        $http = $this->httpWithAssets('v'.Version::STRING);

        [$code, $stdout, $stderr] = $this->runCommand($this->command($http, $phar), []);

        self::assertSame(0, $code, $stderr);
        self::assertSame('', $stdout);
        self::assertStringContainsString('lockrot '.Version::STRING.' is up to date', $stderr);
        self::assertSame($before, file_get_contents($phar));
    }

    public function testForceReinstallsTheSameVersion(): void
    {
        $phar = $this->installedPhar();
        $http = $this->httpWithAssets('v'.Version::STRING);

        [$code, , $stderr] = $this->runCommand($this->command($http, $phar), ['--force' => true]);

        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString('lockrot reinstalled '.Version::STRING, $stderr);
        self::assertSame(self::NEW_PHAR, file_get_contents($phar));
    }

    public function testOutsideAPharTheCommandRefusesBeforeTouchingTheNetwork(): void
    {
        $http = $this->httpWithAssets('v'.self::NEWER);

        [$code, $stdout, $stderr] = $this->runCommand($this->command($http, ''), []);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('self-update is only available in the lockrot.phar build', $stderr);
        self::assertSame([], $http->requested());
    }

    public function testTheOfflineOptionIsRefusedWithAReason(): void
    {
        $http = $this->httpWithAssets('v'.self::NEWER);

        [$code, $stdout, $stderr] = $this->runCommand($this->command($http, $this->installedPhar()), ['--offline' => true]);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('self-update needs network access', $stderr);
        self::assertSame([], $http->requested());
    }

    public function testComposerDisableNetworkIsRefusedWithTheSameReason(): void
    {
        $previous = Platform::getEnv('COMPOSER_DISABLE_NETWORK');
        Platform::putEnv('COMPOSER_DISABLE_NETWORK', '1');
        try {
            $http = $this->httpWithAssets('v'.self::NEWER);

            [$code, , $stderr] = $this->runCommand($this->command($http, $this->installedPhar()), []);

            self::assertSame(2, $code);
            self::assertStringContainsString('self-update needs network access', $stderr);
            self::assertSame([], $http->requested());
        } finally {
            if ($previous === false) {
                Platform::clearEnv('COMPOSER_DISABLE_NETWORK');
            } else {
                Platform::putEnv('COMPOSER_DISABLE_NETWORK', $previous);
            }
        }
    }

    public function testNoPublishedReleaseIsExitTwo(): void
    {
        $body = file_get_contents(self::FIXTURES.'/not-found.json');
        self::assertIsString($body);
        $http = new FakeHttpClient([
            ReleaseLocator::DEFAULT_URL => FakeHttpClient::status(ReleaseLocator::DEFAULT_URL, 404, $body),
        ]);

        [$code, $stdout, $stderr] = $this->runCommand($this->command($http, $this->installedPhar()), ['--check' => true]);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('lockrot: no published release found', $stderr);
    }

    public function testTheCommandIsNamedSelfUpdateAndAliasesComposersOwnSpelling(): void
    {
        $command = $this->command($this->httpFor('v'.Version::STRING), $this->installedPhar());

        self::assertSame('self-update', $command->getName());
        self::assertContains('selfupdate', $command->getAliases());
    }
}
