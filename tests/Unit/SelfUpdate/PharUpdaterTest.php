<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\SelfUpdate;

use Composer\Semver\Comparator;
use Composer\Util\Platform;
use Lockrot\Clock;
use Lockrot\Exception\ConfigException;
use Lockrot\SelfUpdate\PharUpdater;
use Lockrot\SelfUpdate\PharValidatorInterface;
use Lockrot\SelfUpdate\Release;
use Lockrot\SelfUpdate\ReleaseSignatureVerifier;
use Lockrot\Tests\Support\FakeHttpClient;
use Lockrot\Tests\Support\SigningKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class PharUpdaterTest extends TestCase
{
    private const PHAR_URL = 'https://github.com/somework/lockrot/releases/download/v0.2.0/lockrot.phar';
    private const CHECKSUM_URL = 'https://github.com/somework/lockrot/releases/download/v0.2.0/lockrot.phar.sha256';
    private const SIGNATURE_URL = 'https://github.com/somework/lockrot/releases/download/v0.2.0/lockrot.phar.sig';
    private const NEW_PHAR = 'the bytes of a newer lockrot.phar';
    private const INSTALLED = 'the bytes of the running lockrot.phar';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            @chmod($dir, 0755);
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

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-selfupdate-'.uniqid('', true);
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /** @return string the path of the installed "phar" */
    private function installedPhar(string $dir, int $mode = 0755): string
    {
        $path = $dir.'/lockrot.phar';
        file_put_contents($path, self::INSTALLED);
        chmod($path, $mode);

        return $path;
    }

    private function release(string $version = '0.2.0'): Release
    {
        return new Release($version, 'v'.$version, self::PHAR_URL, self::CHECKSUM_URL, self::SIGNATURE_URL);
    }

    /**
     * The three downloads of a release, with the signature the release key would publish for the
     * archive body unless $signatureBody says otherwise.
     */
    private function http(string $checksumBody, string $pharBody = self::NEW_PHAR, ?string $signatureBody = null): FakeHttpClient
    {
        return new FakeHttpClient([
            self::CHECKSUM_URL => FakeHttpClient::ok(self::CHECKSUM_URL, $checksumBody),
            self::SIGNATURE_URL => FakeHttpClient::ok(self::SIGNATURE_URL, $signatureBody ?? SigningKeys::releaseSignatureFile($pharBody)),
            self::PHAR_URL => FakeHttpClient::ok(self::PHAR_URL, $pharBody),
        ]);
    }

    private static function signedOk(): \Lockrot\Data\Http\HttpResult
    {
        return FakeHttpClient::ok(self::SIGNATURE_URL, SigningKeys::releaseSignatureFile(self::NEW_PHAR));
    }

    private static function checksumFileFor(string $body, string $name = 'lockrot.phar'): string
    {
        return hash('sha256', $body).'  '.$name."\n";
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

    private function updater(FakeHttpClient $http, string $phar, ?string $validationError = null, string $installedVersion = '0.1.0', ?Clock $clock = null): PharUpdater
    {
        return new PharUpdater($http, $this->validator($validationError), new ReleaseSignatureVerifier(SigningKeys::releasePublicPem()), $phar, $installedVersion, $clock);
    }

    /** @return list<string> every file in $dir, sorted */
    private static function filesIn(string $dir): array
    {
        $entries = scandir($dir);
        $found = [];
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $found[] = $entry;
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Asserts that $message carries each of $fragments, in this order and without overlapping.
     *
     * The messages below are built by concatenation, and a reader needs every part of them in the
     * right place: the path before what is wrong with it, the URL before what to do with it. This
     * says that much without pinning the wording in between.
     *
     * @param list<string> $fragments
     */
    private static function assertFragmentsInOrder(array $fragments, string $message): void
    {
        $offset = 0;
        foreach ($fragments as $fragment) {
            $at = strpos($message, $fragment, $offset);
            self::assertNotFalse($at, 'expected "'.$fragment.'" after offset '.$offset.' in: '.$message);
            $offset = $at + \strlen($fragment);
        }
    }

    public function testANewerReleaseIsAnAvailableUpdate(): void
    {
        $dir = $this->tempDir();
        $updater = $this->updater(new FakeHttpClient(), $this->installedPhar($dir));

        self::assertTrue($updater->isUpdateAvailable($this->release('0.2.0')));
        self::assertFalse($updater->isUpdateAvailable($this->release('0.1.0')));
        self::assertFalse($updater->isUpdateAvailable($this->release('0.0.9')));
    }

    public function testAnUpToDateInstallDownloadsNothingAndWritesNothing(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = $this->http(self::checksumFileFor(self::NEW_PHAR));

        $message = $this->updater($http, $phar)->update($this->release('0.1.0'), false);

        self::assertNull($message);
        self::assertSame([], $http->requested());
        self::assertSame(self::INSTALLED, file_get_contents($phar));
        self::assertSame(['lockrot.phar'], self::filesIn($dir));
    }

    /**
     * Every one of the nine permission bits is carried over, 0755 included: an install whose
     * `lockrot.phar` came back without the bits its group and everyone else had would still run for
     * the user who updated it and for nobody else.
     *
     * @dataProvider carriedPermissions
     */
    #[DataProvider('carriedPermissions')]
    public function testANewerReleaseReplacesTheRunningPharAndKeepsItsPermissions(int $mode): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir, $mode);
        $http = $this->http(self::checksumFileFor(self::NEW_PHAR));

        $message = $this->updater($http, $phar)->update($this->release('0.2.0'), false);

        self::assertSame('lockrot updated from 0.1.0 to 0.2.0', $message);
        self::assertSame(self::NEW_PHAR, file_get_contents($phar));
        self::assertSame(['lockrot.phar'], self::filesIn($dir), 'the temporary file must be gone');
        clearstatcache(true, $phar);
        self::assertSame($mode, fileperms($phar) & 0777);
        self::assertSame([self::CHECKSUM_URL, self::SIGNATURE_URL, self::PHAR_URL], $http->requested());
    }

    /** @return array<string, array{0: int}> */
    public static function carriedPermissions(): array
    {
        return [
            'private to its owner' => [0700],
            'executable for everyone' => [0755],
        ];
    }

    public function testTheChecksumFileIsAcceptedWhateverNameFollowsTheHash(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = $this->http(self::checksumFileFor(self::NEW_PHAR, 'build/lockrot.phar'));

        self::assertSame('lockrot updated from 0.1.0 to 0.2.0', $this->updater($http, $phar)->update($this->release('0.2.0'), false));
        self::assertSame(self::NEW_PHAR, file_get_contents($phar));
    }

    /**
     * `sha256sum` writes lower case, but the hash is hex either way and some release pipelines
     * upper-case it. It is read whatever case it is published in, and compared in one case.
     */
    public function testAnUpperCaseChecksumIsReadAndComparedAllTheSame(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = $this->http(strtoupper(hash('sha256', self::NEW_PHAR)).'  lockrot.phar'."\n");

        self::assertSame('lockrot updated from 0.1.0 to 0.2.0', $this->updater($http, $phar)->update($this->release('0.2.0'), false));
        self::assertSame(self::NEW_PHAR, file_get_contents($phar));
    }

    public function testAChecksumMismatchReplacesNothingAndLeavesNoTemporaryFile(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = $this->http(self::checksumFileFor('something else entirely'));

        try {
            $this->updater($http, $phar)->update($this->release('0.2.0'), false);
            self::fail('a checksum mismatch must not be swallowed');
        } catch (ConfigException $e) {
            self::assertStringContainsString('checksum mismatch', $e->getMessage());
            self::assertStringContainsString(hash('sha256', 'something else entirely'), $e->getMessage());
        }

        self::assertSame(self::INSTALLED, file_get_contents($phar));
        self::assertSame(['lockrot.phar'], self::filesIn($dir));
    }

    public function testAChecksumFileWithoutAHashIsAnError(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = $this->http("404: Not Found\n");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage(self::CHECKSUM_URL.' holds no sha256 hash');
        $this->updater($http, $phar)->update($this->release('0.2.0'), false);
    }

    public function testAPharThatDoesNotValidateReplacesNothingAndLeavesNoTemporaryFile(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = $this->http(self::checksumFileFor(self::NEW_PHAR));

        try {
            $this->updater($http, $phar, 'internal corruption of phar')->update($this->release('0.2.0'), false);
            self::fail('an unreadable archive must not be installed');
        } catch (ConfigException $e) {
            self::assertFragmentsInOrder(
                ['is not a readable archive (', 'internal corruption of phar', '); nothing was replaced'],
                $e->getMessage()
            );
        }

        self::assertSame(self::INSTALLED, file_get_contents($phar));
        self::assertSame(['lockrot.phar'], self::filesIn($dir));
    }

    public function testADirectoryThatCannotBeWrittenIsReportedByNameBeforeAnythingIsDownloaded(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions');
        }
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = $this->http(self::checksumFileFor(self::NEW_PHAR));
        chmod($dir, 0555);

        try {
            $this->updater($http, $phar)->update($this->release('0.2.0'), false);
            self::fail('an unwritable directory must not be silently skipped');
        } catch (ConfigException $e) {
            self::assertFragmentsInOrder(
                [$dir, ' is not writable', self::PHAR_URL, ' by hand instead'],
                $e->getMessage()
            );
        } finally {
            chmod($dir, 0755);
        }

        self::assertSame([], $http->requested(), 'nothing is downloaded when the file could not be replaced anyway');
        self::assertSame(self::INSTALLED, file_get_contents($phar));
    }

    public function testAFailedDownloadNamesTheUrlAndReplacesNothing(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = new FakeHttpClient([
            self::CHECKSUM_URL => FakeHttpClient::ok(self::CHECKSUM_URL, self::checksumFileFor(self::NEW_PHAR)),
            self::SIGNATURE_URL => self::signedOk(),
            self::PHAR_URL => FakeHttpClient::status(self::PHAR_URL, 404, 'Not Found'),
        ]);

        try {
            $this->updater($http, $phar)->update($this->release('0.2.0'), false);
            self::fail('a failed download must not be swallowed');
        } catch (ConfigException $e) {
            self::assertSame('could not download '.self::PHAR_URL.': HTTP 404', $e->getMessage());
        }

        self::assertSame(self::INSTALLED, file_get_contents($phar));
        self::assertSame(['lockrot.phar'], self::filesIn($dir));
    }

    /**
     * A request that never reached a server has no status to report, so the transport's own reason
     * is what the message carries instead — `HTTP 0` would tell a reader nothing.
     */
    public function testADownloadThatNeverConnectedReportsTheTransportReasonRatherThanAStatus(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = new FakeHttpClient([
            self::CHECKSUM_URL => FakeHttpClient::ok(self::CHECKSUM_URL, self::checksumFileFor(self::NEW_PHAR)),
            self::SIGNATURE_URL => self::signedOk(),
            self::PHAR_URL => FakeHttpClient::transportFailure(self::PHAR_URL, 'Could not resolve host: github.com'),
        ]);

        try {
            $this->updater($http, $phar)->update($this->release('0.2.0'), false);
            self::fail('a transport failure must not be swallowed');
        } catch (ConfigException $e) {
            self::assertSame(
                'could not download '.self::PHAR_URL.': Could not resolve host: github.com',
                $e->getMessage()
            );
        }

        self::assertSame(self::INSTALLED, file_get_contents($phar));
        self::assertSame(['lockrot.phar'], self::filesIn($dir));
    }

    /**
     * The last step can still fail after a verified download — a target that cannot be written over
     * although its directory can. That must be reported, and must not leave the temporary archive
     * lying next to the PHAR.
     *
     * The message is matched whole because both paths in it are what a reader needs — what could
     * not be written, and what it would have been written from — and because that also pins the
     * name the staged archive is given: beside the PHAR, under its own name, ending in `.phar` so
     * the runtime will open it at all.
     */
    public function testAReplaceThatCannotCompleteIsReportedAndLeavesNoTemporaryFile(): void
    {
        $dir = $this->tempDir();
        // A non-empty directory in the target's place: writable parent, unwritable target.
        $target = $dir.'/lockrot.phar';
        mkdir($target, 0755);
        file_put_contents($target.'/occupied', 'x');
        $http = $this->http(self::checksumFileFor(self::NEW_PHAR));

        try {
            $this->updater($http, $target)->update($this->release('0.2.0'), false);
            self::fail('a failed replace must not be swallowed');
        } catch (ConfigException $e) {
            // rename() everywhere but on Windows, where the replace is a copy() instead.
            $verb = Platform::isWindows() ? 'could not copy ' : 'could not move ';
            self::assertMatchesRegularExpression(
                '~^'.preg_quote($verb.$target, '~').'\.\d+-\w+\.tmp\.phar onto '.preg_quote($target, '~').'$~',
                $e->getMessage()
            );
        } finally {
            @unlink($target.'/occupied');
            @rmdir($target);
        }

        self::assertSame([], self::filesIn($dir), 'the temporary file must be gone');
    }

    public function testForceOnTheSameVersionSaysReinstalledRatherThanUpdated(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = $this->http(self::checksumFileFor(self::NEW_PHAR));

        $message = $this->updater($http, $phar)->update($this->release('0.1.0'), true);

        self::assertSame('lockrot reinstalled 0.1.0', $message);
        self::assertSame(self::NEW_PHAR, file_get_contents($phar));
    }

    public function testForceOnAnOlderReleaseSaysWhatItReplacedWithWhat(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = $this->http(self::checksumFileFor(self::NEW_PHAR));

        $message = $this->updater($http, $phar, null, '0.2.0')->update($this->release('0.1.0'), true);

        self::assertSame('lockrot replaced 0.2.0 with 0.1.0', $message);
        self::assertSame(self::NEW_PHAR, file_get_contents($phar));
    }

    /**
     * A process killed between the write and the rename leaves its temporary archive behind for
     * good. The next install clears them out — but only the ones old enough that no other process
     * could still be writing one.
     */
    public function testAnInstallSweepsTemporaryArchivesLeftByAnInterruptedRun(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $stale = $dir.'/lockrot.phar.404-abandoned.tmp.phar';
        $inFlight = $dir.'/lockrot.phar.405-running.tmp.phar';
        $unrelated = $dir.'/notes.txt';
        foreach ([$stale, $inFlight, $unrelated] as $file) {
            file_put_contents($file, 'x');
        }
        touch($stale, time() - 2 * PharUpdater::STALE_TEMPORARY_SECONDS);
        $http = $this->http(self::checksumFileFor(self::NEW_PHAR));

        $this->updater($http, $phar)->update($this->release('0.2.0'), false);

        self::assertSame(['lockrot.phar', basename($inFlight), 'notes.txt'], self::filesIn($dir));
    }

    /**
     * The cutoff itself is not stale. A self-update that started exactly
     * {@see PharUpdater::STALE_TEMPORARY_SECONDS} ago may still be downloading into its temporary
     * archive, and deleting that would turn a working update into a failure — so the sweep takes
     * what is older than the cutoff, not what has reached it. The updater's clock is pinned, so the
     * boundary is exact rather than a race against the wall clock's whole seconds.
     */
    public function testAnArchiveExactlyAsOldAsTheCutoffIsNotSweptYet(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $now = 1789600000;
        $atCutoff = $dir.'/lockrot.phar.406-one-hour-old.tmp.phar';
        $justPast = $dir.'/lockrot.phar.407-one-hour-and-a-second-old.tmp.phar';
        file_put_contents($atCutoff, 'x');
        file_put_contents($justPast, 'x');
        touch($atCutoff, $now - PharUpdater::STALE_TEMPORARY_SECONDS);
        touch($justPast, $now - PharUpdater::STALE_TEMPORARY_SECONDS - 1);
        $clock = new Clock((new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('UTC')));

        $this->updater($this->http(self::checksumFileFor(self::NEW_PHAR)), $phar, null, '0.1.0', $clock)->update($this->release('0.2.0'), false);

        self::assertSame(['lockrot.phar', basename($atCutoff)], self::filesIn($dir), 'exactly at the cutoff stays, one second past it goes');
    }

    /**
     * A process running from a PHAR loses every class it has not already loaded the moment that file
     * is swapped, so the swap has to be the last step: the archive is staged and the message is
     * built while the old bytes are still readable.
     *
     * Watched at the seam the failure actually happened on. Composing the message is the only work
     * left between staging and the swap, and it is the one step that reaches for a class of its own,
     * `Composer\Semver\Comparator`. A prepended autoloader records what the target file held the
     * moment that class was resolved: the old bytes if the message came first, the new ones if the
     * swap did. `--force` is the case the report came from, because it skips isUpdateAvailable() and
     * so leaves the message as the run's first use of the comparison — hence the separate process,
     * where nothing has loaded it yet.
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    public function testTheMessageIsBuiltWhileTheOldArchiveIsStillInPlace(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = $this->http(self::checksumFileFor(self::NEW_PHAR));
        self::assertFalse(class_exists(Comparator::class, false), 'the comparison must still be unresolved here');

        $targetWhenCompared = null;
        $spy = static function (string $class) use (&$targetWhenCompared, $phar): void {
            if ($class === Comparator::class) {
                $targetWhenCompared = file_get_contents($phar);
            }
        };
        spl_autoload_register($spy, true, true);
        try {
            $message = $this->updater($http, $phar)->update($this->release('0.1.0'), true);
        } finally {
            spl_autoload_unregister($spy);
        }

        self::assertSame('lockrot reinstalled 0.1.0', $message);
        self::assertSame(self::INSTALLED, $targetWhenCompared, 'the message must be built before the archive is replaced');
        self::assertSame(self::NEW_PHAR, file_get_contents($phar));
    }

    /** The other half of the same order: the download is checked while the running archive is untouched. */
    public function testTheDownloadIsValidatedWhileTheOldArchiveIsStillInPlace(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        /** @var list<string> $seen */
        $seen = [];
        $validator = new class ($phar, $seen) implements PharValidatorInterface {
            private string $target;

            /** @var list<string> */
            public array $seen;

            /** @param list<string> $seen */
            public function __construct(string $target, array $seen)
            {
                $this->target = $target;
                $this->seen = $seen;
            }

            public function validate(string $path): ?string
            {
                $this->seen[] = (string) file_get_contents($this->target);

                return null;
            }
        };

        $message = (new PharUpdater($this->http(self::checksumFileFor(self::NEW_PHAR)), $validator, new ReleaseSignatureVerifier(SigningKeys::releasePublicPem()), $phar, '0.1.0'))
            ->update($this->release('0.2.0'), false);

        self::assertSame('lockrot updated from 0.1.0 to 0.2.0', $message);
        self::assertSame([self::INSTALLED], $validator->seen);
        self::assertSame(self::NEW_PHAR, file_get_contents($phar));
    }

    public function testTheDownloadIsNotSentTheGithubApiAcceptHeaderOrAToken(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = $this->http(self::checksumFileFor(self::NEW_PHAR));

        $this->updater($http, $phar)->update($this->release('0.2.0'), false);

        foreach ($http->headers() as $headers) {
            self::assertSame(['User-Agent: lockrot'], $headers);
        }
    }

    /**
     * A release signed with a key this build does not know is not installed, whatever its
     * checksum says: the checksum proves the download arrived intact, the signature proves who
     * published it, and only the second one stops a substituted release.
     */
    public function testASignatureByAnotherKeyReplacesNothingAndLeavesNoTemporaryFile(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = $this->http(self::checksumFileFor(self::NEW_PHAR), self::NEW_PHAR, SigningKeys::otherSignatureFile(self::NEW_PHAR));

        try {
            $this->updater($http, $phar)->update($this->release('0.2.0'), false);
            self::fail('a signature by another key must not be accepted');
        } catch (ConfigException $e) {
            self::assertFragmentsInOrder([self::SIGNATURE_URL, 'does not match', 'nothing was written'], $e->getMessage());
        }

        self::assertSame(self::INSTALLED, file_get_contents($phar));
        self::assertSame(['lockrot.phar'], self::filesIn($dir));
    }

    /** The signature is checked before the archive is staged, so a forgery never touches the disk. */
    public function testTheSignatureIsCheckedBeforeAnythingIsWrittenOrValidated(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $validator = new class () implements PharValidatorInterface {
            public int $calls = 0;

            public function validate(string $path): ?string
            {
                ++$this->calls;

                return null;
            }
        };
        $http = $this->http(self::checksumFileFor(self::NEW_PHAR), self::NEW_PHAR, SigningKeys::otherSignatureFile(self::NEW_PHAR));

        try {
            (new PharUpdater($http, $validator, new ReleaseSignatureVerifier(SigningKeys::releasePublicPem()), $phar, '0.1.0'))
                ->update($this->release('0.2.0'), false);
            self::fail('a signature by another key must not be accepted');
        } catch (ConfigException $e) {
        }

        self::assertSame(0, $validator->calls);
        self::assertSame(['lockrot.phar'], self::filesIn($dir));
    }

    /**
     * A download that arrived damaged has a wrong checksum *and* a wrong signature. It is reported
     * as the former: that is what it is, and "re-download" is the fix — not "someone else signed
     * this".
     */
    public function testADamagedDownloadIsReportedAsAChecksumMismatchNotAsAForgery(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = $this->http(self::checksumFileFor(self::NEW_PHAR), 'a truncated download', SigningKeys::releaseSignatureFile(self::NEW_PHAR));

        try {
            $this->updater($http, $phar)->update($this->release('0.2.0'), false);
            self::fail('a damaged download must not be installed');
        } catch (ConfigException $e) {
            self::assertStringStartsWith('checksum mismatch for '.self::PHAR_URL, $e->getMessage());
        }

        self::assertSame(self::INSTALLED, file_get_contents($phar));
    }

    public function testAFailedSignatureDownloadNamesTheUrlAndReplacesNothing(): void
    {
        $dir = $this->tempDir();
        $phar = $this->installedPhar($dir);
        $http = new FakeHttpClient([
            self::CHECKSUM_URL => FakeHttpClient::ok(self::CHECKSUM_URL, self::checksumFileFor(self::NEW_PHAR)),
            self::SIGNATURE_URL => FakeHttpClient::status(self::SIGNATURE_URL, 404, 'Not Found'),
            self::PHAR_URL => FakeHttpClient::ok(self::PHAR_URL, self::NEW_PHAR),
        ]);

        try {
            $this->updater($http, $phar)->update($this->release('0.2.0'), false);
            self::fail('a missing signature must not be swallowed');
        } catch (ConfigException $e) {
            self::assertSame('could not download '.self::SIGNATURE_URL.': HTTP 404', $e->getMessage());
        }

        self::assertSame(self::INSTALLED, file_get_contents($phar));
        self::assertSame(['lockrot.phar'], self::filesIn($dir));
    }
}
