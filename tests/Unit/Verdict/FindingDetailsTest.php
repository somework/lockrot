<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Data\Advisory\Advisory;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryIgnoreMatch;
use Lockrot\Data\Advisory\AdvisoryNameCoverage;
use Lockrot\Data\Advisory\IgnoredAdvisory;
use Lockrot\Lock\LockFile;
use Lockrot\Security\Fix;
use Lockrot\Security\PackageFixes;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
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
}
