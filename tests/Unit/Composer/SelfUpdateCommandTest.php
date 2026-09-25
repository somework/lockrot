<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Composer;

use Composer\Console\Application;
use Composer\Util\Platform;
use Lockrot\Composer\SelfUpdateCommand;
use Lockrot\Data\Http\HttpResult;
use Lockrot\SelfUpdate\PharValidatorInterface;
use Lockrot\SelfUpdate\ReleaseLocator;
use Lockrot\SelfUpdate\ReleaseSignatureVerifier;
use Lockrot\Tests\Support\FakeHttpClient;
use Lockrot\Tests\Support\GitHubReleases;
use Lockrot\Tests\Support\SigningKeys;
use Lockrot\Tests\Support\SplitStreamOutput;
use Lockrot\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\CommandTester;

final class SelfUpdateCommandTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../fixtures/http/github-releases';
    private const URL = ReleaseLocator::DEFAULT_URL;
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

    /** A release newer than the running build and in its major version, whatever that build is. */
    private static function newer(): string
    {
        return GitHubReleases::newerInLine(Version::STRING);
    }

    private static function nextMajor(): string
    {
        return GitHubReleases::nextMajor(Version::STRING);
    }

    private static function heldBackNote(): string
    {
        return 'lockrot '.self::nextMajor().' is in the next major version; run lockrot.phar self-update --allow-major to move to it';
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

    /**
     * The release list for $versions, newest first, each described as the release workflow would
     * describe it — PHP 7.4.0 and the test key the command verifies with — with its archive,
     * checksum and signature ready to download.
     *
     * @param list<string>              $versions
     * @param array<string, HttpResult> $extra
     */
    private static function http(array $versions, array $extra = [], string $url = self::URL): FakeHttpClient
    {
        $key = GitHubReleases::fingerprint(SigningKeys::releasePublicPem());
        $entries = [];
        $responses = [];
        foreach ($versions as $version) {
            $tag = 'v'.$version;
            $entries[] = GitHubReleases::entry($tag);
            $assets = [
                ReleaseLocator::METADATA_ASSET => GitHubReleases::meta('7.4.0', $key),
                ReleaseLocator::PHAR_ASSET => self::NEW_PHAR,
                ReleaseLocator::CHECKSUM_ASSET => hash('sha256', self::NEW_PHAR).'  lockrot.phar'."\n",
                ReleaseLocator::SIGNATURE_ASSET => SigningKeys::releaseSignatureFile(self::NEW_PHAR),
            ];
            foreach ($assets as $name => $body) {
                $assetUrl = GitHubReleases::assetUrl($tag, $name);
                $responses[$assetUrl] = FakeHttpClient::ok($assetUrl, $body);
            }
        }
        $responses[$url] = FakeHttpClient::ok($url, GitHubReleases::listJson($entries));

        return new FakeHttpClient(array_merge($responses, $extra));
    }

    private static function pharUrl(string $version): string
    {
        return GitHubReleases::assetUrl('v'.$version, ReleaseLocator::PHAR_ASSET);
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

    private function command(FakeHttpClient $http, string $runningPhar, ?string $releaseUrl = null): SelfUpdateCommand
    {
        return $this->registered(new SelfUpdateCommand(
            static function () use ($http): array {
                return [$http, null];
            },
            $this->validator(),
            $runningPhar,
            $releaseUrl,
            // The real verifier with the test key: the command's own wiring is what runs, only the
            // key differs from the one built into a release.
            new ReleaseSignatureVerifier(SigningKeys::releasePublicPem())
        ));
    }

    private function registered(SelfUpdateCommand $command): SelfUpdateCommand
    {
        $app = new Application();
        $app->setAutoExit(false);
        $app->add($command);
        $command->setApplication($app);

        return $command;
    }

    /**
     * @param array<string, mixed> $args
     * @param bool                 $decorated render stderr as a terminal would, with the styles applied
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runCommand(SelfUpdateCommand $command, array $args, bool $decorated = false): array
    {
        $output = new SplitStreamOutput();
        $output->getErrorOutput()->setDecorated($decorated);
        $code = $command->run(new ArrayInput($args, $command->getDefinition()), $output);

        return [$code, $output->fetch(), $output->fetchErrors()];
    }

    /**
     * The pattern for "$message is styled, all of it": one escape sequence in front of the whole
     * line and one behind it, and none in between. A style that closed after the `lockrot:` prefix
     * would print the reason itself unhighlighted.
     */
    private static function styledWhole(string $message): string
    {
        return '~\e\[[0-9;]+m'.preg_quote($message, '~').'\e\[[0-9;]+m~';
    }

    public function testCheckOnAnUpToDateInstallExitsZero(): void
    {
        $http = self::http([Version::STRING]);
        $tester = new CommandTester($this->command($http, $this->installedPhar()));

        $code = $tester->execute(['--check' => true]);

        self::assertSame(0, $code, $tester->getDisplay());
        self::assertStringContainsString('lockrot '.Version::STRING.' is up to date', $tester->getDisplay());
        self::assertSame([self::URL], $http->requested(), 'an up-to-date check reads the list and nothing else');
    }

    public function testCheckWithAnAvailableUpdateExitsOneAndNamesBothVersions(): void
    {
        $http = self::http([self::newer(), Version::STRING]);
        $tester = new CommandTester($this->command($http, $this->installedPhar()));

        $code = $tester->execute(['--check' => true]);

        self::assertSame(1, $code, $tester->getDisplay());
        self::assertStringContainsString(
            'lockrot '.self::newer().' is available (installed: '.Version::STRING.'); run lockrot.phar self-update',
            $tester->getDisplay()
        );
        self::assertNotContains(self::pharUrl(self::newer()), $http->requested(), '--check never downloads the phar');
    }

    public function testAnUpdateReplacesThePharAndReportsBothVersions(): void
    {
        $phar = $this->installedPhar();
        $http = self::http([self::newer(), Version::STRING]);

        [$code, $stdout, $stderr] = $this->runCommand($this->command($http, $phar), []);

        self::assertSame(0, $code, $stderr);
        self::assertSame('', $stdout, 'self-update writes nothing to stdout');
        self::assertSame('lockrot updated from '.Version::STRING.' to '.self::newer()."\n", $stderr);
        self::assertSame(self::NEW_PHAR, file_get_contents($phar));
    }

    public function testAnUpToDateInstallSaysSoAndLeavesThePharAlone(): void
    {
        $phar = $this->installedPhar();
        $before = (string) file_get_contents($phar);
        $http = self::http([Version::STRING]);

        [$code, $stdout, $stderr] = $this->runCommand($this->command($http, $phar), []);

        self::assertSame(0, $code, $stderr);
        self::assertSame('', $stdout);
        self::assertSame('lockrot '.Version::STRING." is up to date\n", $stderr);
        self::assertSame($before, file_get_contents($phar));
    }

    public function testForceReinstallsTheSameVersion(): void
    {
        $phar = $this->installedPhar();
        $http = self::http([Version::STRING]);

        [$code, , $stderr] = $this->runCommand($this->command($http, $phar), ['--force' => true]);

        self::assertSame(0, $code, $stderr);
        self::assertSame('lockrot reinstalled '.Version::STRING."\n", $stderr);
        self::assertSame(self::NEW_PHAR, file_get_contents($phar));
    }

    /** --check only ever reports; with --force it still says whether anything newer exists. */
    public function testCheckWithForceOnTheRunningVersionIsUpToDate(): void
    {
        $http = self::http([Version::STRING]);

        [$code, , $stderr] = $this->runCommand($this->command($http, $this->installedPhar()), ['--check' => true, '--force' => true]);

        self::assertSame(0, $code, $stderr);
        self::assertSame('lockrot '.Version::STRING." is up to date\n", $stderr);
        self::assertNotContains(self::pharUrl(Version::STRING), $http->requested());
    }

    public function testANewerMajorIsNotInstalledWithoutAllowMajor(): void
    {
        $phar = $this->installedPhar();
        $before = (string) file_get_contents($phar);
        $http = self::http([self::nextMajor(), Version::STRING]);

        [$code, $stdout, $stderr] = $this->runCommand($this->command($http, $phar), []);

        self::assertSame(0, $code, $stderr);
        self::assertSame('', $stdout);
        self::assertSame(self::heldBackNote()."\n".'lockrot '.Version::STRING." is up to date\n", $stderr);
        self::assertSame($before, file_get_contents($phar));
        self::assertSame([self::URL], $http->requested());
    }

    public function testAllowMajorInstallsTheNextMajor(): void
    {
        $phar = $this->installedPhar();
        $http = self::http([self::nextMajor(), Version::STRING]);

        [$code, , $stderr] = $this->runCommand($this->command($http, $phar), ['--allow-major' => true]);

        self::assertSame(0, $code, $stderr);
        self::assertSame('lockrot updated from '.Version::STRING.' to '.self::nextMajor()."\n", $stderr);
        self::assertSame(self::NEW_PHAR, file_get_contents($phar));
    }

    /**
     * Exit 1 means "self-update would install something", and without the flag it would not. A
     * scheduled job keeps its meaning and still reads the new major on the line above.
     */
    public function testCheckNamesANewerMajorButExitsZero(): void
    {
        $http = self::http([self::nextMajor(), Version::STRING]);

        [$code, , $stderr] = $this->runCommand($this->command($http, $this->installedPhar()), ['--check' => true]);

        self::assertSame(0, $code, $stderr);
        self::assertSame(self::heldBackNote()."\n".'lockrot '.Version::STRING." is up to date\n", $stderr);
    }

    public function testCheckWithAllowMajorExitsOneForANewMajor(): void
    {
        $http = self::http([self::nextMajor(), Version::STRING]);

        [$code, , $stderr] = $this->runCommand($this->command($http, $this->installedPhar()), ['--check' => true, '--allow-major' => true]);

        self::assertSame(1, $code, $stderr);
        self::assertStringContainsString('lockrot '.self::nextMajor().' is available (installed: '.Version::STRING.')', $stderr);
    }

    /** The notes say why; the error says that nothing in the line was left to install. */
    public function testForceWithNoInstallableReleaseInTheLineIsExitTwo(): void
    {
        $http = self::http([self::nextMajor()]);

        [$code, $stdout, $stderr] = $this->runCommand($this->command($http, $this->installedPhar()), ['--force' => true]);

        self::assertSame(2, $code, $stderr);
        self::assertSame('', $stdout);
        self::assertSame(
            self::heldBackNote()."\n".'lockrot: no release in the '.((int) Version::STRING).".x line can be installed by this lockrot.phar\n",
            $stderr
        );
    }

    /** Everything is said before the archive is swapped: afterwards the old classes are gone. */
    public function testNotesComeBeforeTheOutcomeLine(): void
    {
        $phar = $this->installedPhar();
        $http = self::http([self::nextMajor(), self::newer(), Version::STRING]);

        [$code, , $stderr] = $this->runCommand($this->command($http, $phar), []);

        self::assertSame(0, $code, $stderr);
        self::assertSame(
            self::heldBackNote()."\n".'lockrot updated from '.Version::STRING.' to '.self::newer()."\n",
            $stderr
        );
    }

    /**
     * The fingerprint the release list is filtered by is the key's own: a key without one is an
     * error before anything is asked of the network, not a quiet "nothing to install".
     */
    public function testAKeyWithoutAFingerprintIsExitTwoBeforeTheListIsRead(): void
    {
        $http = self::http([self::newer()]);
        $command = $this->registered(new SelfUpdateCommand(
            static function () use ($http): array {
                return [$http, null];
            },
            $this->validator(),
            $this->installedPhar(),
            null,
            new ReleaseSignatureVerifier('not a key')
        ));

        [$code, , $stderr] = $this->runCommand($command, []);

        self::assertSame(2, $code);
        self::assertStringContainsString('lockrot: the public key self-update verifies releases with is not a PEM "PUBLIC KEY" block', $stderr);
        self::assertSame([], $http->requested());
    }

    public function testOutsideAPharTheCommandRefusesBeforeTouchingTheNetwork(): void
    {
        $http = self::http([self::newer()]);

        [$code, $stdout, $stderr] = $this->runCommand($this->command($http, ''), []);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('self-update is only available in the lockrot.phar build', $stderr);
        self::assertSame([], $http->requested());
    }

    public function testTheOfflineOptionIsRefusedWithAReason(): void
    {
        $http = self::http([self::newer()]);

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
            $http = self::http([self::newer()]);

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
        $http = new FakeHttpClient([self::URL => FakeHttpClient::status(self::URL, 404, $body)]);

        [$code, $stdout, $stderr] = $this->runCommand($this->command($http, $this->installedPhar()), ['--check' => true]);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('lockrot: no published release found', $stderr);
    }

    public function testTheCommandIsNamedSelfUpdateAndAliasesComposersOwnSpelling(): void
    {
        $command = $this->command(self::http([Version::STRING]), $this->installedPhar());

        self::assertSame('self-update', $command->getName());
        self::assertContains('selfupdate', $command->getAliases());
    }

    /**
     * The release list is read from wherever the command was pointed, not from the constant.
     * bin/lockrot passes LOCKROT_RELEASE_URL through here, which is how the end-to-end test serves
     * its own releases without reaching api.github.com.
     */
    public function testTheReleaseListIsReadFromTheConfiguredUrl(): void
    {
        $url = 'https://releases.example.test/lockrot/releases.json';
        $http = self::http([Version::STRING], [], $url);
        $tester = new CommandTester($this->command($http, $this->installedPhar(), $url));

        $code = $tester->execute(['--check' => true]);

        self::assertSame(0, $code, $tester->getDisplay());
        self::assertSame([$url], $http->requested());
    }

    /**
     * Pinned: the facts a user needs (the source, the major line, the checksum, the signature, the
     * PHP floor, the flag, the writable directory, the untouched archive on failure), in that order,
     * followed by the three example lines. Rewording around those phrases passes; dropping one of
     * them, or moving an example above the explanation, fails.
     */
    public function testTheHelpExplainsTheUpdateBeforeGivingTheExamples(): void
    {
        $help = $this->command(self::http([Version::STRING]), $this->installedPhar())->getHelp();

        $offset = 0;
        foreach ([
            'from GitHub',
            'same major version',
            'sha256 published beside it',
            'against its signature',
            'key built into this archive',
            'newer PHP',
            '--allow-major',
            'one at a time',
            'has to be writable',
            'leaves the running archive exactly as it was',
            "  php lockrot.phar self-update\n",
            "  php lockrot.phar self-update --check\n",
            '  php lockrot.phar self-update --allow-major',
        ] as $fragment) {
            $at = strpos($help, $fragment, $offset);
            self::assertNotFalse($at, 'the help is missing "'.$fragment.'" after offset '.$offset.': '.$help);
            $offset = $at + \strlen($fragment);
        }
    }

    /**
     * Anything that is not a ConfigException is still exit 2, and still one line on stderr — the
     * command is the outermost frame of the PHAR, so an unhandled failure here would otherwise
     * reach the user as a stack trace.
     */
    public function testAFailureThatIsNotAConfigErrorIsStillOneLineAndExitTwo(): void
    {
        $command = $this->registered(new SelfUpdateCommand(
            static function (): array {
                throw new \RuntimeException('the downloader could not be built');
            },
            $this->validator(),
            $this->installedPhar()
        ));

        [$code, $stdout, $stderr] = $this->runCommand($command, [], true);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertMatchesRegularExpression(
            self::styledWhole('lockrot self-update failed: the downloader could not be built'),
            $stderr
        );
    }

    /** The same for the errors lockrot raises itself, which is every failure a user normally sees. */
    public function testOnATerminalTheWholeErrorIsStyledAndNotJustItsPrefix(): void
    {
        $body = file_get_contents(self::FIXTURES.'/not-found.json');
        self::assertIsString($body);
        $http = new FakeHttpClient([self::URL => FakeHttpClient::status(self::URL, 404, $body)]);

        [$code, , $stderr] = $this->runCommand($this->command($http, $this->installedPhar()), ['--check' => true], true);

        self::assertSame(2, $code);
        self::assertMatchesRegularExpression(self::styledWhole('lockrot: no published release found'), $stderr);
    }
}
