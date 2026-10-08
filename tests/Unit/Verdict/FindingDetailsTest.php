<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\AdvisoryNameCoverage;
use Lockrot\Lock\LockFile;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Verdict\FindingDetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `security.check` says how far the advisory lookup went for the package, whatever it counted. */
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
}
