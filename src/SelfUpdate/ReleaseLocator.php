<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Lockrot\Data\Forge\GitHubApi;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;
use Lockrot\Version;

/**
 * Reads lockrot's release list (`GET /repos/somework/lockrot/releases`) and chooses the release
 * self-update installs: the newest one in the running major version that this PHP can run and this
 * archive can verify.
 *
 * Not `releases/latest`, which names one release and says nothing about whether it may be taken:
 * on the day 2.0 is published every 1.x archive would install it, a release needing a newer PHP
 * would install and then refuse to start, and a release signed after a rotation of the self-update
 * key would be refused with a signature error and no way forward. So:
 *
 * - The major version is the first number of the version, taken literally: all of 0.x is one line,
 *   and 0.x to 1.0 is a new major like any other. A newer major is passed over with a note, and
 *   `--allow-major` moves to the next one — one step, never several at once, so a release that
 *   exists only to warn about the step after it is not skipped on the way.
 * - A release's lowest PHP and the key that signed it come from its `lockrot.phar.meta.json`
 *   ({@see ReleaseDescription}), fetched only for a release that would otherwise be installed.
 *   Releases before 0.13.0 have none and are read as what they are: built for PHP 7.4.0, with no
 *   claim about their key.
 * - Drafts (listed for a token with push access), pre-releases, and tags that are not a stable
 *   version are not candidates. One odd entry does not stop an update; a chosen release that lacks
 *   an asset, or whose description cannot be read, still fails loudly rather than falling back.
 *
 * The request carries the same headers the analyzer's GitHub calls do
 * ({@see GitHubApi::headersFor()}), so `GITHUB_TOKEN`, `LOCKROT_GITHUB_TOKEN` and Composer's
 * `github-oauth` all lift the 60-requests-per-hour anonymous limit here too. The list is paged by
 * count (a page shorter than {@see PER_PAGE} is the last), and stops early at the page that reaches
 * the running version: GitHub lists newest first, and SECURITY.md rules out backports.
 *
 * Every failure is a {@see ConfigException}, which the command reports on stderr and turns into
 * exit 2. Releases before 0.6.0 carry no signature, so `--force` cannot install one from a build
 * that checks signatures; that is the one direction the check closes on purpose.
 *
 * @internal
 */
final class ReleaseLocator
{
    public const DEFAULT_URL = 'https://api.github.com/repos/somework/lockrot/releases?per_page=100';
    /** The page size DEFAULT_URL asks for; a page with fewer entries is the last one. */
    public const PER_PAGE = 100;
    /** A bound on the list, whatever it answers: a thousand releases back is far past any update. */
    public const MAX_PAGES = 10;
    public const PHAR_ASSET = 'lockrot.phar';
    public const CHECKSUM_ASSET = 'lockrot.phar.sha256';
    /**
     * Not `lockrot.phar.sig`: PHIVE takes any release asset ending in `.asc` or `.sig` for the GPG
     * signature (phar-io/phive, GithubRepository::getReleasesByRequestedPhar(), last match wins),
     * and `phive install somework/lockrot` failed on 0.6.0 until its asset was renamed. The
     * suffix says what the file is — Composer's `{"sha384": …}` document — and PHIVE ignores it.
     */
    public const SIGNATURE_ASSET = 'lockrot.phar.sig.json';
    /** {@see ReleaseDescription}; `.json` for the same reason as the signature. */
    public const METADATA_ASSET = 'lockrot.phar.meta.json';
    /** The first release that publishes {@see METADATA_ASSET}; a release from here on without it is an error. */
    public const FIRST_DESCRIBED_VERSION = '0.13.0';

    private HttpClientInterface $http;
    private string $trustedKey;
    private ?string $token;
    private string $url;
    private string $currentVersion;
    private string $phpVersion;

