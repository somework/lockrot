<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\SelfUpdate;

use Lockrot\Data\Http\HttpResult;
use Lockrot\Exception\ConfigException;
use Lockrot\SelfUpdate\PharUpdater;
use Lockrot\SelfUpdate\Release;
use Lockrot\SelfUpdate\ReleaseLocator;
use Lockrot\Tests\Support\FakeHttpClient;
use Lockrot\Tests\Support\GitHubReleases;
use Lockrot\Tests\Support\SigningKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseLocatorTest extends TestCase
{
    private const URL = ReleaseLocator::DEFAULT_URL;
    private const FIXTURES = __DIR__.'/../../fixtures/http/github-releases';
    private const PHP = '8.4.0';

    private static function fixture(string $name): string
    {
        $body = file_get_contents(self::FIXTURES.'/'.$name);
        self::assertIsString($body);

        return $body;
    }

    private static function releaseKey(): string
    {
        return GitHubReleases::fingerprint(SigningKeys::releasePublicPem());
    }

    private static function otherKey(): string
    {
        return GitHubReleases::fingerprint(SigningKeys::otherPublicPem());
    }

    private static function metaUrl(string $tag): string
    {
        return GitHubReleases::assetUrl($tag, ReleaseLocator::METADATA_ASSET);
    }

    /**
     * A client serving $entries as the one page of the list, plus a description for each tag in
     * $metas — a body, or a whole result for the failure cases.
     *
     * @param list<mixed>                     $entries
     * @param array<string, string|HttpResult> $metas keyed by tag
     */
    private static function http(array $entries, array $metas = [], string $url = self::URL): FakeHttpClient
    {
        $responses = [$url => FakeHttpClient::ok($url, GitHubReleases::listJson($entries))];
        foreach ($metas as $tag => $meta) {
            $metaUrl = self::metaUrl($tag);
            $responses[$metaUrl] = $meta instanceof HttpResult ? $meta : FakeHttpClient::ok($metaUrl, $meta);
        }

        return new FakeHttpClient($responses);
    }

    /**
     * The same, with every tag described as the release workflow would describe it: PHP 7.4.0 and
     * the test release key.
     *
     * @param list<string> $tags
     */
    private static function described(array $tags): FakeHttpClient
    {
        $metas = [];
        foreach ($tags as $tag) {
            $metas[$tag] = GitHubReleases::meta('7.4.0', self::releaseKey());
        }

        return self::http(array_map(static fn (string $tag): array => GitHubReleases::entry($tag), $tags), $metas);
    }

    private function locator(
        FakeHttpClient $http,
        string $current,
        ?string $php = self::PHP,
        ?string $trustedKey = null,
        ?string $token = null,
        string $url = self::URL
    ): ReleaseLocator {
        return new ReleaseLocator($http, $trustedKey ?? self::releaseKey(), $token, $url, $current, $php);
    }

    /**
     * locate() throws $message; the notes it decided on the way stay readable afterwards.
     */
    private static function assertLocateFails(string $message, ReleaseLocator $locator, bool $allowMajor = false, bool $force = false): void
    {
        try {
            $locator->locate($allowMajor, $force);
        } catch (ConfigException $e) {
            self::assertSame($message, $e->getMessage());

            return;
        }
        self::fail('expected locate() to fail with: '.$message);
    }

    private static function stranded(): string
    {
        return 'the newer releases are signed with a self-update key this lockrot.phar does not carry, and no release it can install carries that key; download lockrot.phar again by hand and verify it (see https://lockrot.dev/phar/#reinstalling-by-hand)';
    }

    private static function keyNote(string $version): string
    {
        return 'lockrot '.$version.' is signed with a self-update key this lockrot.phar does not carry ('.self::otherKey().'); only a release that carries that key can update to it';
    }

    private static function assertChose(string $version, ?Release $release): void
    {
        self::assertNotNull($release, 'expected '.$version.' to be chosen');
        self::assertSame($version, $release->version());
    }

    public function testTheDefaultUrlListsTheProjectsReleases(): void
    {
        self::assertSame('https://api.github.com/repos/somework/lockrot/releases?per_page=100', ReleaseLocator::DEFAULT_URL);
    }

    /**
     * Pinned as numbers, not only through the paging tests that read the constants: the page size
     * is the one DEFAULT_URL asks GitHub for, and a page shorter than it is taken as the last.
     */
    public function testThePagingBoundsArePinned(): void
    {
        self::assertSame(100, ReleaseLocator::PER_PAGE);
        self::assertSame(10, ReleaseLocator::MAX_PAGES);
        self::assertSame('0.13.0', ReleaseLocator::FIRST_DESCRIBED_VERSION);
        self::assertSame('lockrot.phar.meta.json', ReleaseLocator::METADATA_ASSET);
    }

    /**
     * The recorded-shape list: a pre-release that was not flagged as one, a draft (drafts are listed
     * for a token with push access), a flagged pre-release, the first described release and one from
     * before descriptions. Only the last two are candidates, and the newer is chosen.
     */
    public function testReadsARecordedReleaseList(): void
    {
        $metaUrl = 'https://github.com/somework/lockrot/releases/download/v0.13.0/lockrot.phar.meta.json';
        $http = new FakeHttpClient([
            self::URL => FakeHttpClient::ok(self::URL, self::fixture('releases.json')),
            $metaUrl => FakeHttpClient::ok($metaUrl, self::fixture('lockrot.phar.meta.json')),
        ]);

        $locator = $this->locator($http, '0.12.0', self::PHP, 'sha256:ec3ca71b1a3ced86f871b89cff7973b58454e5694136683680b72b18070a8f87');
        $release = $locator->locate();

        self::assertNotNull($release);
        self::assertSame('0.13.0', $release->version());
        self::assertSame('v0.13.0', $release->tag());
        self::assertSame('https://github.com/somework/lockrot/releases/download/v0.13.0/lockrot.phar', $release->pharUrl());
        self::assertSame('https://github.com/somework/lockrot/releases/download/v0.13.0/lockrot.phar.sha256', $release->checksumUrl());
        self::assertSame('https://github.com/somework/lockrot/releases/download/v0.13.0/lockrot.phar.sig.json', $release->signatureUrl());
        self::assertSame([], $locator->notes());
        self::assertSame([self::URL, $metaUrl], $http->requested());
    }

    public function testPicksTheNewestReleaseInTheRunningMajor(): void
    {
        $http = self::described(['v0.14.0', 'v0.13.1']);

        $locator = $this->locator($http, '0.13.0');
        $release = $locator->locate();

        self::assertChose('0.14.0', $release);
        self::assertSame([], $locator->notes());
        self::assertSame([self::URL, self::metaUrl('v0.14.0')], $http->requested());
    }

    public function testTheNewestIsDecidedByVersionNotByListOrder(): void
    {
        $http = self::http([GitHubReleases::entry('v0.9.0'), GitHubReleases::entry('v0.10.0'), GitHubReleases::entry('v0.9.5')]);

        self::assertChose('0.10.0', $this->locator($http, '0.8.0')->locate());
    }

    public function testADraftIsSkipped(): void
    {
        $http = self::http([GitHubReleases::entry('v0.12.1', GitHubReleases::ASSETS, true), GitHubReleases::entry('v0.12.0')]);

        self::assertChose('0.12.0', $this->locator($http, '0.11.0')->locate());
    }

    public function testAPrereleaseIsSkipped(): void
    {
        $http = self::http([GitHubReleases::entry('v0.12.1', GitHubReleases::ASSETS, false, true), GitHubReleases::entry('v0.12.0')]);

        self::assertChose('0.12.0', $this->locator($http, '0.11.0')->locate());
    }

    /** Only a flag that says so makes a draft or a pre-release; a list that leaves them out lists releases. */
    public function testAnEntryWithoutTheFlagsIsARelease(): void
    {
        $entry = array_diff_key(GitHubReleases::entry('v0.12.0'), ['draft' => true, 'prerelease' => true]);
        $http = self::http([$entry]);

        self::assertChose('0.12.0', $this->locator($http, '0.11.0')->locate());
    }

    /** A tag GitHub was not told is a pre-release is still one if its version says so. */
    public function testATagWithAPreReleaseSuffixIsSkippedEvenWhenNotFlagged(): void
    {
        $http = self::http([GitHubReleases::entry('v0.12.1-RC1'), GitHubReleases::entry('v0.12.0')]);

        self::assertChose('0.12.0', $this->locator($http, '0.11.0')->locate());
    }

    public function testATagWithoutALeadingVIsAcceptedAsIs(): void
    {
        $http = self::http([GitHubReleases::entry('0.3.1')]);

        $release = $this->locator($http, '0.3.0')->locate();

        self::assertNotNull($release);
        self::assertSame('0.3.1', $release->version());
        self::assertSame('0.3.1', $release->tag());
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function unusableEntries(): iterable
    {
        yield 'a tag that is not a version' => [GitHubReleases::entry('nightly')];
        // Only a `v` at the very front is a version prefix; a tag that merely contains one is not
        // rewritten into whatever part of it the parser would accept.
        yield 'a tag that only contains a version' => [GitHubReleases::entry('lockrot-v0.2.0')];
        // The same on the other side: a tag is a version or it is not, never its first line.
        yield 'a tag of two lines' => [GitHubReleases::entry("v0.2.0\nand whatever follows")];
        yield 'an empty tag' => [GitHubReleases::entry('')];
        yield 'a tag that is not a string' => [array_merge(GitHubReleases::entry('v0.2.0'), ['tag_name' => 20])];
        yield 'no tag at all' => [array_diff_key(GitHubReleases::entry('v0.2.0'), ['tag_name' => true])];
        yield 'an entry that is not an object' => ['v0.2.0'];
    }

    /**
     * @dataProvider unusableEntries
     *
     * @param mixed $entry
     */
    #[DataProvider('unusableEntries')]
    public function testAnEntryThatIsNotAVersionedReleaseIsSkipped($entry): void
    {
        $http = self::http([$entry, GitHubReleases::entry('v0.1.0')]);

        self::assertChose('0.1.0', $this->locator($http, '0.0.1')->locate());
    }

    /** @return iterable<string, array{0: string}> */
    public static function listsWithNothingUsable(): iterable
    {
        yield 'an empty list' => ['[]'];
        // json_decode() reads {} and [] alike, and an empty object holds no release either.
        yield 'an empty object' => ['{}'];
        yield 'only a draft' => [GitHubReleases::listJson([GitHubReleases::entry('v0.2.0', GitHubReleases::ASSETS, true)])];
    }

    /** @dataProvider listsWithNothingUsable */
    #[DataProvider('listsWithNothingUsable')]
    public function testAListWithNothingUsableIsNoPublishedRelease(string $body): void
    {
        $http = new FakeHttpClient([self::URL => FakeHttpClient::ok(self::URL, $body)]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('no published release found');
        $this->locator($http, '0.1.0')->locate();
    }

    /** What releases/latest answers — one release, not a list — is not what this reads. */
    public function testAJsonObjectIsNotAListOfReleases(): void
    {
        $body = json_encode(GitHubReleases::entry('v0.2.0'), \JSON_THROW_ON_ERROR);
        $http = new FakeHttpClient([self::URL => FakeHttpClient::ok(self::URL, $body)]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage(self::URL.' is not a list of releases');
        $this->locator($http, '0.1.0')->locate();
    }

    /** The newest release held back is the one named, and only once, however many share the reason. */
    public function testANewerMajorIsHeldBackWithANote(): void
    {
        $http = self::described(['v2.1.0', 'v2.0.0', 'v1.3.0']);

        $locator = $this->locator($http, '1.2.0');
        $release = $locator->locate();

        self::assertChose('1.3.0', $release);
        self::assertSame(
            ['lockrot 2.1.0 is in the next major version; run lockrot.phar self-update --allow-major to move to it'],
            $locator->notes()
        );
        // The next major is described once, for the advice; the rest of its line is not.
        self::assertSame([self::URL, self::metaUrl('v2.1.0'), self::metaUrl('v1.3.0')], $http->requested());
    }

    public function testAllowMajorMovesToTheNextMajor(): void
    {
        $http = self::described(['v2.1.0', 'v2.0.0', 'v1.3.0']);

        $locator = $this->locator($http, '1.2.0');
        $release = $locator->locate(true);

        self::assertChose('2.1.0', $release);
        self::assertSame([], $locator->notes());
    }

    /**
     * --allow-major is one step, not a jump: a 1.x archive goes to the newest 2.x, and the next
     * `self-update --allow-major` from there goes on to 3.x. A 2.x-only step — a release that warns
     * about a PHP floor one minor ahead of raising it — is not skipped on the way.
     */
    public function testAllowMajorMovesOneMajorVersionAtATime(): void
    {
        $http = self::described(['v3.1.0', 'v3.0.0', 'v2.1.0', 'v1.3.0']);

        $locator = $this->locator($http, '1.2.0');
        $release = $locator->locate(true);

        self::assertChose('2.1.0', $release);
        self::assertSame(
            ['lockrot 3.1.0 is more than one major version ahead; --allow-major moves one major version at a time'],
            $locator->notes()
        );
    }

    public function testWithoutAllowMajorBothKindsOfNewerMajorAreNamed(): void
    {
        $http = self::described(['v3.1.0', 'v3.0.0', 'v2.1.0', 'v2.0.0', 'v1.3.0']);

        $locator = $this->locator($http, '1.2.0');
        $release = $locator->locate();

        self::assertChose('1.3.0', $release);
        self::assertSame([
            'lockrot 3.1.0 is more than one major version ahead; --allow-major moves one major version at a time',
            'lockrot 2.1.0 is in the next major version; run lockrot.phar self-update --allow-major to move to it',
        ], $locator->notes());
    }

    /** Taken literally: the major is the first number, so all of 0.x is one line and 1.0 is the next. */
    public function testBeforeOneZeroTheZeroLineIsOneMajor(): void
    {
        $http = self::described(['v1.0.0', 'v0.14.0']);

        $locator = $this->locator($http, '0.13.0');
        $release = $locator->locate();

        self::assertChose('0.14.0', $release);
        self::assertSame(
            ['lockrot 1.0.0 is in the next major version; run lockrot.phar self-update --allow-major to move to it'],
            $locator->notes()
        );
    }

    public function testOnlyANewerMajorLeavesNothingToInstall(): void
    {
        $http = self::described(['v1.0.0', 'v0.13.0']);

        $locator = $this->locator($http, '0.13.0');
        $release = $locator->locate();

        self::assertNull($release);
        self::assertSame(
            ['lockrot 1.0.0 is in the next major version; run lockrot.phar self-update --allow-major to move to it'],
            $locator->notes()
        );
        self::assertSame([self::URL, self::metaUrl('v1.0.0')], $http->requested());
    }

    public function testAReleaseNeedingANewerPhpIsSkipped(): void
    {
        $http = self::http(
            [GitHubReleases::entry('v0.16.0'), GitHubReleases::entry('v0.15.0'), GitHubReleases::entry('v0.14.0')],
            [
                'v0.16.0' => GitHubReleases::meta('8.2.0', self::releaseKey()),
                'v0.15.0' => GitHubReleases::meta('8.1.0', self::releaseKey()),
                'v0.14.0' => GitHubReleases::meta('7.4.0', self::releaseKey()),
            ]
        );

        $locator = $this->locator($http, '0.13.0', '7.4.33');
        $release = $locator->locate();

        self::assertChose('0.14.0', $release);
        self::assertSame(['lockrot 0.16.0 needs PHP 8.2.0 or newer, and this is PHP 7.4.33'], $locator->notes());
    }

    public function testAFloorEqualToThisPhpIsInstallable(): void
    {
        $http = self::http([GitHubReleases::entry('v0.14.0')], ['v0.14.0' => GitHubReleases::meta('7.4.33', self::releaseKey())]);

        self::assertChose('0.14.0', $this->locator($http, '0.13.0', '7.4.33')->locate());
    }

    public function testWithoutAPhpVersionTheRunningPhpIsUsed(): void
    {
        $running = \sprintf('%d.%d.%d', \PHP_MAJOR_VERSION, \PHP_MINOR_VERSION, \PHP_RELEASE_VERSION);
        $next = (\PHP_MAJOR_VERSION + 1).'.0.0';
        $http = self::http(
            [GitHubReleases::entry('v0.15.0'), GitHubReleases::entry('v0.14.0')],
            [
                'v0.15.0' => GitHubReleases::meta($next, self::releaseKey()),
                'v0.14.0' => GitHubReleases::meta($running, self::releaseKey()),
            ]
        );

        $locator = $this->locator($http, '0.13.0', null);
        $release = $locator->locate();

        self::assertChose('0.14.0', $release);
        self::assertSame(['lockrot 0.15.0 needs PHP '.$next.' or newer, and this is PHP '.$running], $locator->notes());
    }

    /**
     * The rotation, with the test keys standing in: an archive that carries the old key skips the
     * releases signed with the new one and installs the transition release — signed with the old
     * key, carrying the new one — and the next self-update from there reaches the rest.
     */
    public function testAReleaseSignedWithAKeyThisArchiveDoesNotCarryIsSkippedForTheTransitionRelease(): void
    {
        $http = self::http(
            [GitHubReleases::entry('v1.2.0'), GitHubReleases::entry('v1.1.0'), GitHubReleases::entry('v1.0.1')],
            [
                'v1.2.0' => GitHubReleases::meta('7.4.0', self::otherKey()),
                'v1.1.0' => GitHubReleases::meta('7.4.0', self::otherKey()),
                'v1.0.1' => GitHubReleases::meta('7.4.0', self::releaseKey()),
            ]
        );

        $locator = $this->locator($http, '1.0.0');
        $release = $locator->locate();

        self::assertChose('1.0.1', $release);
        self::assertSame(
            ['lockrot 1.2.0 is signed with a self-update key this lockrot.phar does not carry ('.self::otherKey().'); only a release that carries that key can update to it'],
            $locator->notes()
        );
    }

    public function testAfterTheTransitionTheNewKeyReachesTheRest(): void
    {
        $http = self::http(
            [GitHubReleases::entry('v1.2.0'), GitHubReleases::entry('v1.1.0'), GitHubReleases::entry('v1.0.1')],
            [
                'v1.2.0' => GitHubReleases::meta('7.4.0', self::otherKey()),
                'v1.1.0' => GitHubReleases::meta('7.4.0', self::otherKey()),
            ]
        );

        $locator = $this->locator($http, '1.0.1', self::PHP, self::otherKey());
        $release = $locator->locate();

        self::assertChose('1.2.0', $release);
        self::assertSame([], $locator->notes());
    }

    /**
     * Every release before 0.13.0 was built for PHP 7.4.0 (build/phar/composer.json has said so
     * since the first commit) and describes nothing, so it is read as exactly that: the 7.4 floor,
     * and no claim about its key — the signature check alone decides, as it always has.
     */
    public function testAReleaseBeforeDescriptionsIsReadAsTheSevenFourFloorWithNoKeyClaim(): void
    {
        $assets = [ReleaseLocator::PHAR_ASSET, ReleaseLocator::CHECKSUM_ASSET, ReleaseLocator::SIGNATURE_ASSET];
        $http = self::http([GitHubReleases::entry('v0.12.0', $assets)]);

        $locator = $this->locator($http, '0.11.0', '7.4.0', self::otherKey());
        $release = $locator->locate();

        self::assertChose('0.12.0', $release);
        self::assertSame([self::URL], $http->requested(), 'a release before 0.13.0 has no description to fetch');
    }

    public function testAReleaseBeforeDescriptionsNeedsPhpSevenFour(): void
    {
        $assets = [ReleaseLocator::PHAR_ASSET, ReleaseLocator::CHECKSUM_ASSET, ReleaseLocator::SIGNATURE_ASSET];
        $http = self::http([GitHubReleases::entry('v0.12.0', $assets)]);

        $locator = $this->locator($http, '0.11.0', '7.3.33');
        $release = $locator->locate();

        self::assertNull($release);
        self::assertSame(['lockrot 0.12.0 needs PHP 7.4.0 or newer, and this is PHP 7.3.33'], $locator->notes());
    }

    public function testTheFirstDescribedVersionWithoutItsDescriptionIsAnError(): void
    {
        $assets = [ReleaseLocator::PHAR_ASSET, ReleaseLocator::CHECKSUM_ASSET, ReleaseLocator::SIGNATURE_ASSET];
        $http = self::http([GitHubReleases::entry('v0.13.0', $assets)]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('release v0.13.0 has no lockrot.phar.meta.json asset');
        $this->locator($http, '0.12.0')->locate();
    }

    /** @return iterable<string, array{0: HttpResult|string, 1: string}> */
    public static function unreadableDescriptions(): iterable
    {
        $url = 'https://github.com/somework/lockrot/releases/download/v0.14.0/lockrot.phar.meta.json';

        yield 'not found' => [FakeHttpClient::status($url, 404, 'Not Found'), 'could not download '.$url.': HTTP 404'];
        yield 'unreachable' => [FakeHttpClient::transportFailure($url, 'Connection reset'), 'could not download '.$url.': Connection reset'];
        yield 'not json' => ['<html>502</html>', $url.' is not a lockrot release description'];
        yield 'a floor that is not a string' => ['{"php": 8.1, "selfupdate-key": "sha256:'.str_repeat('a', 64).'"}', $url.' is not a lockrot release description'];
        yield 'a key that is not a sha256' => ['{"php": "7.4.0", "selfupdate-key": "md5:'.str_repeat('a', 32).'"}', $url.' is not a lockrot release description'];
    }

    /**
     * @dataProvider unreadableDescriptions
     *
     * @param HttpResult|string $meta
     */
    #[DataProvider('unreadableDescriptions')]
    public function testAnUnreadableDescriptionIsAnError($meta, string $message): void
    {
        $http = self::http([GitHubReleases::entry('v0.14.0')], ['v0.14.0' => $meta]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);
        $this->locator($http, '0.13.0')->locate();
    }

    /** The description is a release download like the archive: no API token goes to the CDN. */
    public function testTheDescriptionIsFetchedWithoutTheApiToken(): void
    {
        $http = self::described(['v0.14.0']);

        $this->locator($http, '0.13.0', self::PHP, null, 'ghp_x')->locate();

        self::assertContains('Authorization: token ghp_x', $http->headers()[0]);
        self::assertSame(PharUpdater::DOWNLOAD_HEADERS, $http->headers()[1]);
    }

    public function testUpToDateNeedsOnlyTheList(): void
    {
        $http = self::described(['v0.14.0', 'v0.13.0']);

        $locator = $this->locator($http, '0.14.0');
        $release = $locator->locate();

        self::assertNull($release);
        self::assertSame([], $locator->notes());
        self::assertSame([self::URL], $http->requested());
    }

    public function testForceReturnsTheRunningVersionsOwnRelease(): void
    {
        $http = self::described(['v0.14.0', 'v0.13.0']);

        self::assertChose('0.14.0', $this->locator($http, '0.14.0')->locate(false, true));
    }

    public function testForceStillPrefersANewerRelease(): void
    {
        $http = self::described(['v0.14.0', 'v0.13.0']);

        self::assertChose('0.14.0', $this->locator($http, '0.13.0')->locate(false, true));
    }

    public function testForceFromADevBuildAheadOfEveryReleaseTakesTheNewestInItsLine(): void
    {
        $http = self::described(['v1.0.0', 'v0.14.0', 'v0.13.0']);

        $locator = $this->locator($http, '0.15.0');
        $release = $locator->locate(false, true);

        self::assertChose('0.14.0', $release);
        self::assertSame(
            ['lockrot 1.0.0 is in the next major version; run lockrot.phar self-update --allow-major to move to it'],
            $locator->notes()
        );
    }

    /**
     * The description is unsigned. A lying one for the newest release at or below the running
     * version must not walk --force down to an older one: that would be a downgrade chosen by a file
     * nobody signed. --force considers that one release and no other below it.
     */
    public function testForceNeverWalksBelowTheNewestReleaseAtOrBelowTheRunningOne(): void
    {
        $http = self::http(
            [GitHubReleases::entry('v0.14.0'), GitHubReleases::entry('v0.13.0')],
            [
                'v0.14.0' => GitHubReleases::meta('99.0.0', self::releaseKey()),
                'v0.13.0' => GitHubReleases::meta('7.4.0', self::releaseKey()),
            ]
        );

        $locator = $this->locator($http, '0.14.0');

        self::assertLocateFails('no release in the 0.x line can be installed by this lockrot.phar', $locator, false, true);
        self::assertSame(['lockrot 0.14.0 needs PHP 99.0.0 or newer, and this is PHP '.self::PHP], $locator->notes());
        self::assertNotContains(self::metaUrl('v0.13.0'), $http->requested());
    }

    /**
     * --force stays in the running major line. A build ahead of every release of its line — a 1.0.0
     * archive rehearsed before its tag, or one whose release was pulled — must not reinstall the
     * newest release of the line below.
     */
    public function testForceNeverCrossesDownToAnOlderMajor(): void
    {
        $http = self::described(['v0.14.0']);

        $locator = $this->locator($http, '1.0.0');

        self::assertLocateFails('no release in the 1.x line can be installed by this lockrot.phar', $locator, false, true);
        self::assertSame([], $locator->notes());
        self::assertSame([self::URL], $http->requested(), 'an older major is decided from the tag alone');
    }

    /**
     * @param int<1, max> $count
     *
     * @return list<array<string, mixed>> releases 0.1.($first + $count - 1) down to 0.1.$first, newest
     *                                    first as GitHub lists them; all from before descriptions
     */
    private static function page(int $first, int $count): array
    {
        $assets = [ReleaseLocator::PHAR_ASSET, ReleaseLocator::CHECKSUM_ASSET, ReleaseLocator::SIGNATURE_ASSET];
        $entries = [];
        for ($patch = $first + $count - 1; $patch >= $first; --$patch) {
            $entries[] = GitHubReleases::entry('v0.1.'.$patch, $assets);
        }

        return $entries;
    }

    public function testAFullPageIsFollowedByTheNext(): void
    {
        $second = self::URL.'&page=2';
        $http = new FakeHttpClient([
            self::URL => FakeHttpClient::ok(self::URL, GitHubReleases::listJson(self::page(101, ReleaseLocator::PER_PAGE))),
            $second => FakeHttpClient::ok($second, GitHubReleases::listJson(self::page(1, 3))),
        ]);

        $locator = $this->locator($http, '0.0.1');
        $release = $locator->locate();

        self::assertChose('0.1.200', $release);
        self::assertSame([self::URL, $second], $http->requested());
    }

    public function testAShortPageEndsTheList(): void
    {
        $http = new FakeHttpClient([
            self::URL => FakeHttpClient::ok(self::URL, GitHubReleases::listJson(self::page(1, ReleaseLocator::PER_PAGE - 1))),
        ]);

        self::assertChose('0.1.99', $this->locator($http, '0.0.1')->locate());
        self::assertSame([self::URL], $http->requested());
    }

    /** GitHub lists newest first, so once the running version is on a page nothing newer follows. */
    public function testAPageThatReachesTheRunningVersionEndsTheList(): void
    {
        $http = new FakeHttpClient([
            self::URL => FakeHttpClient::ok(self::URL, GitHubReleases::listJson(self::page(1, ReleaseLocator::PER_PAGE))),
        ]);

        self::assertChose('0.1.100', $this->locator($http, '0.1.1')->locate());
        self::assertSame([self::URL], $http->requested());
    }

    public function testPagingStopsAfterMaxPages(): void
    {
        $responses = [];
        for ($page = 1; $page <= ReleaseLocator::MAX_PAGES + 1; ++$page) {
            $url = $page === 1 ? self::URL : self::URL.'&page='.$page;
            // Page 1 holds the newest, each later page the hundred before it.
            $responses[$url] = FakeHttpClient::ok($url, GitHubReleases::listJson(self::page((ReleaseLocator::MAX_PAGES + 1 - $page) * ReleaseLocator::PER_PAGE + 1, ReleaseLocator::PER_PAGE)));
        }
        $http = new FakeHttpClient($responses);

        $locator = $this->locator($http, '0.0.1');
        $release = $locator->locate();

        self::assertChose('0.1.'.((ReleaseLocator::MAX_PAGES + 1) * ReleaseLocator::PER_PAGE), $release);
        self::assertCount(ReleaseLocator::MAX_PAGES, $http->requested());
        self::assertSame(self::URL.'&page='.ReleaseLocator::MAX_PAGES, $http->requested()[ReleaseLocator::MAX_PAGES - 1]);
    }

    public function testAConfiguredUrlWithoutAQueryGetsOneForPageTwo(): void
    {
        $url = 'https://releases.example.test/releases.json';
        $second = $url.'?page=2';
        $http = new FakeHttpClient([
            $url => FakeHttpClient::ok($url, GitHubReleases::listJson(self::page(1, ReleaseLocator::PER_PAGE))),
            $second => FakeHttpClient::ok($second, '[]'),
        ]);

        $this->locator($http, '0.0.1', self::PHP, null, null, $url)->locate();

        self::assertSame([$url, $second], $http->requested());
    }

    public function testSendsTheSameGithubHeadersTheAnalyzerUses(): void
    {
        $http = self::described(['v0.14.0']);

        $this->locator($http, '0.14.0', self::PHP, null, 'ghp_x')->locate();

        $headers = $http->headers()[0];
        self::assertContains('Accept: application/vnd.github+json', $headers);
        self::assertContains('User-Agent: lockrot', $headers);
        self::assertContains('Authorization: token ghp_x', $headers);
    }

    public function testWithoutATokenNoAuthorizationHeaderIsSent(): void
    {
        $http = self::described(['v0.14.0']);

        $this->locator($http, '0.14.0')->locate();

        foreach ($http->headers()[0] as $header) {
            self::assertStringStartsNotWith('Authorization', $header);
        }
    }

    public function testAMissingListIsReportedAsNoPublishedRelease(): void
    {
        $http = new FakeHttpClient([self::URL => FakeHttpClient::status(self::URL, 404, self::fixture('not-found.json'))]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('no published release found');
        $this->locator($http, '0.1.0')->locate();
    }

    /** @return iterable<string, array{0: string}> */
    public static function missingAssets(): iterable
    {
        yield 'the archive' => [ReleaseLocator::PHAR_ASSET];
        yield 'the checksum' => [ReleaseLocator::CHECKSUM_ASSET];
        // A release from before signing (0.5.0 and earlier) is not one this build can install.
        yield 'the signature' => [ReleaseLocator::SIGNATURE_ASSET];
    }

    /**
     * The chosen release fails loudly on a missing asset, rather than quietly falling back to an
     * older one.
     *
     * @dataProvider missingAssets
     */
    #[DataProvider('missingAssets')]
    public function testAChosenReleaseWithoutAnAssetNamesTheTag(string $missing): void
    {
        $assets = array_values(array_diff(GitHubReleases::ASSETS, [$missing]));
        $http = self::http(
            [GitHubReleases::entry('v0.14.0', $assets), GitHubReleases::entry('v0.13.1')],
            ['v0.14.0' => GitHubReleases::meta('7.4.0', self::releaseKey())]
        );

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('release v0.14.0 has no '.$missing.' asset');
        $this->locator($http, '0.13.0')->locate();
    }

    /** An asset listed under the right name but with nothing to download from is no asset at all. */
    public function testAnAssetWithoutADownloadUrlCountsAsMissing(): void
    {
        $entry = GitHubReleases::entry('v0.12.0');
        $assets = $entry['assets'];
        self::assertIsArray($assets);
        $assets[0] = ['name' => ReleaseLocator::PHAR_ASSET, 'browser_download_url' => ''];
        $entry['assets'] = $assets;
        $http = self::http([$entry]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('release v0.12.0 has no lockrot.phar asset');
        $this->locator($http, '0.11.0')->locate();
    }

    public function testAReleaseWithoutAnAssetListNamesTheTag(): void
    {
        $http = self::http([array_merge(GitHubReleases::entry('v0.12.0'), ['assets' => 'none'])]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('release v0.12.0 has no lockrot.phar asset');
        $this->locator($http, '0.11.0')->locate();
    }

    /**
     * PHIVE reads any release asset ending in `.asc` or `.sig` as the GPG signature of the PHAR —
     * last one wins — so neither the self-update signature nor the description may carry either
     * suffix: 0.6.0 shipped `lockrot.phar.sig` and `phive install somework/lockrot` broke until the
     * asset was renamed.
     */
    public function testNoSelfUpdateAssetNameIsOnePhiveTakesForAGpgSignature(): void
    {
        foreach ([ReleaseLocator::SIGNATURE_ASSET, ReleaseLocator::METADATA_ASSET] as $asset) {
            foreach (['.asc', '.sig', '.phar'] as $suffix) {
                self::assertStringEndsNotWith($suffix, $asset);
            }
        }
        self::assertStringEndsWith('.asc', 'lockrot.phar.asc');
    }

    public function testANonJsonBodyIsAnError(): void
    {
        $http = new FakeHttpClient([self::URL => FakeHttpClient::ok(self::URL, '<html>502 Bad Gateway</html>')]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('the response from '.self::URL.' is not JSON');
        $this->locator($http, '0.1.0')->locate();
    }

    public function testATransportFailureNamesTheUrlAndTheReason(): void
    {
        $http = new FakeHttpClient([self::URL => FakeHttpClient::transportFailure(self::URL, 'Could not resolve host: api.github.com')]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('could not read '.self::URL.': Could not resolve host: api.github.com');
        $this->locator($http, '0.1.0')->locate();
    }

    public function testARateLimitedResponseIsAnErrorNamingTheStatus(): void
    {
        $http = new FakeHttpClient([self::URL => FakeHttpClient::status(self::URL, 403, '{"message":"API rate limit exceeded"}')]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('could not read '.self::URL.': HTTP 403');
        $this->locator($http, '0.1.0')->locate();
    }

    /**
     * A rotation whose transition release is missing (never shipped, or pulled): every newer
     * release names a key this archive does not carry, and nothing it could install carries it. No
     * later run changes that, so it is an error on every run — not "up to date" forever.
     */
    public function testNewerReleasesOnlyUnderAnotherKeyStrandTheArchive(): void
    {
        $http = self::http(
            [GitHubReleases::entry('v0.15.0'), GitHubReleases::entry('v0.14.0'), GitHubReleases::entry('v0.13.0')],
            ['v0.15.0' => GitHubReleases::meta('7.4.0', self::otherKey()), 'v0.14.0' => GitHubReleases::meta('7.4.0', self::otherKey())]
        );
        $locator = $this->locator($http, '0.13.0');

        self::assertLocateFails(self::stranded(), $locator);
        self::assertSame([self::keyNote('0.15.0')], $locator->notes());
        self::assertSame([self::URL, self::metaUrl('v0.15.0'), self::metaUrl('v0.14.0')], $http->requested());
    }

    /** A transition release this PHP cannot run leaves the archive just as stranded. */
    public function testATransitionReleaseThisPhpCannotRunStillStrandsTheArchive(): void
    {
        $http = self::http(
            [GitHubReleases::entry('v0.15.0'), GitHubReleases::entry('v0.14.0')],
            ['v0.15.0' => GitHubReleases::meta('7.4.0', self::otherKey()), 'v0.14.0' => GitHubReleases::meta('99.0.0', self::releaseKey())]
        );
        $locator = $this->locator($http, '0.13.0');

        self::assertLocateFails(self::stranded(), $locator);
        self::assertSame([self::keyNote('0.15.0'), 'lockrot 0.14.0 needs PHP 99.0.0 or newer, and this is PHP '.self::PHP], $locator->notes());
    }

    /** Held back by the PHP floor alone: nothing this PHP can install, which is not an error. */
    public function testNewerReleasesHeldBackOnlyByThePhpFloorAreNotAnError(): void
    {
        $http = self::http(
            [GitHubReleases::entry('v0.15.0'), GitHubReleases::entry('v0.14.0')],
            ['v0.15.0' => GitHubReleases::meta('8.2.0', self::releaseKey()), 'v0.14.0' => GitHubReleases::meta('8.1.0', self::releaseKey())]
        );
        $locator = $this->locator($http, '0.13.0', '7.4.33');

        self::assertNull($locator->locate());
        self::assertSame(['lockrot 0.15.0 needs PHP 8.2.0 or newer, and this is PHP 7.4.33'], $locator->notes());
    }

    /** --allow-major into a line signed only with another key is stranded too. */
    public function testAllowMajorIntoALineOnlyUnderAnotherKeyIsStranded(): void
    {
        $http = self::http([GitHubReleases::entry('v1.0.0'), GitHubReleases::entry('v0.13.0')], ['v1.0.0' => GitHubReleases::meta('7.4.0', self::otherKey())]);
        $locator = $this->locator($http, '0.13.0');

        self::assertLocateFails(self::stranded(), $locator, true);
        self::assertSame([self::keyNote('1.0.0')], $locator->notes());
    }

    /**
     * Without --allow-major the next major is advice, not the target: one signed with another key
     * says so instead of advising a flag that would install nothing, and strands nothing.
     */
    public function testANextMajorUnderAnotherKeyIsNamedForItsKeyNotAdvised(): void
    {
        $http = self::http([GitHubReleases::entry('v1.0.0'), GitHubReleases::entry('v0.13.0')], ['v1.0.0' => GitHubReleases::meta('7.4.0', self::otherKey())]);
        $locator = $this->locator($http, '0.13.0');

        self::assertNull($locator->locate());
        self::assertSame([self::keyNote('1.0.0')], $locator->notes());
    }

    /**
     * The advice names the release --allow-major would install: a newer 1.x this PHP cannot run is
     * named for its floor, and the newest one it can run is the one advised.
     */
    public function testTheAdviceNamesTheNextMajorReleaseAllowMajorWouldInstall(): void
    {
        $http = self::http(
            [GitHubReleases::entry('v1.1.0'), GitHubReleases::entry('v1.0.0'), GitHubReleases::entry('v0.13.0')],
            ['v1.1.0' => GitHubReleases::meta('8.1.0', self::releaseKey()), 'v1.0.0' => GitHubReleases::meta('7.4.0', self::releaseKey())]
        );
        $locator = $this->locator($http, '0.13.0', '7.4.33');

        self::assertNull($locator->locate());
        self::assertSame([
            'lockrot 1.1.0 needs PHP 8.1.0 or newer, and this is PHP 7.4.33',
            'lockrot 1.0.0 is in the next major version; run lockrot.phar self-update --allow-major to move to it',
        ], $locator->notes());
        self::assertChose('1.0.0', $this->locator($http, '0.13.0', '7.4.33')->locate(true));
    }

    /** A next major this PHP cannot run at all gets the floor, never the advice. */
    public function testANextMajorThisPhpCannotRunIsNotAdvised(): void
    {
        $http = self::http([GitHubReleases::entry('v1.0.0'), GitHubReleases::entry('v0.13.0')], ['v1.0.0' => GitHubReleases::meta('8.1.0', self::releaseKey())]);
        $locator = $this->locator($http, '0.13.0', '7.4.33');

        self::assertNull($locator->locate());
        self::assertSame(['lockrot 1.0.0 needs PHP 8.1.0 or newer, and this is PHP 7.4.33'], $locator->notes());
    }

    /** @return iterable<string, array{0: list<array<string, mixed>>}> */
    public static function majorsWithoutAStableRelease(): iterable
    {
        yield 'a major number that was skipped' => [[GitHubReleases::entry('v2.0.0'), GitHubReleases::entry('v0.13.0')]];
        yield 'a major withdrawn to a draft' => [[GitHubReleases::entry('v2.0.0'), GitHubReleases::entry('v1.0.0', GitHubReleases::ASSETS, true), GitHubReleases::entry('v0.13.0')]];
        yield 'a major with only a pre-release' => [[GitHubReleases::entry('v2.0.0'), GitHubReleases::entry('v1.0.0', GitHubReleases::ASSETS, false, true), GitHubReleases::entry('v0.13.0')]];
    }

    /**
     * The next major is the next one that has a stable release: a major with none is no dead end
     * that no flag gets past.
     *
     * @dataProvider majorsWithoutAStableRelease
     *
     * @param list<array<string, mixed>> $entries
     */
    #[DataProvider('majorsWithoutAStableRelease')]
    public function testTheNextMajorIsTheNextOneWithAStableRelease(array $entries): void
    {
        $http = self::http($entries, ['v2.0.0' => GitHubReleases::meta('7.4.0', self::releaseKey())]);
        $locator = $this->locator($http, '0.13.0');

        self::assertNull($locator->locate());
        self::assertSame(['lockrot 2.0.0 is in the next major version; run lockrot.phar self-update --allow-major to move to it'], $locator->notes());
        self::assertChose('2.0.0', $this->locator($http, '0.13.0')->locate(true));
    }

    public function testBeyondTheNextStableMajorIsStillOneStepAtATime(): void
    {
        $http = self::described(['v3.0.0', 'v2.0.0', 'v0.13.0']);
        $locator = $this->locator($http, '0.13.0');

        self::assertChose('2.0.0', $locator->locate(true));
        self::assertSame(['lockrot 3.0.0 is more than one major version ahead; --allow-major moves one major version at a time'], $locator->notes());
    }

    /**
     * A floor spelled other than `major.minor.patch` is compared with nothing: `v8.1.0` would sort
     * below every PHP and install an archive that refuses to start. The release is passed over
     * with a note that does not repeat the value.
     */
    public function testAReleaseWhoseFloorIsNotMajorMinorPatchIsNotInstalled(): void
    {
        $http = self::http(
            [GitHubReleases::entry('v0.15.0'), GitHubReleases::entry('v0.14.0')],
            ['v0.15.0' => GitHubReleases::meta('v8.1.0', self::releaseKey()), 'v0.14.0' => GitHubReleases::meta('7.4.0', self::releaseKey())]
        );
        $locator = $this->locator($http, '0.13.0', '7.4.33');

        self::assertChose('0.14.0', $locator->locate());
        self::assertSame(['lockrot 0.15.0 does not give its lowest PHP as major.minor.patch in its lockrot.phar.meta.json, so it is not installed'], $locator->notes());
    }

    /**
     * Every decision is on the normalised version: a tag with a capital `V` is the version it
     * names, in its major line, and described like any other.
     */
    public function testACapitalVTagIsTheVersionItNames(): void
    {
        $http = self::http([GitHubReleases::entry('V1.0.0'), GitHubReleases::entry('v0.13.0')], [
            'V1.0.0' => GitHubReleases::meta('99.0.0', self::releaseKey()),
            'v0.13.0' => GitHubReleases::meta('7.4.0', self::releaseKey()),
        ]);
        $locator = $this->locator($http, '0.12.0');

        self::assertChose('0.13.0', $locator->locate());
        self::assertSame(['lockrot 1.0.0 needs PHP 99.0.0 or newer, and this is PHP '.self::PHP], $locator->notes());
    }

    /** --force from 0.12.0 does not leave its line for a `V1.0.0`. */
    public function testForceWithACapitalVTagStaysInTheLine(): void
    {
        $http = self::http([GitHubReleases::entry('V1.0.0'), GitHubReleases::entry('v0.12.0', [ReleaseLocator::PHAR_ASSET, ReleaseLocator::CHECKSUM_ASSET, ReleaseLocator::SIGNATURE_ASSET])], [
            'V1.0.0' => GitHubReleases::meta('7.4.0', self::releaseKey()),
        ]);

        self::assertChose('0.12.0', $this->locator($http, '0.12.0')->locate(false, true));
    }

    /** @return iterable<string, array{0: string}> */
    public static function otherSpellingsOfADescribedRelease(): iterable
    {
        yield 'two numbers' => ['v0.13'];
        yield 'build metadata' => ['v0.13.0+build.1'];
        yield 'a stability flag' => ['v0.13.0-stable'];
    }

    /**
     * 0.13.0 in any spelling is a described release: its description is read and its floor holds
     * it back, and it is shown as `major.minor.patch`.
     *
     * @dataProvider otherSpellingsOfADescribedRelease
     */
    #[DataProvider('otherSpellingsOfADescribedRelease')]
    public function testEverySpellingOfADescribedReleaseIsDescribed(string $tag): void
    {
        $http = self::http([GitHubReleases::entry($tag)], [$tag => GitHubReleases::meta('99.0.0', self::releaseKey())]);
        $locator = $this->locator($http, '0.12.0');

        self::assertNull($locator->locate());
        self::assertSame(['lockrot 0.13.0 needs PHP 99.0.0 or newer, and this is PHP '.self::PHP], $locator->notes());
    }

    public function testAFourNumberTagOfTheRunningVersionIsNotAnUpdate(): void
    {
        $http = self::described(['v0.13.0.0']);

        self::assertNull($this->locator($http, '0.13.0')->locate());
        self::assertSame([self::URL], $http->requested());
    }

    /**
     * Two releases of one version — `v0.13.0` with every asset, a stray `0.13.0` with none — pick
     * the same one in either list order, on every PHP (usort() is not stable on 7.4).
     */
    public function testTwoTagsOfOneVersionAlwaysResolveToTheSameRelease(): void
    {
        $full = GitHubReleases::entry('v0.13.0');
        $bare = GitHubReleases::entry('0.13.0', []);
        foreach ([[$full, $bare], [$bare, $full]] as $entries) {
            $http = self::http($entries, ['v0.13.0' => GitHubReleases::meta('7.4.0', self::releaseKey())]);

            $release = $this->locator($http, '0.12.0')->locate();

            self::assertNotNull($release);
            self::assertSame('v0.13.0', $release->tag());
        }
    }

    /**
     * A published release whose tag is not a version is skipped — but not in silence, when it is
     * newer than the running version by list order: a signed release nobody can install would
     * otherwise look like "up to date" forever.
     */
    public function testAPublishedReleaseWithATagThatIsNotAVersionIsNamed(): void
    {
        $http = self::http([GitHubReleases::entry('v0.13.1-hotfix'), GitHubReleases::entry('v0.13.0')]);
        $locator = $this->locator($http, '0.13.0');

        self::assertNull($locator->locate());
        self::assertSame(['release tag "v0.13.1-hotfix" is not a version lockrot can compare, so that release was skipped'], $locator->notes());
    }

    /** Each such tag is named, and none of them hides a note given for a reason. */
    public function testEveryTagThatIsNotAVersionIsNamedOnItsOwnLine(): void
    {
        $http = self::http(
            [GitHubReleases::entry('php'), GitHubReleases::entry('v0.13.2-hotfix'), GitHubReleases::entry('v0.14.0'), GitHubReleases::entry('v0.13.0')],
            ['v0.14.0' => GitHubReleases::meta('99.0.0', self::releaseKey())]
        );
        $locator = $this->locator($http, '0.13.0');

        self::assertNull($locator->locate());
        self::assertSame([
            'release tag "php" is not a version lockrot can compare, so that release was skipped',
            'release tag "v0.13.2-hotfix" is not a version lockrot can compare, so that release was skipped',
            'lockrot 0.14.0 needs PHP 99.0.0 or newer, and this is PHP '.self::PHP,
        ], $locator->notes());
    }

    /** Listed after a release at or below the running one, it is history, not news. */
    public function testATagThatIsNotAVersionBelowTheRunningVersionIsNotNamed(): void
    {
        $http = self::http([GitHubReleases::entry('v0.13.0'), GitHubReleases::entry('v0.12.1-hotfix'), GitHubReleases::entry('v0.12.0')]);
        $locator = $this->locator($http, '0.13.0');

        self::assertNull($locator->locate());
        self::assertSame([], $locator->notes());
    }

    /** A draft or a pre-release under such a tag is not published, so it is not named either. */
    public function testAnUnpublishedTagThatIsNotAVersionIsNotNamed(): void
    {
        $http = self::http([
            GitHubReleases::entry('nightly', GitHubReleases::ASSETS, false, true),
            GitHubReleases::entry('wip', GitHubReleases::ASSETS, true),
            GitHubReleases::entry('v0.13.0'),
        ]);
        $locator = $this->locator($http, '0.13.0');

        self::assertNull($locator->locate());
        self::assertSame([], $locator->notes());
    }

    /**
     * A failure further down the list keeps the notes decided before it: the reason the newer
     * release was passed over — here the flag that would route around the broken one — is still
     * there to print ahead of the error.
     */
    public function testNotesSurviveAFailureFurtherDownTheList(): void
    {
        $assets = array_values(array_diff(GitHubReleases::ASSETS, [ReleaseLocator::PHAR_ASSET]));
        $http = self::http(
            [GitHubReleases::entry('v1.0.0'), GitHubReleases::entry('v0.14.0', $assets)],
            ['v1.0.0' => GitHubReleases::meta('7.4.0', self::releaseKey()), 'v0.14.0' => GitHubReleases::meta('7.4.0', self::releaseKey())]
        );
        $locator = $this->locator($http, '0.12.0');

        self::assertLocateFails('release v0.14.0 has no lockrot.phar asset', $locator);
        self::assertSame(['lockrot 1.0.0 is in the next major version; run lockrot.phar self-update --allow-major to move to it'], $locator->notes());
    }

    /** Each locate() starts from no notes. */
    public function testNotesAreThoseOfTheLastLocate(): void
    {
        $http = self::described(['v1.0.0', 'v0.13.0']);
        $locator = $this->locator($http, '0.13.0');

        $locator->locate();
        self::assertCount(1, $locator->notes());
        $locator->locate(true);
        self::assertSame([], $locator->notes());
    }

    public function testTheRunningMajorIsTheFirstNumberOfTheVersion(): void
    {
        self::assertSame(0, ReleaseLocator::majorOf('0.13.0'));
        self::assertSame(1, ReleaseLocator::majorOf('1.0.0'));
        self::assertSame(12, ReleaseLocator::majorOf('12.3.4'));
    }
}
