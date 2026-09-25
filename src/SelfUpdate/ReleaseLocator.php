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
 *   `--allow-major` moves to the next one — the lowest major above the running one that has a
 *   stable release — one step, never several at once, so a release that exists only to warn about
 *   the step after it is not skipped on the way.
 * - A release's lowest PHP and the key that signed it come from its `lockrot.phar.meta.json`
 *   ({@see ReleaseDescription}), fetched only for a release that would otherwise be installed, and
 *   for the newest releases of the next major until one could be, so the advice to use
 *   `--allow-major` is given only when it would install something. Releases before 0.13.0 have none
 *   and are read as what they are: built for PHP 7.4.0, with no claim about their key.
 * - Drafts (listed for a token with push access), pre-releases, and tags that are not a stable
 *   version are not candidates; a published release whose tag is not a version at all is named in a
 *   note. One odd entry does not stop an update; a chosen release that lacks an asset, or whose
 *   description cannot be read, still fails loudly rather than falling back.
 * - Versions are compared in Composer's normalised form (`v1.0`, `V1.0.0` and `1.0.0` are one
 *   version), and shown as `major.minor.patch`.
 *
 * The request carries the same headers the analyzer's GitHub calls do
 * ({@see GitHubApi::headersFor()}), so `GITHUB_TOKEN`, `LOCKROT_GITHUB_TOKEN` and Composer's
 * `github-oauth` all lift the 60-requests-per-hour anonymous limit here too. The list is paged by
 * count (a page shorter than {@see PER_PAGE} is the last), and stops early at the page that reaches
 * the running version: GitHub lists newest first, and SECURITY.md rules out backports.
 *
 * Every failure is a {@see ConfigException}, which the command reports on stderr and turns into
 * exit 2. So is an archive stranded by a key rotation — newer releases signed with a key it does
 * not carry and no release it can install that carries it — which no later run would fix. Releases before 0.6.0 carry no signature, so `--force` cannot install one from a build
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
    /** Where a stranded archive is sent: what to do when self-update cannot follow a new key. */
    public const REINSTALL_DOCS = 'https://lockrot.dev/phar/#reinstalling-by-hand';

    private HttpClientInterface $http;
    private string $trustedKey;
    private ?string $token;
    private string $url;
    private string $currentVersion;
    private string $phpVersion;
    /** @var array<array-key, string> the notes of the last locate(): keyed by reason, a skipped tag's appended */
    private array $notes = [];

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
     * Walks the releases newest first and returns the first one that may be installed, or null when
     * the build is current in its line. Every release passed over on the way leaves a note
     * ({@see notes()}).
     *
     * Without $force the walk ends at the running version: nothing at or below it is an update, so
     * an up-to-date check reads the list and nothing else. With $force the newest release at or
     * below the running version is also a candidate — the one it reinstalls, or the one a build
     * ahead of every release goes back to, which may be older than the running build — as long as
     * it is in the running major version, and nothing below it: the description is unsigned, and a
     * lying one must not walk a reinstall further down.
     *
     * The next major version is the lowest one above the running line that has a stable release, so
     * a major that was skipped or withdrawn is no dead end. Without $allowMajor its newest release
     * that this PHP can run and this archive can verify is described and named as the one
     * `--allow-major` would install; when none can be, the notes say why instead.
     *
     * @param bool $allowMajor also take a release of the next major version
     * @param bool $force      also take the newest release at or below the running version
     *
     * @return ?Release the release to install; null when there is none newer to take
     *
     * @throws ConfigException on every failure, and when the newer releases are signed with a key
     *                         this archive does not carry and none it could install carries it (the
     *                         archive is stranded, and only a download by hand moves it on), or,
     *                         with $force, when no release in the running line can be installed
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
            // --force stays in the running line: a build ahead of every release of its major (one
            // rehearsed before its tag, or one whose release was pulled) does not fall back to the
            // newest release of the line below.
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
     * The end of a walk that found nothing to install, which means "current in its line" unless
     * this throws: when that is not the whole story.
     *
     * @param bool $stranded a release the walk would have taken was signed with a key this archive
     *                       does not carry
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
     * Advice about a release of the next major version while `--allow-major` is not given: a note
     * naming it as the one the flag would install, or, when it could not be installed either, the
     * note saying why. True once the advice has been given, so the rest of that major is not
     * described.
     *
     * @param array{version: string, normalized: string, tag: string, entry: array<mixed>} $candidate
     */
    private function advise(array $candidate): bool
    {
        $heldBack = $this->heldBack($candidate);
        [$reason, $text] = $heldBack ?? ['major', \sprintf(
            'lockrot %s is in the next major version; run lockrot.phar self-update --allow-major to move to it',
            $candidate['version']
        )];
        $this->note($reason, $text);

        return $heldBack === null;
    }

    /**
     * One line for each reason a newer release was passed over during the last {@see locate()} —
     * a new major version, a PHP floor above this one or unreadable, a key this archive does not
     * carry, a tag that is not a version — naming the newest release held back for it. Kept when
     * locate() throws, so a failure further down the list still comes after the reason the newer
     * release was not taken.
     *
     * The text carries tags and versions from the release list: whoever prints it escapes it.
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
     * The lowest major version above $line that has a candidate, or null when there is none: the
     * candidates are newest first, so it is the major of the last one above the line.
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
     * Why $candidate may not be installed, as a reason and the line that says so, or null when it
     * may. Only the major version is decided before this, from the tag alone, so a release beyond
     * the next major costs no request.
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
     * The release's description, fetched from its download URL the way the archive is — without
     * the API token, which is not for the CDN a download redirects to.
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
     * Every usable release in the list, newest first by version — not by list order, which is by
     * date and would put a patch of an older line above a newer minor. Two tags of one version
     * (`v1.0` and `v1.0.0`) are ordered by the tag itself, so every PHP picks the same one: usort()
     * is stable only from PHP 8.0 on.
     *
     * A published release whose tag is not a version is skipped with a note naming the tag, when
     * it is listed before any release at or below the running version — a tag nobody can compare
     * would otherwise hide a new release in silence.
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
     * $entry as a candidate; its tag when it is a published release whose tag is not a version;
     * or null when it is not a published stable release at all: a draft, a pre-release, an entry
     * without a string tag, or a version that is not stable (`v1.0.0-RC1` published without the
     * pre-release flag).
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
        // Composer's parser takes the tag as it is: a leading `v` or `V` is part of its grammar, and
        // anything around a version (`lockrot-v0.2.0`, a second line) is not a version.
        try {
            $normalized = (new VersionParser())->normalize($tag);
        } catch (\UnexpectedValueException $e) {
            return $tag;
        }
        if (VersionParser::parseStability($normalized) !== 'stable') {
            return null;
        }

        // Every comparison is on the normalised form; the version shown is its `major.minor.patch`
        // (the fourth number kept only when it is not 0), whatever spelling the tag used.
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