    /**
     * @param string  $trustedKey the fingerprint of the key this archive verifies with
     *                            ({@see SignatureVerifierInterface::keyFingerprint()})
     * @param string  $url        the release list; a page after the first adds `page=N` to it
     * @param ?string $phpVersion the PHP to choose for, `major.minor.patch`; null is the running one
     */
    public function __construct(
        HttpClientInterface $http,
        string $trustedKey,
        ?string $token = null,
        string $url = self::DEFAULT_URL,
        string $currentVersion = Version::STRING,
        ?string $phpVersion = null
    ) {
        $this->http = $http;
        $this->trustedKey = $trustedKey;
        $this->token = $token;
        $this->url = $url;
        $this->currentVersion = $currentVersion;
        $this->phpVersion = $phpVersion ?? \sprintf('%d.%d.%d', \PHP_MAJOR_VERSION, \PHP_MINOR_VERSION, \PHP_RELEASE_VERSION);
    }

    /** The first number of $version: its major version line, with all of 0.x as line 0. */
    public static function majorOf(string $version): int
    {
        return (int) $version;
    }

    /**
     * Walks the releases newest first and returns the first one that may be installed.
     *
     * Without $force the walk ends at the running version: nothing at or below it is an update, so
     * an up-to-date check reads the list and nothing else. With $force the newest release at or
     * below the running version is also a candidate — the one it reinstalls, or the one a dev build
     * ahead of every release goes back to — and nothing older: the description is unsigned, and a
     * lying one must not walk a reinstall down to an older release.
     *
     * @param bool $allowMajor also take a release of the next major version
     * @param bool $force      also take the newest release at or below the running version
     *
     * @throws ConfigException
     */
    public function locate(bool $allowMajor = false, bool $force = false): ReleaseChoice
    {
        $notes = [];
        foreach ($this->candidates() as $candidate) {
            $newer = Comparator::greaterThan($candidate['version'], $this->currentVersion);
            if (!$newer && !$force) {
                return new ReleaseChoice(null, array_values($notes));
            }
            $heldBack = $this->heldBack($candidate, $allowMajor);
            if ($heldBack === null) {
                return new ReleaseChoice($this->release($candidate), array_values($notes));
            }
            // One line per reason, naming the newest release held back for it.
            $notes[$heldBack[0]] ??= $heldBack[1];
            if (!$newer) {
                break;
            }
        }

        return new ReleaseChoice(null, array_values($notes));
    }

    /**
     * Why $candidate may not be installed, as a reason and the line that says so, or null when it
     * may. The major version is decided from the tag alone; only a release that passes it is
     * described, so a held-back major costs no request.
     *
     * @param array{version: string, normalized: string, tag: string, entry: array<mixed>} $candidate
     *
     * @return array{0: string, 1: string}|null
     */
    private function heldBack(array $candidate, bool $allowMajor): ?array
    {
        $version = $candidate['version'];
        $ahead = self::majorOf($version) - self::majorOf($this->currentVersion);
        if ($ahead > 1) {
            return ['beyond', \sprintf('lockrot %s is more than one major version ahead; --allow-major moves one major version at a time', $version)];
        }
        if ($ahead === 1 && !$allowMajor) {
            return ['major', \sprintf('lockrot %s is in the next major version; run lockrot.phar self-update --allow-major to move to it', $version)];
        }
        $description = $this->describe($candidate);
        if (Comparator::lessThan($this->phpVersion, $description->phpFloor())) {
            return ['php', \sprintf('lockrot %s needs PHP %s or newer, and this is PHP %s', $version, $description->phpFloor(), $this->phpVersion)];
        }
        $key = $description->signingKey();
        if ($key !== null && $key !== $this->trustedKey) {
            return ['key', \sprintf(
                'lockrot %s is signed with a self-update key this lockrot.phar does not carry (%s); only a release that carries that key can update to it',
                $version,
                $key
            )];
        }

        return null;
    }

    /**
     * The release's description, fetched from its download URL the way the archive is — without
     * the API token, which is not for the CDN a download redirects to.
     *
     * @param array{version: string, normalized: string, tag: string, entry: array<mixed>} $candidate
     */
    private function describe(array $candidate): ReleaseDescription
    {
        if (Comparator::lessThan($candidate['version'], self::FIRST_DESCRIBED_VERSION)) {
            return ReleaseDescription::undescribed();
        }
        $url = self::assetUrl($candidate['entry'], $candidate['tag'], self::METADATA_ASSET);
        $result = $this->http->fetchAll([$url], PharUpdater::DOWNLOAD_HEADERS)[$url];
        if (!$result->isOk()) {
            throw new ConfigException('could not download '.$url.': '.self::reason($result));
        }

        return ReleaseDescription::fromJson($result->body() ?? '', $url);
    }

