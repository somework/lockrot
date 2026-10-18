<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryFeed;
use Lockrot\Data\Advisory\AdvisoryIgnoreMatch;
use Lockrot\Data\Advisory\AdvisoryNameCoverage;
use Lockrot\Data\Advisory\IgnoredAdvisory;
use Lockrot\Data\Forge\ActivityLookup;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Forge\RepositoryActivity;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\StableRelease;
use Lockrot\Lock\LockFile;
use Lockrot\Security\BranchFixes;
use Lockrot\Security\Candidate;
use Lockrot\Security\Fix;
use Lockrot\Security\Gets;
use Lockrot\Security\Holder;
use Lockrot\Security\PackageFixes;
use Lockrot\Security\PhpCheck;
use Lockrot\Signal\Data\AdvisoryRow;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\FindingDetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FindingDetailsTest extends TestCase
{
    /** @return iterable<string, array{list<AdvisoryFeed>, ?int, ?string, string}> */
    public static function lookups(): iterable
    {
        $answered = AdvisoryFeed::answered('composer repo (https://repo.packagist.org)', 0);
        $failed = AdvisoryFeed::failed('composer repo (https://mirror.example.test)', 'HTTP 503');
        yield 'every feed answered' => [[$answered], 0, null, 'complete'];
        yield 'one feed answered, one threw' => [[$answered, $failed], 0, AdvisoryCoverage::LOOKUP_FAILED, 'partial'];
        yield 'no feed answered' => [[$failed], null, AdvisoryCoverage::LOOKUP_FAILED, 'not_run'];
    }

    /**
     * `security.check` says how far the advisory lookup went for the package, whatever it counted.
     *
     * @param list<AdvisoryFeed> $feeds
     *
     * @dataProvider lookups
     */
    #[DataProvider('lookups')]
    public function testTheCheckReadsTheFeedsAndNotTheCountedAdvisories(array $feeds, ?int $records, ?string $reason, string $check): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/a', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $package = $lock->find('vendor/a');
        self::assertNotNull($package);
        $facts = F::looked(new PackageFacts($package, null, null, [], null, null, new AdvisoryNameCoverage($feeds, $records, $reason)));

        $security = FindingDetails::of($facts, null, new PhpFloor('8.4', null), [], [], null, FindingBuilder::unmeasuredAge())->toSecurityArray([], null);

        self::assertSame($check, $security['check']);
        self::assertSame($records, $security['records']);
        self::assertSame($feeds, $security['feeds'], 'the feeds as the lookup gave them');
    }

    public function testARunWithoutALookupWritesNoFeedAndNoRecords(): void
    {
        $security = FindingDetails::of(F::looked(new PackageFacts(F::package(), null, null)), null, new PhpFloor('8.4', null), [], [], null, FindingBuilder::unmeasuredAge())->toSecurityArray([], null);

        self::assertSame([[], null], [$security['feeds'], $security['records']]);
    }

    /**
     * Unread release data gives no fix (SPEC §5.3): the installed branch still has a row, with no
     * advisory fixed on it and each counted advisory not known.
     */
    public function testUnreadReleasesGiveTheInstalledBranchARowWithNoFixKnown(): void
    {
        $rows = self::rows([['id' => 'PKSA-a', 'severity' => 'high', 'counted' => true, 'fix' => ['kind' => 'unknown', 'on_installed_branch' => null, 'reason' => 'releases_unknown']]]);
        $details = new FindingDetails('unavailable', null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'complete', null, [], new PackageFixes(['PKSA-a' => Fix::unknown(Fix::RELEASES_UNKNOWN)], [], null, null, null), FindingBuilder::unread(), FindingBuilder::unmeasuredAge(), null, null);

        $security = $details->toSecurityArray($rows, '1.x');

        self::assertSame(
            ['fixed' => 0, 'unknown' => 1, 'of' => 1, 'fix_kind' => null, 'lowest' => null, 'newest' => null, 'held_by' => [], 'if_applied' => null, 'check' => null],
            JsonPath::decoded($security['installed_branch_fixes'])
        );
        self::assertStringContainsString(' — fix not known on 1.x;', $details->vulnerableSummary([$rows[0]->withDeciding(true)], '1.x', 'high'));
        self::assertNull($details->toSecurityArray($rows, null)['installed_branch_fixes'], 'a branch snapshot has no installed branch');
    }

    /** Case `A-php-star‡`: the project's `require.php` is `*`, which admits the installed release. */
    public function testAStarRequirePhpAllowsTheInstalledRelease(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'twig/twig', 'version' => 'v1.44.8', 'require' => ['php' => '>=7.1.3'], 'notification-url' => 'https://packagist.org/downloads/']]]);
        $package = $lock->find('twig/twig');
        self::assertNotNull($package);
        $facts = F::looked(new PackageFacts($package, null, null, [], null, null, null));

        $installed = FindingDetails::of($facts, null, new PhpFloor('8.4', '*'), [], [], null, FindingBuilder::unmeasuredAge())->installedPhp();

        self::assertSame(['requires' => '>=7.1.3', 'target_runs' => true, 'project_allows' => true], $installed);
    }

    /** A package the advisory lookup leaves out by scope or version skips the check, with the reason. */
    public function testAnAdvisoryLookupLeftOutIsASkippedCheck(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/a', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $package = $lock->find('vendor/a');
        self::assertNotNull($package);
        $facts = F::looked(new PackageFacts($package, null, null, [], null, null, new AdvisoryNameCoverage([], null, 'unparseable_version')));

        $skipped = FindingDetails::of($facts, null, new PhpFloor('8.4', null), [], [], null, FindingBuilder::unmeasuredAge())->skipped();

        self::assertContains(['check' => 'advisories', 'reason' => 'unparseable_version', 'blocks' => ['S9']], JsonPath::decoded($skipped));
    }

    public function testASnapshotsVulnerableSummaryHasNoBranchClause(): void
    {
        $rows = self::rows([['id' => 'PKSA-a', 'cve' => 'CVE-2026-1', 'title' => 'a title', 'severity' => 'high', 'counted' => true, 'deciding' => true, 'fix' => ['kind' => 'unknown', 'on_installed_branch' => null]]]);
        $details = new FindingDetails('read', null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'complete', null, [], null, FindingBuilder::unread(), FindingBuilder::unmeasuredAge(), null, null);

        self::assertSame('1 advisory: 1 high; advisory: CVE-2026-1 a title', $details->vulnerableSummary($rows, null, 'high'));
    }

    /** An advisory that Composer's audit ignore list keeps out of S9 is listed with its cleaned title. */
    public function testAnIgnoredAdvisoryIsListedWithItsMatch(): void
    {
        $advisory = new Advisory('PKSA-i', 'CVE-2024-9', 'CVE-2024-9: an  ignored title', null, 'moderate', null);
        $ignored = new IgnoredAdvisory($advisory, new AdvisoryIgnoreMatch(AdvisoryIgnoreMatch::CVE, 'CVE-2024-9', 'not used here', AdvisoryIgnoreMatch::BY_AUDIT));
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/a', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $package = $lock->find('vendor/a');
        self::assertNotNull($package);

        $security = FindingDetails::of(F::looked(new PackageFacts($package, null, null, [])), null, new PhpFloor('8.4', null), [$ignored], [], null, FindingBuilder::unmeasuredAge())->toSecurityArray([], null);

        self::assertSame(1, $security['ignored_count']);
        self::assertSame(
            [['id' => 'PKSA-i', 'cve' => 'CVE-2024-9', 'title' => 'an ignored title', 'severity' => 'medium', 'by' => AdvisoryIgnoreMatch::BY_AUDIT, 'matched' => AdvisoryIgnoreMatch::CVE, 'reason' => 'not used here']],
            $security['ignored']
        );
    }

    /** A scanned package: the installed branch row, what `composer update` gets, and the hardest per-advisory kind. */
    public function testAScannedPackageWritesItsBranchRowAndWhatAnUpdateGets(): void
    {
        $holder = new Holder(Holder::PACKAGE, 'acme/b', '2.0.0', Holder::REQUIRE, '^1.0');
        $candidate = new Candidate(new StableRelease('1.2.0.0', '1.2.0', null, '>=8.1', false), '1', Fix::UPGRADE, [$holder]);
        $branch = new BranchFixes('1.x', true, 0, 2, $candidate, '1.3.0', ['PKSA-a']);
        $gets = new Gets('1.3.0', PhpCheck::of('>=8.1', new PhpFloor('8.4', '>=8.1')), ['PKSA-a'], false);
        $details = new FindingDetails('read', null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'complete', null, [], new PackageFixes([], [$branch], $gets, null, null), FindingBuilder::unread(), FindingBuilder::unmeasuredAge(), null, null);
        $rows = self::rows([
            ['id' => 'PKSA-a', 'severity' => 'medium', 'fix' => ['kind' => 'upgrade', 'on_installed_branch' => true]],
            ['id' => 'PKSA-b', 'severity' => 'low', 'fix' => ['kind' => 'update', 'on_installed_branch' => true]],
        ]);

        $security = $details->toSecurityArray($rows, '1.x');

        self::assertSame('medium', $security['worst']);
        self::assertSame(['critical' => 0, 'high' => 0, 'medium' => 1, 'unrated' => 0, 'low' => 1], $security['counts']);
        self::assertSame('upgrade', $security['fix_kind']);
        self::assertSame(['fixed' => 1, 'unknown' => 0, 'of' => 2, 'fix_kind' => 'upgrade', 'lowest' => '1.2.0', 'newest' => '1.3.0', 'held_by' => [JsonPath::decoded($holder)], 'if_applied' => null, 'check' => null], JsonPath::decoded($security['installed_branch_fixes']));
        self::assertSame([
            'version' => '1.3.0',
            'php_check' => ['requires' => '>=8.1', 'project_allows' => true, 'target_runs' => true, 'raise_to' => null, 'raise_size' => null],
            'clears' => [['kind' => 'advisory', 'id' => 'PKSA-a', 'basis' => 'outside_range']],
            'clears_all' => false,
        ], JsonPath::decoded($security['gets']));
    }

    /** Without the scan, the S9 rows give the branch row: each row on the branch is fixed, each without a kind is not known. */
    public function testTheS9RowsCountTheFixedAndTheUnknown(): void
    {
        $details = new FindingDetails('read', null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'complete', null, [], null, FindingBuilder::unread(), FindingBuilder::unmeasuredAge(), null, null);
        $rows = self::rows([
            ['id' => 'PKSA-a', 'severity' => 'acme:severe', 'fix' => ['kind' => 'update', 'on_installed_branch' => true]],
            ['id' => 'PKSA-b', 'severity' => 'low', 'fix' => ['kind' => 'update', 'on_installed_branch' => true]],
            ['id' => 'PKSA-c', 'severity' => 'low', 'fix' => ['kind' => 'unknown', 'on_installed_branch' => null]],
            ['id' => 'PKSA-d', 'severity' => 'low', 'fix' => []],
        ]);

        $security = $details->toSecurityArray($rows, '1.x');

        self::assertSame(['fixed' => 2, 'unknown' => 2, 'of' => 4], \array_slice(JsonPath::arrayAt(JsonPath::decoded($security), ['installed_branch_fixes']), 0, 3));
        self::assertSame(1, JsonPath::intAt($security, ['counts', 'acme:severe']));
        self::assertSame(3, JsonPath::intAt($security, ['counts', 'low']));
        self::assertSame('low', $security['worst']);
        self::assertSame('unknown', $security['fix_kind'], 'a row with no kind reads as unknown');
    }

    /** The standing that report-2's root counts: the status, the counts and the hardest fix kind of the counted rows. */
    public function testTheStandingOfCountedRowsIsVulnerableWithTheHardestKind(): void
    {
        $ignored = new IgnoredAdvisory(new Advisory('PKSA-i', null, null, null, 'low', null), new AdvisoryIgnoreMatch(AdvisoryIgnoreMatch::ID, 'PKSA-i', null, AdvisoryIgnoreMatch::BY_AUDIT));
        $details = new FindingDetails('read', null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'partial', 'lookup_failed', [$ignored], null, FindingBuilder::unread(), FindingBuilder::unmeasuredAge(), null, null);
        $rows = self::rows([
            ['id' => 'PKSA-a', 'severity' => 'acme:severe', 'fix' => ['kind' => 'update']],
            ['id' => 'PKSA-b', 'severity' => 'low', 'fix' => ['kind' => 'raise-php']],
            ['id' => 'PKSA-c', 'severity' => 'low', 'fix' => ['kind' => 'upgrade']],
        ]);

        $standing = $details->standing($rows);

        self::assertSame('vulnerable', $standing->status());
        self::assertSame('partial', $standing->check());
        self::assertSame(1, $standing->ignoredCount());
        self::assertSame(['critical' => 0, 'high' => 0, 'medium' => 0, 'unrated' => 0, 'low' => 2, 'acme:severe' => 1], $standing->counts());
        self::assertSame('raise-php', $standing->fixKind());
        self::assertSame(1, $details->toSecurityArray($rows, null)['ignored_count'], 'a vulnerable package counts its ignored advisories too');
    }

    /** With no counted row, the lookup decides the status, and nothing is counted or fixed. */
    public function testTheStandingWithNoRowFollowsTheLookup(): void
    {
        $details = static fn (string $check): FindingDetails => new FindingDetails('read', null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, $check, null, [], null, FindingBuilder::unread(), FindingBuilder::unmeasuredAge(), null, null);

        self::assertSame('clear', $details('complete')->standing([])->status());
        self::assertSame('unchecked', $details('partial')->standing([])->status());
        self::assertSame('unchecked', $details('not_run')->standing([])->status());
        self::assertSame('not_run', $details('not_run')->standing([])->check());
        self::assertSame(0, $details('complete')->standing([])->ignoredCount());
        self::assertNull($details('complete')->standing([])->fixKind());
        self::assertSame([], array_filter($details('complete')->standing([])->counts()));
    }

    /**
     * `metadata.reason` and `metadata.message` say why an unavailable answer failed, and are null
     * for every other status.
     *
     * @param array{status: string, reason: ?string, message: ?string} $metadata
     *
     * @dataProvider metadataAnswers
     */
    #[DataProvider('metadataAnswers')]
    public function testTheMetadataReasonExplainsAnUnavailableAnswerOnly(PackageFacts $facts, ?string $failure, array $metadata): void
    {
        self::assertSame($metadata, FindingDetails::of($facts, null, new PhpFloor('8.4', null), [], [], $failure, FindingBuilder::unmeasuredAge())->metadata());
    }

    /** @return iterable<string, array{PackageFacts, ?string, array{status: string, reason: ?string, message: ?string}}> */
    public static function metadataAnswers(): iterable
    {
        $offline = MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON;
        $unavailable = F::looked(new PackageFacts(F::package(), null, null, [], null, null, null, true));
        yield 'unavailable, offline' => [$unavailable, $offline, ['status' => 'unavailable', 'reason' => 'offline', 'message' => $offline]];
        yield 'unavailable, with no answer' => [$unavailable, null, ['status' => 'unavailable', 'reason' => null, 'message' => null]];
        yield 'read' => [F::looked(F::facts(F::package(), F::metadata([['1.0.0', '2026-01-01T00:00:00+00:00']]))), $offline, ['status' => 'read', 'reason' => null, 'message' => null]];
    }

    /**
     * A check that did not run and raised no S10 is a skipped check, with its reason and the
     * signals it blocks.
     *
     * @param list<Signal>                                                $signals
     * @param list<array{check: string, reason: string, blocks: list<string>}> $skipped
     *
     * @dataProvider skips
     */
    #[DataProvider('skips')]
    public function testEachCheckThatDidNotRunIsSkippedWithItsReason(PackageFacts $facts, ?AllowlistEntry $entry, array $signals, array $skipped): void
    {
        self::assertSame($skipped, JsonPath::decoded(FindingDetails::of($facts, $entry, new PhpFloor('8.4', null), [], $signals, null, FindingBuilder::unmeasuredAge())->skipped()));
    }

    /** @return iterable<string, array{PackageFacts, ?AllowlistEntry, list<Signal>, list<array{check: string, reason: string, blocks: list<string>}>}> */
    public static function skips(): iterable
    {
        $metadata = F::metadata([['1.0.0', '2026-01-01T00:00:00+00:00']]);
        $read = F::looked(F::facts(F::package(), $metadata));
        $asked = F::facts(F::package(), $metadata, F::activity(false, '2026-01-01T00:00:00+00:00'));
        $activity = static fn (string $reason): array => ['check' => 'repository_activity', 'reason' => $reason, 'blocks' => ['S3', 'S4']];
        $s10 = static fn ($unchecked): Signal => new Signal(Signal::LEVEL_INFO, 'not checked', SignalDataStub::of(Signal::S10, ['unchecked' => $unchecked]));
        $whole = new AllowlistEntry('vendor/pkg', null, 'kept on purpose', null, AllowlistEntry::BY_PROJECT);
        $silentOnly = new AllowlistEntry('vendor/pkg', null, 'kept on purpose', null, AllowlistEntry::BY_PROJECT, ['silent']);

        yield 'every check ran' => [$asked, null, [], []];
        yield 'no repository to ask' => [$read, null, [], [$activity('no_repository')]];
        yield 'a whole-package entry' => [F::looked(F::facts(F::package(), $metadata), ActivityLookup::ALLOWLISTED), $whole, [], [$activity('allowlisted')]];
        yield 'an entry that accepts some flags' => [$read, $silentOnly, [], [$activity('no_repository')]];
        yield 'the reason the lookup gives' => [F::looked(new PackageFacts(F::package(), $metadata, null, []), 'no_token'), null, [], [$activity('no_token')]];
        yield 'S10 holds the activity gap' => [$read, null, [new Signal(Signal::LEVEL_WARN, 'old', SignalDataStub::of(Signal::S2, [])), $s10([['check' => 'repository_activity', 'reason' => 'no_token', 'blocks' => ['S3', 'S4']]])], []];
        yield 'S10 holds another gap' => [$read, null, [$s10([['check' => 'releases', 'reason' => 'releases_unknown', 'blocks' => ['S9']]])], [$activity('no_repository')]];
        yield 'not from a Composer repository' => [F::looked(F::facts(F::package(['fromComposerRepository' => false])), ActivityLookup::NOT_FROM_COMPOSER_REPOSITORY), null, [], [
            $activity('not_from_composer_repository'),
            ['check' => 'release_metadata', 'reason' => 'not_from_composer_repository', 'blocks' => ['S2', 'S8']],
        ]];
        yield 'a branch snapshot' => [F::facts(F::package(['version' => 'dev-main']), $metadata, F::activity(false, null)), null, [], [['check' => 'release_branch', 'reason' => 'branch_snapshot', 'blocks' => ['S8']]]];
        $gitlab = new RepositoryActivity(new RepoRef(RepoRef::GITLAB, 'gitlab.com', 'group/pkg'), null, null, new \DateTimeImmutable(F::NOW));
        yield 'a read without the archived flag' => [(new PackageFacts(F::package(), $metadata, null, []))->withActivityLookup(ActivityLookup::read($gitlab)), null, [], [['check' => 'repository_archived', 'reason' => 'no_token', 'blocks' => ['S3']]]];
        yield 'a read whose request for the flag failed' => [(new PackageFacts(F::package(), $metadata, null, []))->withActivityLookup(ActivityLookup::read(new RepositoryActivity($gitlab->ref(), null, null, new \DateTimeImmutable(F::NOW), null, true))), null, [], [['check' => 'repository_archived', 'reason' => 'fetch_failed', 'blocks' => ['S3']]]];
        yield 'a 404' => [(new PackageFacts(F::package(), $metadata, null, []))->withActivityLookup(ActivityLookup::notFound(new RepoRef(RepoRef::GITHUB, 'github.com', 'vendor/pkg'))), null, [], [$activity('repository_not_found')]];
        yield 'S10 holds the origin gaps' => [F::looked(F::facts(F::package(['fromComposerRepository' => false])), ActivityLookup::NOT_FROM_COMPOSER_REPOSITORY), null, [$s10([
            ['check' => 'release_metadata', 'reason' => 'not_from_composer_repository', 'blocks' => ['S2', 'S8']],
            ['check' => 'repository_activity', 'reason' => 'not_from_composer_repository', 'blocks' => ['S3', 'S4']],
        ])], []];
    }

    /**
     * @param list<array<string, mixed>> $rows each with its `deciding` mark when it has one
     *
     * @return list<AdvisoryRow>
     */
    private static function rows(array $rows): array
    {
        return array_map(static fn (array $row): AdvisoryRow => SignalDataStub::row($row)->withDeciding(($row['deciding'] ?? false) === true), $rows);
    }
}
