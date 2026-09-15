<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

use Composer\Semver\VersionParser;
use Lockrot\Data\GitHub\GitHubClient;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Exception\ConfigException;

/**
 * Reads `GET /repos/somework/lockrot/releases/latest` and turns it into a {@see Release}.
 *
 * Pre-releases are out of scope by construction: GitHub's `releases/latest` endpoint already
 * excludes drafts and pre-releases, so there is no channel to pick (ruling,
 * .superpowers/sdd/2026-09-15-lockrot-self-update/progress.md).
 *
 * The request carries the same headers the analyzer's GitHub calls do
 * ({@see GitHubClient::headersFor()}), so `GITHUB_TOKEN`, `LOCKROT_GITHUB_TOKEN` and Composer's
 * `github-oauth` all lift the 60-requests-per-hour anonymous limit here too.
 *
 * Every failure — no release, an unreachable API, a body that is not JSON, a tag that is not a
 * version, a release missing either asset — is a {@see ConfigException}, which the command reports
 * on stderr and turns into exit 2.
 */
final class ReleaseLocator
{
    public const DEFAULT_URL = 'https://api.github.com/repos/somework/lockrot/releases/latest';
    public const PHAR_ASSET = 'lockrot.phar';
    public const CHECKSUM_ASSET = 'lockrot.phar.sha256';

    private HttpClientInterface $http;
    private ?string $token;
    private string $url;

    public function __construct(HttpClientInterface $http, ?string $token = null, string $url = self::DEFAULT_URL)
    {
        $this->http = $http;
        $this->token = $token;
        $this->url = $url;
    }

    public function locate(): Release
    {
        $json = $this->fetch();
        $tag = $json['tag_name'] ?? null;
        if (!\is_string($tag) || $tag === '') {
            throw new ConfigException($this->url.' has no tag_name');
        }
        // One leading `v`, and only in front of a digit: `v0.2.0` is 0.2.0, while a tag that merely
        // starts with the letter (`vendor-freeze`) is left alone and fails the check below as it
        // should, rather than being silently rewritten into something the parser accepts.
        $version = preg_match('/^v(\d.*)$/', $tag, $matches) === 1 ? $matches[1] : $tag;
        try {
            (new VersionParser())->normalize($version);
        } catch (\UnexpectedValueException $e) {
            throw new ConfigException('release tag "'.$tag.'" is not a version lockrot can compare');
        }

        return new Release(
            $version,
            $tag,
            $this->assetUrl($json, $tag, self::PHAR_ASSET),
            $this->assetUrl($json, $tag, self::CHECKSUM_ASSET)
        );
    }

    /** @return array<string, mixed> the decoded `releases/latest` body */
    private function fetch(): array
    {
        $result = $this->http->fetchAll([$this->url], GitHubClient::headersFor($this->token))[$this->url];
        if ($result->isNotFound()) {
            throw new ConfigException('no published release found');
        }
        if (!$result->isOk()) {
            throw new ConfigException('could not read '.$this->url.': '.self::reason($result));
        }
        $json = $result->json();
        if ($json === null) {
            throw new ConfigException('the response from '.$this->url.' is not JSON');
        }

        return $json;
    }

    private static function reason(HttpResult $result): string
    {
        $error = $result->error();

        return $error !== null && $error !== '' ? $error : 'HTTP '.$result->status();
    }

    /**
     * The `browser_download_url` of the asset published under exactly $name. GitHub keeps assets in
     * an unordered list, so the match is by name rather than by position, and a partial match is not
     * accepted: `lockrot.phar` and `lockrot.phar.sha256` are distinguished only by their full names.
     *
     * @param array<string, mixed> $json
     */
    private function assetUrl(array $json, string $tag, string $name): string
    {
        $assets = $json['assets'] ?? null;
        foreach (\is_array($assets) ? $assets : [] as $asset) {
            if (!\is_array($asset) || ($asset['name'] ?? null) !== $name) {
                continue;
            }
            $url = $asset['browser_download_url'] ?? null;
            if (\is_string($url) && $url !== '') {
                return $url;
            }
        }

        throw new ConfigException('release '.$tag.' has no '.$name.' asset');
    }
}
