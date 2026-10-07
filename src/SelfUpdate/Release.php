<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

/** @internal */
final class Release
{
    private string $version;
    private string $tag;
    private string $pharUrl;
    private string $checksumUrl;
    private string $signatureUrl;

    public function __construct(string $version, string $tag, string $pharUrl, string $checksumUrl, string $signatureUrl)
    {
        $this->version = $version;
        $this->tag = $tag;
        $this->pharUrl = $pharUrl;
        $this->checksumUrl = $checksumUrl;
        $this->signatureUrl = $signatureUrl;
    }

    /** The version as `major.minor.patch`, plus a fourth number that is not 0, whatever spelling the tag used (`v0.13` is 0.13.0). */
    public function version(): string
    {
        return $this->version;
    }

    public function tag(): string
    {
        return $this->tag;
    }

    public function pharUrl(): string
    {
        return $this->pharUrl;
    }

    public function checksumUrl(): string
    {
        return $this->checksumUrl;
    }

    public function signatureUrl(): string
    {
        return $this->signatureUrl;
    }
}
