<?php

declare(strict_types=1);

namespace Lockrot\Security;

use Composer\Semver\VersionParser;

/**
 * What `composer update <package>` installs on the installed branch, and which counted advisories
 * it clears (`security.gets`). Composer resolves against the target, never the
 * project's `require.php`, so the floor does not filter it: {@see phpCheck()} says what it needs.
 *
 * @internal
 */
final class Gets
{
    private ?string $version;
    private PhpCheck $phpCheck;
    /** @var list<string> */
    private array $clears;
    private bool $clearsAll;
    private bool $capped = false;
    /** @var array<string, self> by normalized version */
    private array $releases = [];

    /**
     * @param ?string      $version null when the target cannot run the release
     * @param list<string> $clears  advisory ids
     */
    public function __construct(?string $version, PhpCheck $phpCheck, array $clears, bool $clearsAll)
    {
        $this->version = $version;
        $this->phpCheck = $phpCheck;
        $this->clears = $clears;
        $this->clearsAll = $clearsAll;
    }

    /**
     * Refusal holds exclude every release that the links allow: no version, and the php check of
     * the highest such release. Only a solve names a release then ({@see at()}).
     */
    public static function capped(PhpCheck $phpCheck): self
    {
        $gets = new self(null, $phpCheck, [], false);
        $gets->capped = true;

        return $gets;
    }

    public function isCapped(): bool
    {
        return $this->capped;
    }

    /** @param array<string, self> $releases the facts of each release above the installed one, by normalized version */
    public function withReleases(array $releases): self
    {
        $clone = clone $this;
        $clone->releases = $releases;

        return $clone;
    }

    /**
     * The Gets of $version, a release that a solve installs: that release's own php check and clears.
     * A release that the scan does not know keeps the facts of this Gets.
     */
    public function at(string $version): self
    {
        try {
            $known = $this->releases[(new VersionParser())->normalize($version)] ?? null;
        } catch (\UnexpectedValueException $e) {
            $known = null;
        }
        $gets = clone ($known ?? $this);
        $gets->version = $version;
        $gets->capped = false;

        return $gets;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    public function phpCheck(): PhpCheck
    {
        return $this->phpCheck;
    }

    /** @return list<string> */
    public function clears(): array
    {
        return $this->clears;
    }

    public function clearsAll(): bool
    {
        return $this->clearsAll;
    }
}