    /**
     * Every usable release in the list, newest first by version — not by list order, which is by
     * date and would put a patch of an older line above a newer minor.
     *
     * @return list<array{version: string, normalized: string, tag: string, entry: array<mixed>}>
     */
    private function candidates(): array
    {
        $candidates = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $entries = $this->page($page);
            $reached = false;
            foreach ($entries as $entry) {
                $candidate = self::candidate($entry);
                if ($candidate === null) {
                    continue;
                }
                $candidates[] = $candidate;
                if (!Comparator::greaterThan($candidate['version'], $this->currentVersion)) {
                    $reached = true;
                }
            }
            if ($reached || \count($entries) < self::PER_PAGE) {
                break;
            }
        }
        if ($candidates === []) {
            throw new ConfigException('no published release found');
        }
        usort($candidates, static fn (array $a, array $b): int => version_compare($b['normalized'], $a['normalized']));

        return $candidates;
    }

    /**
     * One page of the list. `[]` — and `{}`, which json_decode() cannot tell from it — is an empty
     * page; any other JSON that is not a list, such as the single release `releases/latest`
     * answers, is not a release list at all.
     *
     * @return array<mixed>
     */
    private function page(int $page): array
    {
        $url = $page === 1 ? $this->url : $this->url.(strpos($this->url, '?') === false ? '?' : '&').'page='.$page;
        $result = $this->http->fetchAll([$url], GitHubApi::headersFor($this->token))[$url];
        if ($result->isNotFound()) {
            throw new ConfigException('no published release found');
        }
        if (!$result->isOk()) {
            throw new ConfigException('could not read '.$url.': '.self::reason($result));
        }
        $json = $result->json();
        if ($json === null) {
            throw new ConfigException('the response from '.$url.' is not JSON');
        }
        if ($json !== [] && !JsonReader::isList($json)) {
            throw new ConfigException($url.' is not a list of releases');
        }

        return $json;
    }

    /**
     * $entry as a candidate, or null when it is not a published stable release: a draft, a
     * pre-release, a tag that is not a string or not a version, or a version that is not stable
     * (`v1.0.0-RC1` published without the pre-release flag).
     *
     * @param mixed $entry
     *
     * @return array{version: string, normalized: string, tag: string, entry: array<mixed>}|null
     */
    private static function candidate($entry): ?array
    {
        if (!\is_array($entry) || ($entry['draft'] ?? null) === true || ($entry['prerelease'] ?? null) === true) {
            return null;
        }
        $tag = $entry['tag_name'] ?? null;
        if (!\is_string($tag)) {
            return null;
        }
        // One leading `v`, and only in front of a digit: `v0.2.0` is 0.2.0, while a tag that merely
        // starts with the letter (`vendor-freeze`) is left alone and fails the check below as it
        // should, rather than being silently rewritten into something the parser accepts.
        $version = preg_match('/^v(\d.*)$/', $tag, $matches) === 1 ? $matches[1] : $tag;
        try {
            $normalized = (new VersionParser())->normalize($version);
        } catch (\UnexpectedValueException $e) {
            return null;
        }
        if (VersionParser::parseStability($normalized) !== 'stable') {
            return null;
        }

        return ['version' => $version, 'normalized' => $normalized, 'tag' => $tag, 'entry' => $entry];
    }

    /** @param array{version: string, normalized: string, tag: string, entry: array<mixed>} $candidate */
    private function release(array $candidate): Release
    {
        $entry = $candidate['entry'];
        $tag = $candidate['tag'];

        return new Release(
            $candidate['version'],
            $tag,
            self::assetUrl($entry, $tag, self::PHAR_ASSET),
            self::assetUrl($entry, $tag, self::CHECKSUM_ASSET),
            self::assetUrl($entry, $tag, self::SIGNATURE_ASSET)
        );
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
     * @param array<mixed> $entry
     */
    private static function assetUrl(array $entry, string $tag, string $name): string
    {
        $assets = $entry['assets'] ?? null;
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
