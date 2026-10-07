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
 * Reads the release list (`GET /repos/somework/lockrot/releases`) and chooses the release that
 * self-update installs, by the rules in docs/phar.md#which-release-it-installs. It does not use
 * `releases/latest`, which names one release and does not say whether this archive can take it.
 * The walk stops at the page that reaches the running version: GitHub lists newest first, and
 * SECURITY.md rules out backports. `--allow-major` moves one major version per run, so a release
 * that warns about the next step is not skipped.
 *
 * @internal
 */
final class ReleaseLocator
{
    public const DEFAULT_URL = 'https://api.github.com/repos/somework/lockrot/releases?per_page=100';
    /** The page size that DEFAULT_URL asks for: a page with fewer entries is the last one. */
    public const PER_PAGE = 100;
    /** A bound on the list, whatever GitHub answers: MAX_PAGES pages reach far past any update. */
    public const MAX_PAGES = 10;
    public const PHAR_ASSET = 'lockrot.phar';
    public const CHECKSUM_ASSET = 'lockrot.phar.sha256';
    /**
     * Not `lockrot.phar.sig`: PHIVE takes any release asset that ends in `.asc` or `.sig` for the
     * GPG signature, and the last match wins (`GithubRepository::getReleasesByRequestedPhar()` in
     * https://github.com/phar-io/phive). The suffix `.json` names the format and PHIVE ignores it.
     */
    public const SIGNATURE_ASSET = 'lockrot.phar.sig.json';
    /** `.json` for the same reason as {@see SIGNATURE_ASSET}. */
    public const METADATA_ASSET = 'lockrot.phar.meta.json';
    /** The first release that publishes {@see METADATA_ASSET}: a later one without it is an error. */
    public const FIRST_DESCRIBED_VERSION = '0.13.0';
    public const REINSTALL_DOCS = 'https://lockrot.dev/phar/#reinstalling-by-hand';

    private HttpClientInterface $http;
    private string $trustedKey;
    private ?string $token;
    private string $url;
    private string $currentVersion;
    private string $phpVersion;
    /** @var array<array-key, string> keyed by reason, with the line of a skipped tag appended */
    private array $notes = [];

    /**
     * @param string  $trustedKey the fingerprint of the key this archive verifies with
     *                            ({@see SignatureVerifierInterface::keyFingerprint()})
     * @param string  $url        the release list, where a page after the first adds `page=N`
     * @param ?string $phpVersion the PHP to choose for as `major.minor.patch`, null for the running one
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

    /** The first number of $version, so all of 0.x is major version 0. */
    public static function majorOf(string $version): int
    {
        return (int) $version;
    }

    /**
     * Walks the releases newest first and returns the first one that can be installed, or null when
     * the build is current in its line. Each release passed over leaves a note ({@see notes()}).
     * With $force, the newest release at or below the running version is also a candidate, in the
     * running major only. An unsigned description can lie and must not walk a reinstall further
     * down. With $allowMajor, the lowest higher major with a stable release is also a candidate.
     *
     * @return ?Release the release to install, null when none is newer
     *
     * @throws ConfigException on every failure, when the archive is stranded (newer releases are
     *                         signed with a key that it does not carry, and no release that it can
     *                         install carries that key), and, with $force, when no release in the
     *                         running line can be installed
     */
    public function locate(bool $allowMajor = false, bool $force = false): ?Release
    {
        $this->notes = [];
        $current = (new VersionParser())->normalize($this->currentVersion);
        $candidates = $this->candidates($current);
        $line = self::majorOf($current);
        $next = self::nextMajor($candidates, $line);
        $advised = false;
        $stranded = false;
        foreach ($candidates as $candidate) {
            $major = self::majorOf($candidate['normalized']);
            $newer = Comparator::greaterThan($candidate['normalized'], $current);
            // --force stays in the running line: a build ahead of every release of its major does
            // not fall back to the line below.
            if (!$newer && (!$force || $major < $line)) {
                $this->failUnlessCurrent($stranded, $force, $line);

                return null;
            }
            if ($major > $line && $major !== $next) {
                $this->note('beyond', \sprintf('lockrot %s is more than one major version ahead; --allow-major moves one major version at a time', $candidate['version']));

                continue;
            }
            if ($major === $next && !$allowMajor) {
                $advised = $advised || $this->advise($candidate);

                continue;
            }
            $heldBack = $this->heldBack($candidate);
            if ($heldBack === null) {
                return $this->release($candidate);
            }
            $this->note($heldBack[0], $heldBack[1]);
            $stranded = $stranded || $heldBack[0] === 'key';
            if (!$newer) {
                $this->failUnlessCurrent($stranded, $force, $line);

                return null;
            }
        }

        $this->failUnlessCurrent($stranded, $force, $line);

        return null;
    }

