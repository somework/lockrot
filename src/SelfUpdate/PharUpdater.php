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
 * Downloads a {@see Release}, verifies its sha256 and signature, and replaces the running PHAR.
 *
 * The order of the steps is a contract. A running PHAR reads more classes from its file on demand,
 * against the manifest that it read at startup. After a swap, a class that the process has not
 * loaded is gone. The swap is the last step: stage and validate the temporary archive, build the
 * message, then replace. Writes and rollback: docs/phar.md#keeping-it-updated
 *
 * @internal
 */
final class PharUpdater
{
    /**
     * No `Authorization`: a token for api.github.com must not reach the host that a download
     * redirects to.
     */
    public const DOWNLOAD_HEADERS = ['User-Agent: lockrot'];

    /**
     * A leftover `*.tmp.phar` must be older than this before an install deletes it: a younger file
     * can belong to a second self-update that is still downloading.
     */
    public const STALE_TEMPORARY_SECONDS = 3600;

    private HttpClientInterface $http;
    private PharValidatorInterface $validator;
    private SignatureVerifierInterface $signatures;
    private string $runningPhar;
    private string $currentVersion;
    private Clock $clock;

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

    public function isUpdateAvailable(Release $release): bool
    {
        return Comparator::greaterThan($release->version(), $this->currentVersion);
    }

    /**
     * @return string|null the message to report, or null when the running build is current and
     *                     nothing was downloaded, written or replaced
     *
     * @throws ConfigException on every failure, with the running PHAR untouched
     */
    public function update(Release $release, bool $force): ?string
    {
        if (!$force && !$this->isUpdateAvailable($release)) {
            return null;
        }
        $directory = \dirname($this->runningPhar);
        // Checked before the download: the temporary file and rename() need write access to this
        // directory, so without it the download is wasted.
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
        // Checksum first, signature second: a damaged download is reported as damaged, not as a
        // forgery. Both come before a byte is written next to the running archive.
        $this->signatures->verify($phar, $signature, $release->signatureUrl());

        [$temporary, $unappliedMode] = $this->stage($phar);
        try {
            // Built before the replace: it needs Comparator, which a `--force` run has not loaded.
            $message = $this->outcome($release, $unappliedMode);
            $this->replace($temporary);
        } catch (\Throwable $e) {
            @unlink($temporary);

            throw $e;
        }

        return $message;
    }

    /** @param ?int $unappliedMode the file mode that could not be applied, null when none */
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
     * Every path that throws removes the temporary file first. A caller that gets a path back owns
     * it and either replaces with it or removes it.
     *
     * @return array{0: string, 1: ?int} the staged path, and the mode that `chmod()` refused to
     *                                   apply, null when none: a refused mode is reported and never
     *                                   fails an update that otherwise succeeded
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
     * Deletes only this class's own `*.tmp.phar` files in the PHAR's directory that are older than
     * {@see STALE_TEMPORARY_SECONDS}. A file that another sweep deletes first is no error.
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
            // copy() applies the destination's own permissions. rename() carries the temporary
            // file's across and can lock other users out. Composer does the same.
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
     * The name ends in `.phar` because `new \Phar()` rejects a path with an unrecognised extension,
     * so a `.tmp` suffix fails validation.
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
     * Reads the first 64-hex token of the `.sha256` file: the name after the hash in its
     * `sha256sum` output is not stable across releases.
     */
    private static function expectedHash(string $checksumFile, string $url): string
    {
        if (preg_match('/\b[0-9a-f]{64}\b/i', $checksumFile, $matches) !== 1) {
            throw new ConfigException($url.' holds no sha256 hash');
        }

        return strtolower($matches[0]);
    }
}
