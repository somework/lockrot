<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

use Composer\Semver\VersionParser;
use Lockrot\Exception\ConfigException;

/**
 * What a release's `lockrot.phar.meta.json` says about it — the lowest PHP its archive runs on,
 * and the fingerprint of the self-update key that signed its `lockrot.phar.sig.json` — so
 * {@see ReleaseLocator} can pass over a release this machine cannot run, or this archive cannot
 * verify, without downloading it.
 *
 * The file is written by the release workflow (`build/selfupdate-signature.php describe`) from
 * build/phar/composer.json and the key the release was actually signed with, and is published from
 * 0.13.0 on. It is not signed: it decides only which release is tried. The checksum and the
 * signature still decide whether one is installed, so the worst a doctored description can do is
 * hold an update back.
 *
 * @internal
 */
final class ReleaseDescription
{
    /**
     * The floor of every release before 0.13.0: build/phar/composer.json has pinned the platform to
     * PHP 7.4.0 since the first commit, and CI runs each archive once on PHP 7.4.
     */
    public const UNDESCRIBED_PHP_FLOOR = '7.4.0';

    private string $phpFloor;
    private ?string $signingKey;

    private function __construct(string $phpFloor, ?string $signingKey)
    {
        $this->phpFloor = $phpFloor;
        $this->signingKey = $signingKey;
    }

    /**
     * A release from before descriptions: the 7.4 floor it was built for, and no claim about its
     * key, so the signature check alone decides — as it did before descriptions existed.
     */
    public static function undescribed(): self
    {
        return new self(self::UNDESCRIBED_PHP_FLOOR, null);
    }

    /**
     * Only the one shape is read — `{"php": "<version>", "selfupdate-key": "sha256:<64 hex>"}`,
     * anything more ignored — and anything else is an error naming where it came from, never a
     * guess: a description that cannot be read cannot say the release is fit to try.
     *
     * @throws ConfigException
     */
    public static function fromJson(string $body, string $url): self
    {
        $decoded = json_decode($body, true);
        $php = \is_array($decoded) ? ($decoded['php'] ?? null) : null;
        $key = \is_array($decoded) ? ($decoded['selfupdate-key'] ?? null) : null;
        if (!\is_string($php) || !self::isVersion($php) || !\is_string($key) || preg_match('/^sha256:[0-9a-f]{64}$/D', $key) !== 1) {
            throw new ConfigException($url.' is not a lockrot release description ({"php": "<version>", "selfupdate-key": "sha256:<hex>"} expected)');
        }

        return new self($php, $key);
    }

    private static function isVersion(string $version): bool
    {
        try {
            (new VersionParser())->normalize($version);
        } catch (\UnexpectedValueException $e) {
            return false;
        }

        return true;
    }

    /** The lowest PHP version the release's archive runs on, e.g. `7.4.0`. */
    public function phpFloor(): string
    {
        return $this->phpFloor;
    }

    /** `sha256:<hex>` of the key that signed the release, or null when the release does not say. */
    public function signingKey(): ?string
    {
        return $this->signingKey;
    }
}
