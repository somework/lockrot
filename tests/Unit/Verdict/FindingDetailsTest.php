<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryIgnoreMatch;
use Lockrot\Data\Advisory\AdvisoryNameCoverage;
use Lockrot\Data\Advisory\IgnoredAdvisory;
use Lockrot\Data\Repository\StableRelease;
use Lockrot\Lock\LockFile;
use Lockrot\Security\BranchFixes;
use Lockrot\Security\Candidate;
use Lockrot\Security\Fix;
use Lockrot\Security\Gets;
use Lockrot\Security\Holder;
use Lockrot\Security\PackageFixes;
use Lockrot\Security\PhpCheck;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Rule\AdvisoryRule;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FindingDetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FindingDetailsTest extends TestCase
{
    /** @return iterable<string, array{list<array{composer_repository: string, answer: string, reason: ?string, message: ?string, records: ?int}>, ?int, ?string, string}> */
    public static function lookups(): iterable
    {
        $answered = ['composer_repository' => 'packagist.org', 'answer' => AdvisoryCoverage::ANSWERED, 'reason' => null, 'message' => null, 'records' => 0];
        $failed = ['composer_repository' => 'mirror.example.test', 'answer' => AdvisoryCoverage::FAILED, 'reason' => AdvisoryCoverage::TRANSPORT, 'message' => 'HTTP 503', 'records' => null];
        yield 'every feed answered' => [[$answered], 0, null, 'complete'];
        yield 'one feed answered, one threw' => [[$answered, $failed], 0, AdvisoryCoverage::LOOKUP_FAILED, 'partial'];
        yield 'no feed answered' => [[$failed], null, AdvisoryCoverage::LOOKUP_FAILED, 'not_run'];
    }

    /**
     * `security.check` says how far the advisory lookup went for the package, whatever it counted.
     *
     * @param list<array{composer_repository: string, answer: string, reason: ?string, message: ?string, records: ?int}> $feeds
     *
     * @dataProvider lookups
     */
    #[DataProvider('lookups')]
    public function testTheCheckReadsTheFeedsAndNotTheCountedAdvisories(array $feeds, ?int $records, ?string $reason, string $check): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/a', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $package = $lock->find('vendor/a');
        self::assertNotNull($package);
        $facts = new PackageFacts($package, null, null, [], null, null, new AdvisoryNameCoverage($feeds, $records, $reason));

        $security = FindingDetails::of($facts, null, new PhpFloor('8.4', null), [], [], null)->security([], null);

        self::assertSame($check, $security['check']);
    }

    /**
     * Unread release data gives no fix (SPEC §5.3): the installed branch still has a row, with no
     * advisory fixed on it and each counted advisory not known.
     */
    public function testUnreadReleasesGiveTheInstalledBranchARowWithNoFixKnown(): void
    {
        $rows = [['id' => 'PKSA-a', 'severity' => 'high', 'counted' => true, 'fix' => ['kind' => 'unknown', 'on_installed_branch' => null, 'reason' => 'releases_unknown']]];
        $details = new FindingDetails('unavailable', null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'complete', null, [], new PackageFixes(['PKSA-a' => Fix::unknown(Fix::RELEASES_UNKNOWN)], [], null, null, null));

        $security = $details->security($rows, '1.x');

        self::assertSame(
            ['fixed' => 0, 'unknown' => 1, 'of' => 1, 'fix_kind' => null, 'lowest' => null, 'newest' => null, 'held_by' => [], 'if_applied' => null],
            $security['installed_branch_fixes']
        );
        self::assertStringContainsString(' — fix not known on 1.x;', $details->vulnerableSummary($rows, '1.x', 'high'));
        self::assertNull($details->security($rows, null)['installed_branch_fixes'], 'a branch snapshot has no installed branch');
    }

    /** Case `A-php-star‡`: the project's `require.php` is `*`, which admits the installed release. */
    public function testAStarRequirePhpAllowsTheInstalledRelease(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'twig/twig', 'version' => 'v1.44.8', 'require' => ['php' => '>=7.1.3'], 'notification-url' => 'https://packagist.org/downloads/']]]);
        $package = $lock->find('twig/twig');
        self::assertNotNull($package);
        $facts = new PackageFacts($package, null, null, [], null, null, null);

        $installed = FindingDetails::of($facts, null, new PhpFloor('8.4', '*'), [], [], null)->installedPhp();

        self::assertSame(['requires' => '>=7.1.3', 'target_runs' => true, 'project_allows' => true], $installed);
    }

    /** A package the advisory lookup leaves out by scope or version skips the check, with the reason. */
    public function testAnAdvisoryLookupLeftOutIsASkippedCheck(): void
    {
        $lock = LockFile::fromArray(['packages' => [['name' => 'vendor/a', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $package = $lock->find('vendor/a');
        self::assertNotNull($package);
        $facts = new PackageFacts($package, null, null, [], null, null, new AdvisoryNameCoverage([], null, 'unparseable_version'));

        $skipped = FindingDetails::of($facts, null, new PhpFloor('8.4', null), [], [], null)->skipped();

        self::assertContains(['check' => 'advisories', 'reason' => 'unparseable_version', 'blocks' => ['S9']], $skipped);
    }

    public function testASnapshotsVulnerableSummaryHasNoBranchClause(): void
    {
        $rows = [['id' => 'PKSA-a', 'cve' => 'CVE-2026-1', 'title' => 'a title', 'severity' => 'high', 'counted' => true, 'deciding' => true, 'fix' => ['kind' => 'unknown', 'on_installed_branch' => null]]];
        $details = new FindingDetails('read', null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'complete', null, [], null);

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

        $security = FindingDetails::of(new PackageFacts($package, null, null, []), null, new PhpFloor('8.4', null), [$ignored], [], null)->security([], null);

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
        $branch = new BranchFixes('1.x', true, 1, 0, 2, $candidate, '1.3.0', ['PKSA-a']);
        $gets = new Gets('1.3.0', PhpCheck::of('>=8.1', new PhpFloor('8.4', '>=8.1')), ['PKSA-a'], false);
        $details = new FindingDetails('read', null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'complete', null, [], new PackageFixes([], [$branch], $gets, null, null));
        $rows = [
            ['id' => 'PKSA-a', 'severity' => 'medium', 'fix' => ['kind' => 'upgrade', 'on_installed_branch' => true]],
            ['id' => 'PKSA-b', 'severity' => 'low', 'fix' => ['kind' => 'update', 'on_installed_branch' => true]],
        ];

        $security = $details->security($rows, '1.x');

        self::assertSame('medium', $security['worst']);
        self::assertSame(['critical' => 0, 'high' => 0, 'medium' => 1, 'unrated' => 0, 'low' => 1], $security['counts']);
        self::assertSame('upgrade', $security['fix_kind']);
        self::assertSame(['fixed' => 1, 'unknown' => 0, 'of' => 2, 'fix_kind' => 'upgrade', 'lowest' => '1.2.0', 'newest' => '1.3.0', 'held_by' => [AdvisoryRule::heldBy($holder)], 'if_applied' => null], $security['installed_branch_fixes']);
        self::assertSame([
            'version' => '1.3.0',
            'php_check' => ['requires' => '>=8.1', 'project_allows' => true, 'target_runs' => true, 'raise_to' => null, 'raise_size' => null],
            'clears' => [['kind' => 'advisory', 'id' => 'PKSA-a', 'basis' => 'outside_range']],
            'clears_all' => false,
        ], $security['gets']);
    }

    /** Without the scan, the S9 rows give the branch row: each row on the branch is fixed, each without a kind is not known. */
    public function testTheS9RowsCountTheFixedAndTheUnknown(): void
    {
        $details = new FindingDetails('read', null, null, [], ['requires' => null, 'target_runs' => null, 'project_allows' => null], null, 'complete', null, [], null);
        $rows = [
            ['id' => 'PKSA-a', 'severity' => 'acme:severe', 'fix' => ['kind' => 'update', 'on_installed_branch' => true]],
            ['id' => 'PKSA-b', 'severity' => 'low', 'fix' => ['kind' => 'update', 'on_installed_branch' => true]],
            ['id' => 'PKSA-c', 'severity' => 'low', 'fix' => ['kind' => 'unknown', 'on_installed_branch' => null]],
            ['id' => 'PKSA-d', 'severity' => 'low', 'fix' => []],
        ];

        $security = $details->security($rows, '1.x');

        self::assertSame(['fixed' => 2, 'unknown' => 2, 'of' => 4], \array_slice(JsonPath::arrayAt($security, ['installed_branch_fixes']), 0, 3));
        self::assertSame(1, JsonPath::intAt($security, ['counts', 'acme:severe']));
        self::assertSame(3, JsonPath::intAt($security, ['counts', 'low']));
        self::assertSame('low', $security['worst']);
        self::assertSame('unknown', $security['fix_kind'], 'a row with no kind reads as unknown');
    }
}
