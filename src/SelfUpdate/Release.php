<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

/**
 * One published GitHub release of lockrot, reduced to what self-update needs: the version to
 * compare against {@see \Lockrot\Version::STRING}, the tag it came from (for messages), and the two
 * asset URLs to download.
 *
 * Built only by {@see ReleaseLocator}, which is where the validation lives; this is an immutable
 * carrier, not a parser.
 */
final class Release
{
    private string $version;
    private string $tag;
    private string $pharUrl;
    private string $checksumUrl;

    public function __construct(string $version, string $tag, string $pharUrl, string $checksumUrl)
    {
        $this->version = $version;
        $this->tag = $tag;
        $this->pharUrl = $pharUrl;
        $this->checksumUrl = $checksumUrl;
    }

    /** The tag without its leading `v`, normalised by Composer's own version parser. */
    public function version(): string
    {
        return $this->version;
    }

    /** The tag exactly as GitHub published it, e.g. `v0.2.0`. */
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
}