    /**
     * Ends a walk that found nothing to install. That means "current in its line" unless this
     * throws.
     *
     * @param bool $stranded a newer release that passes every other rule is signed with a key that
     *                       this archive does not carry
     *
     * @throws ConfigException
     */
    private function failUnlessCurrent(bool $stranded, bool $force, int $line): void
    {
        if ($stranded) {
            throw new ConfigException('the newer releases are signed with a self-update key this lockrot.phar does not carry, and no release it can install carries that key; download lockrot.phar again by hand and verify it (see '.self::REINSTALL_DOCS.')');
        }
        if ($force) {
            throw new ConfigException(\sprintf('no release in the %d.x line can be installed by this lockrot.phar', $line));
        }
    }

    /**
     * Notes the release that `--allow-major` installs, or the reason that none can be installed.
     * Returns true once a release is named, so the rest of that major is not described.
     *
     * A release that is only advised is not chosen, so a description that cannot be read is a note,
     * not a failure. A next major without its description, or one failed download, must not stop an
     * update in the running major. The walk goes on to older releases of that major.
     *
     * @param array{version: string, normalized: string, tag: string, entry: array<mixed>} $candidate
     */
    private function advise(array $candidate): bool
    {
        try {
            $heldBack = $this->heldBack($candidate);
        } catch (ConfigException $e) {
            $this->note('undescribed', \sprintf('lockrot %s is in the next major version, and its description could not be read: %s', $candidate['version'], $e->getMessage()));

            return false;
        }
        [$reason, $text] = $heldBack ?? ['major', \sprintf(
            'lockrot %s is in the next major version; run lockrot.phar self-update --allow-major to move to it',
            $candidate['version']
        )];
        $this->note($reason, $text);

        return $heldBack === null;
    }

    /**
     * One line for each reason that a newer release was passed over in the last {@see locate()}.
     * Each line names the newest release held back for that reason. The lines stay when locate()
     * throws.
     *
     * The text carries tags and versions from the release list: whoever prints it must escape it.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return array_values($this->notes);
    }

    /** The first note for $reason stays: the walk is newest first, so it names the newest release. */
    private function note(string $reason, string $line): void
    {
        $this->notes[$reason] ??= $line;
    }

    /**
     * The lowest major version above $line that has a candidate, or null. The candidates are newest
     * first, so it is the major of the last one above the line.
     *
     * @param list<array{version: string, normalized: string, tag: string, entry: array<mixed>}> $candidates
     */
    private static function nextMajor(array $candidates, int $line): ?int
    {
        $next = null;
        foreach ($candidates as $candidate) {
            $major = self::majorOf($candidate['normalized']);
            if ($major <= $line) {
                return $next;
            }
            $next = $major;
        }

        return $next;
    }

