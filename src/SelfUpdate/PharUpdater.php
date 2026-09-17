<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

use Composer\Semver\Comparator;
use Composer\Util\Platform;
use Lockrot\Clock;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Exception\ConfigException;
use Lockrot\Version;

/**
 * Downloads a {@see Release}, verifies it against its published sha256 and its signature, and replaces the running
 * PHAR with it.
 *
 * The shape of the replace follows Composer's own self-update: write next to the target, carry the
 * target's permissions onto the new file, check that the runtime can open it, then `rename()` —
 * except on Windows, where `copy()` + `unlink()` is used because `rename()` keeps the source file's
 * permissions and can lock other users out (Composer's own reason).
 *
 * Two things this deliberately does not do:
 *
 * - No cache-directory fallback for the temporary file. It is written beside the PHAR or not at
 *   all, so a successful download is always one `rename()` away from being installed and an
 *   unwritable directory is reported before anything is fetched.
 * - No backup of the replaced PHAR, and so no rollback. Every previous release stays downloadable
 *   from GitHub, which is the documented way back.
 *
 * On any failure the running PHAR is left exactly as it was and the temporary file is removed.
 *
 * The order of the steps is part of the contract, not an accident. A PHP process that is executing
 * a PHAR keeps reading further classes out of that file on demand, against the manifest it read at
 * startup; once the file underneath has been swapped for a different archive, every class the
 * process has not already loaded is gone. So the swap is the last thing that touches the runtime:
 * the temporary archive is written, permissioned and validated first, the message to report is
 * built while the old bytes are still there, and only then is the file replaced.
 */
final class PharUpdater
{
    /**
     * The asset downloads are public files served from GitHub's CDN, not API calls: no
     * `Accept: application/vnd.github+json`, and deliberately no `Authorization` either, so a token
     * meant for api.github.com is not handed to whatever host the download redirects to.
     */
    public const DOWNLOAD_HEADERS = ['User-Agent: lockrot'];

    /**
     * How old a leftover `*.tmp.phar` beside the PHAR has to be before an install sweeps it.
     *
     * A run killed between the write and the `rename()` leaves its temporary archive behind for
     * good, and nothing else ever removes it. An hour keeps the sweep away from a second
     * self-update that is downloading right now: its file is minutes old at most, and deleting it
     * mid-flight would turn someone else's working update into a failure.
     */
    public const STALE_TEMPORARY_SECONDS = 3600;

    private HttpClientInterface $http;
    private PharValidatorInterface $validator;
    private SignatureVerifierInterface $signatures;
    private string $runningPhar;
    private string $currentVersion;
    private Clock $clock;

    /** @param Clock|null $clock the instant the stale-temporary sweep measures age from; now by default */
    public function __construct(
        HttpClientInterface $http,
        PharValidatorInterface $validator,
        SignatureVerifierInterface $signatures,
        string $runningPhar,
        string $currentVersion = Version::STRING,
        ?Clock $clock = null
    ) {
        $this->http = $http;
        $this->validator = $validator;
        $this->signatures = $signatures;
        $this->runningPhar = $runningPhar;
        $this->currentVersion = $currentVersion;
        $this->clock = $clock ?? new Clock();
    }

    /**
     * Whether $release is strictly newer than the running build.
     *
     * `Composer\Semver\Comparator::greaterThan()` rather than a string compare, so 0.10.0 is
     * correctly newer than 0.9.0.
     */
    public function isUpdateAvailable(Release $release): bool
    {
        return Comparator::greaterThan($release->version(), $this->currentVersion);
    }

    /**
     * Installs $release over the running PHAR.
     *
     * @param bool $force replace even when $release is not newer, e.g. to repair a damaged install
     *
     * @return string|null the message to report, or null when the running build is already current
     *                     and nothing was downloaded, written or replaced
     *
     * @throws ConfigException on every failure, with the running PHAR untouched
     */
    public function update(Release $release, bool $force): ?string
    {
        if (!$force && !$this->isUpdateAvailable($release)) {
            return null;
        }
        $directory = \dirname($this->runningPhar);
        // Checked before the download rather than after it: replacing the PHAR needs write access
        // to its directory (both rename() and the temporary file live there), so without it the
        // megabyte that would follow is wasted either way.
        if (!is_writable($directory)) {
            throw new ConfigException(
                $directory.' is not writable; download the new lockrot.phar from '.$release->pharUrl().' by hand instead'
            );
        }

        $responses = $this->http->fetchAll(
            [$release->checksumUrl(), $release->signatureUrl(), $release->pharUrl()],
            self::DOWNLOAD_HEADERS
        );
        $expected = self::expectedHash(self::body($responses[$release->checksumUrl()]), $release->checksumUrl());
        $signature = self::body($responses[$release->signatureUrl()]);
        $phar = self::body($responses[$release->pharUrl()]);
        $actual = hash('sha256', $phar);
        if (!hash_equals($expected, $actual)) {
            throw new ConfigException(\sprintf(
                'checksum mismatch for %s: %s expects sha256 %s, the download is %s; nothing was written',
                $release->pharUrl(),
                $release->checksumUrl(),
                $expected,
                $actual
            ));
        }
        // Checksum first, signature second: a download that arrived damaged is reported as that,
        // not as a forgery. Both before a byte is written next to the running archive.
        $this->signatures->verify($phar, $signature, $release->signatureUrl());

        [$temporary, $unappliedMode] = $this->stage($phar);
        try {
            // Built here rather than after the replace: composing it needs Comparator, and with
            // --force nothing has loaded that class yet.
            $message = $this->outcome($release, $unappliedMode);
            $this->replace($temporary);
        } catch (\Throwable $e) {
            @unlink($temporary);

            throw $e;
        }

        return $message;
    }

