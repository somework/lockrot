<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

use Composer\Semver\Comparator;
use Composer\Util\Platform;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Exception\ConfigException;
use Lockrot\Version;

/**
 * Downloads a {@see Release}, verifies it against its published sha256, and replaces the running
 * PHAR with it.
 *
 * The shape of the replace follows Composer's own self-update (2.10.3
 * src/Composer/Command/SelfUpdateCommand.php:458-505): write next to the target, carry the target's
 * permissions onto the new file, check that the runtime can open it, then `rename()` — except on
 * Windows, where `copy()` + `unlink()` is used because `rename()` keeps the source file's
 * permissions and can lock other users out (Composer's own reason, same lines; 2.2.25 :436,:464).
 *
 * Two things this deliberately does not do:
 *
 * - No cache-directory fallback for the temporary file. It is written beside the PHAR or not at
 *   all, so a successful download is always one `rename()` away from being installed and an
 *   unwritable directory is reported before anything is fetched (ruling,
 *   .superpowers/sdd/2026-09-15-lockrot-self-update/progress.md).
 * - No backup of the replaced PHAR and so no rollback in 0.1. Every previous release stays
 *   downloadable from GitHub, which is the documented way back (same ruling).
 *
 * On any failure the running PHAR is left exactly as it was and the temporary file is removed.
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
    private string $runningPhar;
    private string $currentVersion;

    public function __construct(
        HttpClientInterface $http,
        PharValidatorInterface $validator,
        string $runningPhar,
        string $currentVersion = Version::STRING
    ) {
        $this->http = $http;
        $this->validator = $validator;
        $this->runningPhar = $runningPhar;
        $this->currentVersion = $currentVersion;
    }

    /**
     * Whether $release is strictly newer than the running build.
     *
     * `Composer\Semver\Comparator::greaterThan()` (vendor/composer/semver/src/Comparator.php:26,
     * semver 3.x, bundled by Composer 2.2.25 and 2.10.3 alike) rather than a string compare, so
     * 0.10.0 is correctly newer than 0.9.0.
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

        $responses = $this->http->fetchAll([$release->checksumUrl(), $release->pharUrl()], self::DOWNLOAD_HEADERS);
        $expected = self::expectedHash(self::body($responses[$release->checksumUrl()]), $release->checksumUrl());
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

        return $this->outcome($release, $this->install($phar));
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
     * Writes $phar beside the running archive, validates it, and swaps it in. The temporary file is
     * removed on every path out of here except the successful `rename()`, which consumes it.
     *
     * @return int|null the mode `chmod()` refused to apply, null when the permissions carried over
     *                  (or when the running archive had none to read). An executable `./lockrot.phar`
     *                  that silently came back non-executable would be worse than a noisy one, so
     *                  this is reported — but it never fails an update that has otherwise succeeded.
     */
    private function install(string $phar): ?int
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
            $this->replace($temporary);

            return $unapplied;
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
        $cutoff = time() - self::STALE_TEMPORARY_SECONDS;
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
            // file's across instead — Composer's reason, SelfUpdateCommand.php:485-487.
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
     * The file is `sha256sum` output — `<hex>  <name>` — and the name half has changed across
     * releases (it used to read `build/lockrot.phar`), so only the hash is relied on. The archive
     * being verified is the one just downloaded from the URL the same release listed, not a file
     * picked by name.
     */
    private static function expectedHash(string $checksumFile, string $url): string
    {
        if (preg_match('/\b[0-9a-f]{64}\b/i', $checksumFile, $matches) !== 1) {
            throw new ConfigException($url.' holds no sha256 hash');
        }

        return strtolower($matches[0]);
    }
}
