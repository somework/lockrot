<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

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
 * signature still decide whether one is installed, so a doctored description cannot get an archive
 * installed that the release key did not sign. It can hold an update back, and it can understate the
 * floor: a signed release this PHP cannot run is then installed and refuses to start, and has to be
 * replaced by hand. A floor that is not plain `major.minor.patch` — what the workflow always writes —
 * is never compared at all ({@see phpFloor()}), so a doctored spelling cannot slip past the check.
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

    /**
     * The one spelling of a floor that is read: `major.minor.patch`, digits only. No `v`, no fourth
     * number, no build metadata, nothing around it — the workflow writes nothing else, and the value
     * is also printed, so it must not carry anything a terminal would act on.
     */
    public const PHP_FLOOR_PATTERN = '/^\d+\.\d+\.\d+$/D';

    private ?string $phpFloor;
    private ?string $signingKey;

    private function __construct(?string $phpFloor, ?string $signingKey)
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
     * Only the one shape is read — `{"php": "<major.minor.patch>", "selfupdate-key": "sha256:<64 hex>"}`,
     * anything more ignored. A document without that shape is an error naming where it came from,
     * never a guess: a description that cannot be read cannot say the release is fit to try. A floor
     * that is a string in another spelling is read as no floor ({@see phpFloor()}).
     *
     * @throws ConfigException
     */
    public static function fromJson(string $body, string $url): self
    {
        $decoded = json_decode($body, true);
        $php = \is_array($decoded) ? ($decoded['php'] ?? null) : null;
        $key = \is_array($decoded) ? ($decoded['selfupdate-key'] ?? null) : null;
        if (!\is_string($php) || !\is_string($key) || preg_match('/^sha256:[0-9a-f]{64}$/D', $key) !== 1) {
            throw new ConfigException($url.' is not a lockrot release description ({"php": "<version>", "selfupdate-key": "sha256:<hex>"} expected)');
        }

        return new self(preg_match(self::PHP_FLOOR_PATTERN, $php) === 1 ? $php : null, $key);
    }

    /**
     * The lowest PHP version the release's archive runs on, `major.minor.patch` (e.g. `7.4.0`), or
     * null when the description spells it any other way: such a release is not installed, since its
     * real floor is unknown.
     */
    public function phpFloor(): ?string
    {
        return $this->phpFloor;
    }

    /** `sha256:<hex>` of the key that signed the release, or null when the release does not say. */
    public function signingKey(): ?string
    {
        return $this->signingKey;
    }
}
