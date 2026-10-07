<?php

declare(strict_types=1);

namespace Lockrot\Security;

/**
 * What `composer update <package>` installs on the installed branch, and which counted advisories
 * it clears (`security.gets` of SPEC-0.14 5.3). Composer resolves against the target, never the
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
