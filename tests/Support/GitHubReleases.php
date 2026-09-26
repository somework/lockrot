<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\SelfUpdate\ReleaseLocator;
use Lockrot\SelfUpdate\ReleaseSignatureVerifier;

/**
 * Builds GitHub release-list bodies (`GET /repos/{owner}/{repo}/releases`) and the
 * `lockrot.phar.meta.json` descriptions next to them, for the self-update tests.
 *
 * Generated rather than recorded: a paging test needs a page of exactly 100 entries, and a
 * selection test needs versions around whatever {@see \Lockrot\Version::STRING} is today.
 * `tests/fixtures/http/github-releases/releases.json` is the one recorded-shape list, kept so
 * the reader is also checked against the fields GitHub actually sends.
 */
final class GitHubReleases
{
    public const BASE = 'https://github.com/somework/lockrot/releases/download/';

    /** Every asset a release from 0.13.0 on publishes, in the names self-update looks for. */
    public const ASSETS = [
        ReleaseLocator::PHAR_ASSET,
        ReleaseLocator::CHECKSUM_ASSET,
        ReleaseLocator::SIGNATURE_ASSET,
        ReleaseLocator::METADATA_ASSET,
    ];

    public static function assetUrl(string $tag, string $asset, string $base = self::BASE): string
    {
        return $base.$tag.'/'.$asset;
    }

    /**
     * One entry of the list, shaped the way GitHub sends it (the fields self-update reads, plus a
     * few it ignores).
     *
     * @param list<string> $assets asset names to publish
     *
     * @return array<string, mixed>
     */
    public static function entry(string $tag, array $assets = self::ASSETS, bool $draft = false, bool $prerelease = false, string $base = self::BASE): array
    {
        $published = [];
        foreach ($assets as $name) {
            $published[] = [
                'name' => $name,
                'state' => 'uploaded',
                'browser_download_url' => self::assetUrl($tag, $name, $base),
            ];
        }

        return [
            'tag_name' => $tag,
            'name' => ltrim($tag, 'v'),
            'draft' => $draft,
            'prerelease' => $prerelease,
            'assets' => $published,
        ];
    }

    /** @param list<mixed> $entries */
    public static function listJson(array $entries): string
    {
        return json_encode($entries, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /** The `lockrot.phar.meta.json` body the release workflow writes. */
    public static function meta(string $php, string $key): string
    {
        return json_encode(['php' => $php, 'selfupdate-key' => $key], \JSON_THROW_ON_ERROR)."\n";
    }

    /** `sha256:<hex>` of the DER public key in $pem — the value a description names. */
    public static function fingerprint(string $pem): string
    {
        return (new ReleaseSignatureVerifier($pem))->keyFingerprint();
    }

    /** The major version of $version followed by `.999.0`: newer than it, and in the same line. */
    public static function newerInLine(string $version): string
    {
        return ((int) $version).'.999.0';
    }

    /** The first release of the next major version after $version's. */
    public static function nextMajor(string $version): string
    {
        return (((int) $version) + 1).'.0.0';
    }
}
