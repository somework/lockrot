<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

use Lockrot\Exception\ConfigException;

/**
 * What a release's `lockrot.phar.meta.json` says, as `build/selfupdate-signature.php describe`
 * writes it: its lowest PHP and the fingerprint of the key that signed it. The file is unsigned
 * and decides only which release is tried: the checksum and the signature decide whether one is
 * installed. A floor that is not plain `major.minor.patch` is never compared ({@see phpFloor()}).
 * What a doctored file can do: SECURITY.md#how-self-update-trusts-a-release
 *
 * @internal
 */
final class ReleaseDescription
{
    /**
     * A release before {@see ReleaseLocator::FIRST_DESCRIBED_VERSION} has no description, and its
     * archive needs PHP 7.4.0. Do not change this value with the PHAR build.
     */
    public const UNDESCRIBED_PHP_FLOOR = '7.4.0';

    /** Only `major.minor.patch` in digits: the value is printed, so it must carry nothing that a terminal acts on. */
    public const PHP_FLOOR_PATTERN = '/^\d+\.\d+\.\d+$/D';

    private ?string $phpFloor;
    private ?string $signingKey;

    private function __construct(?string $phpFloor, ?string $signingKey)
    {
        $this->phpFloor = $phpFloor;
        $this->signingKey = $signingKey;
    }

    public static function undescribed(): self
    {
        return new self(self::UNDESCRIBED_PHP_FLOOR, null);
    }

    /**
     * Reads only `{"php": "<major.minor.patch>", "selfupdate-key": "sha256:<64 hex>"}` and ignores
     * other members. Any other document is an error that names $url: a description that cannot be
     * read cannot say the release is fit to try. A `php` string in another spelling is no floor.
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
     * `major.minor.patch`, or null when the description spells the floor any other way, because the
     * real floor is unknown then.
     */
    public function phpFloor(): ?string
    {
        return $this->phpFloor;
    }

    /** `sha256:<hex>`, or null when the release names no key. */
    public function signingKey(): ?string
    {
        return $this->signingKey;
    }
}