    /**
     * The reason that $candidate cannot be installed and the note line for it, or null when it can
     * be. This fetches the description, so a caller decides the major version from the tag first.
     *
     * @param array{version: string, normalized: string, tag: string, entry: array<mixed>} $candidate
     *
     * @return array{0: string, 1: string}|null
     */
    private function heldBack(array $candidate): ?array
    {
        $version = $candidate['version'];
        $description = $this->describe($candidate);
        $floor = $description->phpFloor();
        if ($floor === null) {
            return ['floor', \sprintf('lockrot %s does not give its lowest PHP as major.minor.patch in its %s, so it is not installed', $version, self::METADATA_ASSET)];
        }
        if (Comparator::lessThan($this->phpVersion, $floor)) {
            return ['php', \sprintf('lockrot %s needs PHP %s or newer, and this is PHP %s', $version, $floor, $this->phpVersion)];
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
     * Fetched like the archive, without the API token: that token is not for the CDN that a
     * download redirects to.
     *
     * @param array{version: string, normalized: string, tag: string, entry: array<mixed>} $candidate
     */
    private function describe(array $candidate): ReleaseDescription
    {
        if (Comparator::lessThan($candidate['normalized'], (new VersionParser())->normalize(self::FIRST_DESCRIBED_VERSION))) {
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
     * Every usable release in the list, newest first by version. The list is ordered by date, which
     * puts a patch of an older line above a newer minor. Two tags of one version (`v1.0` and
     * `v1.0.0`) sort by tag, so every PHP picks the same one: `usort()` is stable only from 8.0.
     *
     * A tag that is not a version gets a note when it is listed before any release at or below the
     * running version. Otherwise a tag that nobody can compare hides a new release in silence.
     *
     * @param string $current the running version, normalised
     *
     * @return list<array{version: string, normalized: string, tag: string, entry: array<mixed>}>
     */
    private function candidates(string $current): array
    {
        $candidates = [];
        $reached = false;
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $entries = $this->page($page);
            foreach ($entries as $entry) {
                $candidate = self::candidate($entry);
                if (\is_string($candidate)) {
                    if (!$reached) {
                        // One line per tag (GitHub has one release per tag), never merged by reason.
                        $this->notes[] = \sprintf('release tag "%s" is not a version lockrot can compare, so that release was skipped', $candidate);
                    }

                    continue;
                }
                if ($candidate === null) {
                    continue;
                }
                $candidates[] = $candidate;
                if (!Comparator::greaterThan($candidate['normalized'], $current)) {
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
        usort($candidates, static fn (array $a, array $b): int => version_compare($b['normalized'], $a['normalized']) ?: strcmp($b['tag'], $a['tag']));

        return $candidates;
    }

    /**
     * One page of the list. `[]` and `{}`, which `json_decode()` cannot tell apart, are an empty
     * page. Any other JSON that is not a list, such as the single release of `releases/latest`, is
     * refused.
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
     * $entry as a candidate, or its tag when the tag is not a version. Null when the entry is no
     * published stable release: a draft, a pre-release, an entry without a string tag, or a version
     * that is not stable (`v1.0.0-RC1` published without the pre-release flag).
     *
     * @param mixed $entry
     *
     * @return array{version: string, normalized: string, tag: string, entry: array<mixed>}|string|null
     */
    private static function candidate($entry)
    {
        if (!\is_array($entry) || ($entry['draft'] ?? null) === true || ($entry['prerelease'] ?? null) === true) {
            return null;
        }
        $tag = $entry['tag_name'] ?? null;
        if (!\is_string($tag)) {
            return null;
        }
        // Composer's parser takes the tag as it is: a leading `v` or `V` is part of its grammar,
        // and anything around a version (`lockrot-v0.2.0`, a second line) is not a version.
        try {
            $normalized = (new VersionParser())->normalize($tag);
        } catch (\UnexpectedValueException $e) {
            return $tag;
        }
        if (VersionParser::parseStability($normalized) !== 'stable') {
            return null;
        }

        // Comparisons use the normalised form. The shown version drops a fourth number that is 0.
        return [
            'version' => (string) preg_replace('/^(\d+\.\d+\.\d+)\.0(?!\d)/', '$1', $normalized),
            'normalized' => $normalized,
            'tag' => $tag,
            'entry' => $entry,
        ];
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
     * The `browser_download_url` of the asset named exactly $name. GitHub lists assets in no fixed
     * order, and `lockrot.phar` is a prefix of `lockrot.phar.sha256`, so neither a position nor a
     * partial match works.
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
