<?php

declare(strict_types=1);

namespace Lockrot\Tests\E2E;

use Lockrot\SelfUpdate\ReleaseKey;
use Lockrot\Tests\Support\GitHubReleases;
use Lockrot\Tests\Support\SigningKeys;
use Lockrot\Tests\Support\StaticFileServer;
use Lockrot\Version;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** @group e2e */
#[Group('e2e')]
final class PharTest extends TestCase
{
    private const SIGNED_BY_RELEASE_KEY = 'release';
    private const SIGNED_BY_OTHER_KEY = 'other';

    private static ?StaticFileServer $server = null;
    private static ?string $docroot = null;
    private static ?string $composerHome = null;

    /**
     * The version the release server offers under `/release`: ahead of the build and in its major
     * version, so it is always an update the archive takes without --allow-major.
     */
    private static function offeredVersion(): string
    {
        return GitHubReleases::newerInLine(Version::STRING);
    }

    private function phar(): string
    {
        $phar = \dirname(__DIR__, 2).'/build/lockrot.phar';
        if (!is_file($phar)) {
            self::markTestSkipped('build/lockrot.phar not built; run build/build-phar.sh');
        }

        return $phar;
    }

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempDirs) as $dir) {
            self::removeTree($dir);
        }
        $this->tempDirs = [];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
            self::$server = null;
        }
        foreach ([self::$docroot, self::$composerHome] as $dir) {
            if ($dir !== null) {
                self::removeTree($dir);
            }
        }
        self::$docroot = null;
        self::$composerHome = null;
    }

    private static function removeTree(string $dir): void
    {
        $entries = is_dir($dir) ? scandir($dir) : false;
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) && !is_link($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * @param list<string> $arguments
     * @param ?string      $cwd       run the PHAR from here instead of the repository root
     */
    private function runPhar(array $arguments, ?string $cwd = null): Process
    {
        $process = new Process(array_merge(['php', $this->phar()], $arguments), $cwd);
        $process->setTimeout(300)->run();

        return $process;
    }

    public function testPharRunsOnFixtureDirectory(): void
    {
        $phar = $this->phar();
        $process = new Process(['php', $phar, '-d', \dirname(__DIR__).'/fixtures/skeletons/laravel', '--format=json', '--target-php=8.4']);
        $process->setTimeout(300)->run();
        $json = json_decode($process->getOutput(), true);
        self::assertIsArray($json, $process->getErrorOutput());
        $counts = $json['counts'];
        self::assertIsArray($counts);
        self::assertSame(0, $counts['abandoned']);
    }

    public function testListShowsOnlyTheTwoLockrotCommands(): void
    {
        $process = $this->runPhar(['list']);
        $output = $process->getOutput();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('self-update', $output);
        self::assertStringContainsString('lockrot', $output);
        // The PHAR is a Composer application; without an explicit command list it would also offer
        // to install, update and require packages in whatever project it is pointed at.
        foreach (['install', 'update', 'require', 'remove', 'dump-autoload'] as $composerCommand) {
            self::assertStringNotContainsString("\n  ".$composerCommand.' ', $output, $composerCommand.' must not be reachable from lockrot.phar');
        }
    }

    /**
     * Composer's application turns the scripts of whatever project it is run in into commands of
     * its own. This runs from lockrot's own checkout, whose composer.json defines `cs`, so a build
     * that stopped filtering registrations would list and run it.
     */
    public function testAProjectScriptIsNotAReachableCommand(): void
    {
        $listed = $this->runPhar(['list']);
        $invoked = $this->runPhar(['cs']);

        self::assertStringNotContainsString("\n  cs ", $listed->getOutput());
        self::assertNotSame(0, $invoked->getExitCode());
        self::assertStringContainsString('Command "cs" is not defined', $invoked->getOutput().$invoked->getErrorOutput());
    }

    /**
     * A project whose `scripts` map a name to a class name. Composer >= 2.9 registers the project's
     * own autoloader and calls `class_exists()` on that value, which loads the project's file and
     * runs whatever sits at its top level; here that writes `marker.txt`. lockrot reads a project,
     * it never executes one, so running the PHAR in this directory must leave no marker behind.
     *
     * @return array{0: string, 1: string} project directory, marker path
     */
    private function projectWithAClassScript(): array
    {
        $dir = sys_get_temp_dir().'/lockrot-script-class-'.uniqid('', true);
        if (!mkdir($dir.'/src', 0755, true) && !is_dir($dir.'/src')) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;
        $marker = $dir.'/marker.txt';
        file_put_contents($dir.'/composer.json', (string) json_encode([
            'name' => 'acme/script-class',
            'autoload' => ['psr-4' => ['Acme\\' => 'src/']],
            'scripts' => ['marker' => 'Acme\\Marker'],
        ]));
        file_put_contents($dir.'/composer.lock', (string) json_encode(['packages' => [], 'packages-dev' => []]));
        file_put_contents(
            $dir.'/src/Marker.php',
            "<?php\n\nnamespace Acme;\n\nfile_put_contents(__DIR__.'/../marker.txt', 'the project was loaded');\n\nclass Marker extends \\Symfony\\Component\\Console\\Command\\Command\n{\n}\n"
        );

        return [$dir, $marker];
    }

    public function testRunningInAProjectNeverLoadsItsScriptClasses(): void
    {
        [$dir, $marker] = $this->projectWithAClassScript();

        $process = $this->runPhar(['--format=json', '--offline'], $dir);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertIsArray(json_decode($process->getOutput(), true), $process->getErrorOutput());
        self::assertFileDoesNotExist($marker, 'lockrot must not load the inspected project\'s classes');
    }

    public function testTheAliasAlsoNeverLoadsAProjectsScriptClasses(): void
    {
        [$dir, $marker] = $this->projectWithAClassScript();

        $process = $this->runPhar(['rot', '--format=json', '--offline'], $dir);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertFileDoesNotExist($marker, 'lockrot must not load the inspected project\'s classes');
    }

    public function testListingCommandsNeverLoadsAProjectsScriptClasses(): void
    {
        [$dir, $marker] = $this->projectWithAClassScript();

        $process = $this->runPhar(['list'], $dir);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertFileDoesNotExist($marker, 'lockrot must not load the inspected project\'s classes');
    }

    public function testSelfUpdateHelpIsAvailable(): void
    {
        $process = $this->runPhar(['self-update', '--help']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('--check', $process->getOutput());
        self::assertStringContainsString('--force', $process->getOutput());
        self::assertStringContainsString('--allow-major', $process->getOutput());
    }

    public function testTheDefaultCommandStillRunsWithoutBeingNamed(): void
    {
        $process = $this->runPhar(['-d', \dirname(__DIR__).'/fixtures/skeletons/laravel', '--format=json', '--target-php=8.4', '--offline']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertIsArray(json_decode($process->getOutput(), true), $process->getErrorOutput());
    }

    /**
     * The PHAR runs the same command on Composer's application, so the warning renders there too:
     * one line on stderr in Composer's `<warning>` style rather than a literal tag, and stdout still
     * the report alone.
     */
    public function testThePharWarnsAboutAnUnknownKeyOnStderr(): void
    {
        $dir = $this->freshDir();
        file_put_contents($dir.'/composer.json', (string) json_encode([
            'name' => 'acme/unknown-key',
            'extra' => ['lockrot' => ['install-tme' => 'off', 'x-ci' => true, 'extensions' => ['acme/x' => ['k' => 1]]]],
        ]));
        file_put_contents($dir.'/composer.lock', (string) json_encode(['packages' => [], 'packages-dev' => []]));

        $process = $this->runPhar(['--format=json', '--offline'], $dir);

        $stderr = $process->getErrorOutput();
        self::assertSame(0, $process->getExitCode(), $stderr);
        self::assertIsArray(json_decode($process->getOutput(), true), $stderr);
        self::assertSame(1, substr_count($stderr, 'lockrot: unknown key extra.lockrot.install-tme ignored (did you mean install-time?)'), $stderr);
        self::assertSame(1, substr_count($stderr, 'extra.lockrot.'), $stderr);
        self::assertStringNotContainsString('<warning>', $stderr);
    }

    /**
     * The symfony/console inside the PHAR is where a key like `<<fg=red>>` broke the run: escaped by
     * OutputFormatter::escape() and handed to the formatter, it threw `Invalid "red>" color`, exit 2
     * with nothing on stdout. Written raw, the key prints as written — in the warning, and in the
     * config error that quotes it when the schema rejects the config.
     */
    public function testAKeyThatLooksLikeMarkupPrintsAsWrittenFromThePhar(): void
    {
        $dir = $this->freshDir();
        file_put_contents($dir.'/composer.lock', (string) json_encode(['packages' => [], 'packages-dev' => []]));
        file_put_contents($dir.'/composer.json', (string) json_encode(['name' => 'acme/markup-key', 'extra' => ['lockrot' => ['<<fg=red>>' => 1, '<<href=https://example.com>>' => 1]]]));

        $process = $this->runPhar(['--format=json', '--offline'], $dir);

        $stderr = $process->getErrorOutput();
        self::assertSame(0, $process->getExitCode(), $stderr);
        self::assertIsArray(json_decode($process->getOutput(), true), $stderr);
        self::assertSame(
            "lockrot: unknown key extra.lockrot.<<fg=red>> ignored\nlockrot: unknown key extra.lockrot.<<href=https://example.com>> ignored\n",
            $stderr
        );

        file_put_contents($dir.'/composer.json', (string) json_encode(['name' => 'acme/markup-key', 'extra' => ['lockrot' => ['<<fg=red>>' => 1, 'fail-on' => 'dead']]]));

        $failed = $this->runPhar(['--offline'], $dir);

        self::assertSame(2, $failed->getExitCode(), $failed->getErrorOutput());
        self::assertSame('', $failed->getOutput());
        self::assertStringStartsWith('lockrot: extra.lockrot is invalid:', $failed->getErrorOutput());
        self::assertStringEndsWith("\n  - unknown key extra.lockrot.<<fg=red>> ignored\n", $failed->getErrorOutput());
    }

    /**
     * A real self-update, end to end: a real PHAR replaces itself with a different, valid archive
     * and then has to finish. Everything up to the swap is covered by unit tests; what only a
     * separate process can show is what happens afterwards, when the code the running archive still
     * has to load is no longer the code its manifest describes.
     *
     * The replacement is deliberately `minimal.phar` — a few hundred bytes with nothing of lockrot
     * in it — because an archive that happens to contain the same classes at the same offsets would
     * hide the failure this test exists for.
     */
    public function testSelfUpdateFinishesCleanlyAfterReplacingTheRunningArchive(): void
    {
        $directory = $this->freshDir();
        $target = $this->installedCopy($directory);

        $process = $this->runSelfUpdate($target, 'release');

        self::assertSame('', $process->getOutput(), 'self-update writes nothing to stdout');
        self::assertSame(
            'lockrot updated from '.Version::STRING.' to '.self::offeredVersion()."\n",
            self::reported($process),
            'the update must report one line and nothing else'
        );
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(
            hash_file('sha256', self::minimalPhar()),
            hash_file('sha256', $target),
            'the running archive must have been replaced by the published one'
        );
        clearstatcache(true, $target);
        self::assertSame(0755, fileperms($target) & 0777, 'the executable bit must survive the replace');
        self::assertSame([], glob($directory.'/*.tmp.phar') ?: [], 'no temporary archive may be left behind');
    }

    /**
     * `--force` onto the bytes already installed. This is the path the 0.1.0 release was verified
     * with, and the one that cannot go wrong on its own: the archive is rewritten with what it
     * already held, so every class stays loadable whatever order the code runs in.
     */
    public function testForcingAReinstallOfTheSameBytesAlsoFinishesCleanly(): void
    {
        $directory = $this->freshDir();
        $target = $this->installedCopy($directory);

        $process = $this->runSelfUpdate($target, 'same', ['--force']);

        self::assertSame('', $process->getOutput());
        self::assertSame('lockrot reinstalled '.Version::STRING."\n", self::reported($process));
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(hash_file('sha256', $this->phar()), hash_file('sha256', $target));
        self::assertSame([], glob($directory.'/*.tmp.phar') ?: []);
    }

    /**
     * The built archive enforcing its own signature check end to end: a release whose `.sig.json` is by
     * a key the archive does not trust is reported on one line, exits 2, and leaves the running
     * archive — bytes and permissions — and its directory exactly as they were.
     *
     * The channel's description claims the key the archive trusts, which is what a doctored
     * `lockrot.phar.meta.json` would do: the description only chooses what is tried, and the
     * signature still decides.
     */
    public function testSelfUpdateRefusesAReleaseSignedWithAnotherKey(): void
    {
        $directory = $this->freshDir();
        $target = $this->installedCopy($directory);
        $before = hash_file('sha256', $target);

        $process = $this->runSelfUpdate($target, 'forged');

        self::assertSame('', $process->getOutput());
        self::assertSame(2, $process->getExitCode(), $process->getErrorOutput());
        $reported = self::reported($process);
        self::assertStringContainsString(
            'lockrot: the signature in '.self::server()->url().'/forged/v'.self::offeredVersion().'/lockrot.phar.sig.json does not match',
            $reported
        );
        self::assertStringEndsWith("nothing was written\n", $reported);
        self::assertSame(1, substr_count($reported, "\n"), 'one line and nothing else');
        self::assertSame($before, hash_file('sha256', $target), 'the running archive must be untouched');
        self::assertSame([$target], glob($directory.'/*') ?: [], 'nothing else may have been written next to it');
    }

    /**
     * Without the seam, the archive verifies with the key built into it — which proves that key
     * is packed and loads twice over. Its fingerprint matches the one the channel's description
     * names, so the release is tried rather than passed over; and the release, signed with the test
     * key, is then "does not match", never "the key cannot be loaded". A box.json that stopped
     * packing ReleaseKey.php would fail here.
     */
    public function testWithoutTheSeamTheBuiltInKeyIsWhatTheArchiveVerifiesWith(): void
    {
        $directory = $this->freshDir();
        $target = $this->installedCopy($directory);

        $process = $this->runSelfUpdate($target, 'builtin', [], false);

        self::assertSame(2, $process->getExitCode(), $process->getErrorOutput());
        $reported = self::reported($process);
        self::assertStringContainsString('does not match the downloaded archive', $reported);
        self::assertStringNotContainsString('cannot be loaded', $reported);
        self::assertStringNotContainsString('does not carry', $reported, 'the built-in fingerprint must match the description');
        self::assertSame(hash_file('sha256', $this->phar()), hash_file('sha256', $target));
    }

    /**
     * A new major version is not installed without --allow-major: the archive says so on one line,
     * says it is up to date on the next, exits 0 and stays exactly as it was.
     */
    public function testTheBuiltArchiveHoldsBackANewMajor(): void
    {
        $directory = $this->freshDir();
        $target = $this->installedCopy($directory);

        $process = $this->runSelfUpdate($target, 'major');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(
            'lockrot '.GitHubReleases::nextMajor(Version::STRING).' is in the next major version; run lockrot.phar self-update --allow-major to move to it'."\n"
            .'lockrot '.Version::STRING." is up to date\n",
            self::reported($process)
        );
        self::assertSame(hash_file('sha256', $this->phar()), hash_file('sha256', $target));
    }

    /**
     * A key rotation, with the test keys standing in: the newest release is signed with a key the
     * archive does not carry, the one before it — the transition release — with the key it does. The
     * archive passes over the first, names it, and installs the second.
     */
    public function testTheBuiltArchiveStepsThroughATransitionRelease(): void
    {
        $directory = $this->freshDir();
        $target = $this->installedCopy($directory);

        $process = $this->runSelfUpdate($target, 'rotated');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(
            'lockrot '.self::offeredVersion().' is signed with a self-update key this lockrot.phar does not carry ('
            .GitHubReleases::fingerprint(SigningKeys::otherPublicPem()).'); only a release that carries that key can update to it'."\n"
            .'lockrot updated from '.Version::STRING.' to '.self::transitionVersion()."\n",
            self::reported($process)
        );
        self::assertSame(hash_file('sha256', self::minimalPhar()), hash_file('sha256', $target));
    }

    /**
     * The same rotation with its transition release missing: the archive names the release it
     * cannot verify, then says it is stranded and has to be reinstalled by hand — exit 2, for
     * `--check` too, and the running archive untouched.
     */
    public function testTheBuiltArchiveSaysWhenARotationStrandsIt(): void
    {
        $directory = $this->freshDir();
        $target = $this->installedCopy($directory);
        $expected = 'lockrot '.self::offeredVersion().' is signed with a self-update key this lockrot.phar does not carry ('
            .GitHubReleases::fingerprint(SigningKeys::otherPublicPem()).'); only a release that carries that key can update to it'."\n"
            .'lockrot: the newer releases are signed with a self-update key this lockrot.phar does not carry, and no release it can install carries that key; download lockrot.phar again by hand and verify it (see https://lockrot.dev/phar/#reinstalling-by-hand)'."\n";

        foreach ([[], ['--check']] as $options) {
            $process = $this->runSelfUpdate($target, 'stranded', $options);

            self::assertSame(2, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame($expected, self::reported($process));
        }
        self::assertSame(hash_file('sha256', $this->phar()), hash_file('sha256', $target));
    }

    /** The transition release of the `rotated` channel: in the line, just below the offered one. */
    private static function transitionVersion(): string
    {
        return ((int) Version::STRING).'.998.0';
    }

    /**
     * What lockrot itself said on stderr. Exactly one line is dropped: Composer warns once per run
     * that it is reaching 127.0.0.1 over plain http, which the test harness asked for and a real
     * install never sees. Nothing else is filtered, so a notice, a warning or a trace from the PHAR
     * still fails the assertion it lands in.
     */
    private static function reported(Process $process): string
    {
        return str_replace(
            "Warning: Accessing 127.0.0.1 over http which is an insecure protocol.\n",
            '',
            $process->getErrorOutput()
        );
    }

    /**
     * @param list<string> $options
     * @param bool         $testKey whether the archive verifies against the test key the channels are
     *                              signed with (the seam), or against the key built into it
     */
    private function runSelfUpdate(string $target, string $channel, array $options = [], bool $testKey = true): Process
    {
        $environment = [
            'LOCKROT_RELEASE_URL' => self::server()->url().'/'.$channel.'/releases.json',
            // The release server is plain http on 127.0.0.1, which Composer's HttpDownloader
            // refuses under its default secure-http. Relaxing it in a throwaway COMPOSER_HOME
            // keeps that to the test process; nothing in lockrot itself lowers the bar.
            'COMPOSER_HOME' => self::composerHome(),
        ];
        if ($testKey) {
            // The channels are signed with the test key, not the release key built into the
            // archive; this is the seam that lets the built PHAR verify them.
            $environment['LOCKROT_RELEASE_KEY'] = \dirname(__DIR__).'/fixtures/signing/release-key.pub';
        }
        $process = new Process(array_merge(['php', $target, 'self-update'], $options), \dirname($target), $environment);
        $process->setTimeout(300)->run();

        return $process;
    }

    /** Copies the built PHAR into $directory under the name a real install carries. */
    private function installedCopy(string $directory): string
    {
        $target = $directory.'/lockrot.phar';
        if (!copy($this->phar(), $target) || !chmod($target, 0755)) {
            throw new \RuntimeException('cannot stage a lockrot.phar in '.$directory);
        }

        return $target;
    }

    private function freshDir(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-selfupdate-e2e-'.uniqid('', true);
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private static function minimalPhar(): string
    {
        return \dirname(__DIR__).'/fixtures/phar/minimal.phar';
    }

    /**
     * One server for the whole class, started on first use so a run without a built PHAR skips
     * without ever binding a port. Each channel is a release list of its own: `/release` offers the
     * small fixture archive as an update, `/same` the built PHAR itself at its own version, and the
     * rest one case each of what the archive must refuse or pass over.
     */
    private static function server(): StaticFileServer
    {
        if (self::$server !== null) {
            return self::$server;
        }
        $docroot = sys_get_temp_dir().'/lockrot-release-server-'.uniqid('', true);
        if (!mkdir($docroot, 0755, true) && !is_dir($docroot)) {
            throw new \RuntimeException('cannot create temp dir: '.$docroot);
        }
        self::$docroot = $docroot;
        $server = StaticFileServer::serving($docroot);
        $releaseKey = GitHubReleases::fingerprint(SigningKeys::releasePublicPem());
        $otherKey = GitHubReleases::fingerprint(SigningKeys::otherPublicPem());
        $built = \dirname(__DIR__, 2).'/build/lockrot.phar';
        $minimal = self::minimalPhar();
        $channels = [
            'release' => [[$minimal, self::offeredVersion(), self::SIGNED_BY_RELEASE_KEY, $releaseKey]],
            'same' => [[$built, Version::STRING, self::SIGNED_BY_RELEASE_KEY, $releaseKey]],
            // A substituted release whose description lies about its key: it claims the one the
            // archive trusts, so the signature check is what refuses it.
            'forged' => [[$minimal, self::offeredVersion(), self::SIGNED_BY_OTHER_KEY, $releaseKey]],
            // Signed with the test key, described as signed with the key built into the archive,
            // for the one test that runs without the key seam.
            'builtin' => [[$minimal, self::offeredVersion(), self::SIGNED_BY_RELEASE_KEY, GitHubReleases::fingerprint(ReleaseKey::PEM)]],
            'major' => [[$minimal, GitHubReleases::nextMajor(Version::STRING), self::SIGNED_BY_RELEASE_KEY, $releaseKey]],
            'rotated' => [
                [$minimal, self::offeredVersion(), self::SIGNED_BY_OTHER_KEY, $otherKey],
                [$minimal, self::transitionVersion(), self::SIGNED_BY_RELEASE_KEY, $releaseKey],
            ],
            'stranded' => [[$minimal, self::offeredVersion(), self::SIGNED_BY_OTHER_KEY, $otherKey]],
        ];
        foreach ($channels as $channel => $releases) {
            self::publishChannel($docroot, $server->url(), $channel, $releases);
        }
        $server->start();
        self::$server = $server;

        return $server;
    }

    /**
     * Writes one channel: for each release, the archive, its `sha256sum` file, its signature and its
     * `lockrot.phar.meta.json` under `<channel>/v<version>/`, and the release list naming them all
     * as `<channel>/releases.json`. A signature is by the test release key, or — for a channel that
     * stands in for a substituted or rotated release — by a key of the same shape that is not it; the
     * description names whichever key the channel says, which need not be the signer.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $releases archive, version, signer, described key
     */
    private static function publishChannel(string $docroot, string $url, string $channel, array $releases): void
    {
        $entries = [];
        foreach ($releases as [$archive, $version, $signer, $describedKey]) {
            $tag = 'v'.$version;
            $directory = $docroot.'/'.$channel.'/'.$tag;
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException('cannot create temp dir: '.$directory);
            }
            if (!copy($archive, $directory.'/lockrot.phar')) {
                throw new \RuntimeException('cannot publish '.$archive);
            }
            $hash = hash_file('sha256', $archive);
            file_put_contents($directory.'/lockrot.phar.sha256', $hash.'  lockrot.phar'."\n");
            $bytes = (string) file_get_contents($archive);
            file_put_contents(
                $directory.'/lockrot.phar.sig.json',
                $signer === self::SIGNED_BY_RELEASE_KEY ? SigningKeys::releaseSignatureFile($bytes) : SigningKeys::otherSignatureFile($bytes)
            );
            file_put_contents($directory.'/lockrot.phar.meta.json', GitHubReleases::meta('7.4.0', $describedKey));
            $entries[] = GitHubReleases::entry($tag, GitHubReleases::ASSETS, false, false, $url.'/'.$channel.'/');
        }
        file_put_contents($docroot.'/'.$channel.'/releases.json', GitHubReleases::listJson($entries));
    }

    private static function composerHome(): string
    {
        if (self::$composerHome !== null) {
            return self::$composerHome;
        }
        $home = sys_get_temp_dir().'/lockrot-composer-home-'.uniqid('', true);
        if (!mkdir($home, 0755, true) && !is_dir($home)) {
            throw new \RuntimeException('cannot create temp dir: '.$home);
        }
        file_put_contents($home.'/config.json', '{"config": {"secure-http": false}}');
        self::$composerHome = $home;

        return $home;
    }
}