    /**
     * What to report once the archive is in place. `--force` can install a release that is not
     * newer — the same version, to repair an install, or an older one from a dev build ahead of the
     * latest release — and neither of those is an update, so neither is called one.
     *
     * @param ?int $unappliedMode the file mode that could not be carried over, null when it was
     */
    private function outcome(Release $release, ?int $unappliedMode): string
    {
        if (Comparator::greaterThan($release->version(), $this->currentVersion)) {
            $message = \sprintf('lockrot updated from %s to %s', $this->currentVersion, $release->version());
        } elseif ($release->version() === $this->currentVersion) {
            $message = 'lockrot reinstalled '.$release->version();
        } else {
            $message = \sprintf('lockrot replaced %s with %s', $this->currentVersion, $release->version());
        }
        if ($unappliedMode !== null) {
            $message .= \sprintf(' (the previous file mode %04o could not be applied to the new file)', $unappliedMode);
        }

        return $message;
    }

    /**
     * Writes $phar beside the running archive, carries the target's permissions onto it and checks
     * that the runtime can open it — everything an install does short of the swap itself. The
     * temporary file is removed on every path out of here that throws; a caller that gets a path
     * back owns it, and either replaces with it or removes it.
     *
     * @return array{0: string, 1: ?int} the staged path, and the mode `chmod()` refused to apply
     *                                   (null when the permissions carried over, or when the running
     *                                   archive had none to read). An executable `./lockrot.phar`
     *                                   that silently came back non-executable would be worse than a
     *                                   noisy one, so this is reported — but it never fails an update
     *                                   that has otherwise succeeded.
     */
    private function stage(string $phar): array
    {
        $this->sweepStaleTemporaries();
        $temporary = $this->temporaryPath();
        if (file_put_contents($temporary, $phar) !== \strlen($phar)) {
            @unlink($temporary);

            throw new ConfigException('could not write '.$temporary);
        }
        try {
            $unapplied = null;
            $permissions = @fileperms($this->runningPhar);
            if ($permissions !== false && !@chmod($temporary, $permissions & 0777)) {
                $unapplied = $permissions & 0777;
            }
            $error = $this->validator->validate($temporary);
            if ($error !== null) {
                throw new ConfigException('the downloaded lockrot.phar is not a readable archive ('.$error.'); nothing was replaced');
            }

            return [$temporary, $unapplied];
        } catch (\Throwable $e) {
            @unlink($temporary);

            throw $e;
        }
    }

    /**
     * Removes temporary archives an earlier run left behind. Only files matching this class's own
     * naming, only in the PHAR's own directory, and only ones older than
     * {@see STALE_TEMPORARY_SECONDS}; a file that disappears between the listing and the unlink —
     * another process sweeping the same directory — is not an error.
     */
    private function sweepStaleTemporaries(): void
    {
        $pattern = \dirname($this->runningPhar).'/'.basename($this->runningPhar).'.*.tmp.phar';
        $cutoff = $this->clock->now()->getTimestamp() - self::STALE_TEMPORARY_SECONDS;
        foreach (glob($pattern) ?: [] as $leftover) {
            $modified = @filemtime($leftover);
            if ($modified !== false && $modified < $cutoff) {
                @unlink($leftover);
            }
        }
    }

    private function replace(string $temporary): void
    {
        if (Platform::isWindows()) {
            // copy() applies the destination's own permissions; rename() would carry the temporary
            // file's across instead — Composer's own reason for doing it this way on Windows.
            if (!@copy($temporary, $this->runningPhar)) {
                throw new ConfigException('could not copy '.$temporary.' onto '.$this->runningPhar);
            }
            @unlink($temporary);

            return;
        }
        if (!@rename($temporary, $this->runningPhar)) {
            throw new ConfigException('could not move '.$temporary.' onto '.$this->runningPhar);
        }
    }

    /**
     * A unique name in the PHAR's own directory, ending in `.phar` because that is the only way the
     * runtime will open it: `new \Phar()` rejects a path whose extension it does not recognise, so a
     * plain `.tmp` suffix would fail validation before the archive was ever inspected.
     */
    private function temporaryPath(): string
    {
        return \dirname($this->runningPhar).'/'.basename($this->runningPhar).'.'.getmypid().'-'.uniqid().'.tmp.phar';
    }

    private static function body(HttpResult $result): string
    {
        $body = $result->body();
        if (!$result->isOk() || $body === null) {
            $error = $result->error();

            throw new ConfigException(
                'could not download '.$result->url().': '.($error !== null && $error !== '' ? $error : 'HTTP '.$result->status())
            );
        }

        return $body;
    }

    /**
     * The sha256 the release publishes, read as the first 64-hex token of the `.sha256` file.
     *
     * The file is `sha256sum` output — `<hex>  <name>` — and the name half is not stable across
     * releases, so only the hash is relied on. The archive being verified is the one just
     * downloaded from the URL the same release listed, not a file picked by name.
     */
    private static function expectedHash(string $checksumFile, string $url): string
    {
        if (preg_match('/\b[0-9a-f]{64}\b/i', $checksumFile, $matches) !== 1) {
            throw new ConfigException($url.' holds no sha256 hash');
        }

        return strtolower($matches[0]);
    }
}
