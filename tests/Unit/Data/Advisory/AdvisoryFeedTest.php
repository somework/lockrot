<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Advisory;

use Lockrot\Data\Advisory\AdvisoryFeed;
use Lockrot\Data\Advisory\AdvisoryRepository;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdvisoryFeedTest extends TestCase
{
    private const PACKAGIST = 'composer repo (https://repo.packagist.org)';

    public function testAnAnsweredFeedHasACountAndNoReason(): void
    {
        self::assertSame(
            ['composer_repository' => self::PACKAGIST, 'registry' => 'packagist.org', 'answer' => 'answered', 'records' => 3, 'reason' => null],
            JsonPath::decoded(AdvisoryFeed::answered(self::PACKAGIST, 3))
        );
    }

    public function testAFailedFeedHasAReasonAndNoCount(): void
    {
        $feed = AdvisoryFeed::failed('composer repo (https://mirror.example.test)', 'HTTP 503');

        self::assertSame(['composer_repository' => 'composer repo (https://mirror.example.test)', 'registry' => null, 'answer' => 'failed', 'records' => null, 'reason' => 'lookup_failed'], JsonPath::decoded($feed));
        self::assertSame('HTTP 503', $feed->message());
    }

    public function testAFeedNotAskedSaysWhy(): void
    {
        self::assertSame(['not_asked', null, 'not_from_composer_repository'], [AdvisoryFeed::notAsked(self::PACKAGIST, 'not_from_composer_repository')->answer(), AdvisoryFeed::notAsked(self::PACKAGIST, 'not_from_composer_repository')->records(), AdvisoryFeed::notAsked(self::PACKAGIST, 'not_from_composer_repository')->reason()]);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function registries(): iterable
    {
        yield 'packagist.org' => ['composer repo (https://repo.packagist.org)', 'packagist.org'];
        yield 'Private Packagist' => ['composer repo (https://repo.packagist.com/acme/)', 'repo.packagist.com'];
        yield 'Drupal' => ['composer repo (https://packages.drupal.org/8)', 'packages.drupal.org'];
        yield 'WP Packages' => ['composer repo (https://wp-packages.org)', 'wp-packages.org'];
        yield 'another repository' => ['composer repo (https://satis.example.test)', null];
        yield 'a name without a URL' => ['my-repo', null];
        yield 'a host in capitals' => ['composer repo (https://Repo.Packagist.ORG)', 'packagist.org'];
    }

    /** @dataProvider registries */
    #[DataProvider('registries')]
    public function testTheRegistryIsTheHostThatOriginRegistryWrites(string $name, ?string $registry): void
    {
        self::assertSame($registry, AdvisoryFeed::answered($name, 0)->registry());
        self::assertSame($registry, AdvisoryRepository::of($name, AdvisoryRepository::NO_FEED)->registry());
    }

    public function testARepositoryThatAnsweredCountsItsRecords(): void
    {
        $answered = AdvisoryRepository::of(self::PACKAGIST, AdvisoryRepository::ANSWERED)->withRecords(33, 4);

        self::assertSame(
            ['composer_repository' => self::PACKAGIST, 'registry' => 'packagist.org', 'outcome' => 'answered', 'reason' => null, 'message' => null, 'packages_with_records' => 4, 'records' => 33],
            JsonPath::decoded($answered)
        );
        $unanswered = AdvisoryRepository::of(self::PACKAGIST, AdvisoryRepository::ANSWERED);
        $unanswered->withRecords(3, 1);
        self::assertNull($unanswered->records(), 'withRecords() returns a copy');
        self::assertSame([null, null], [AdvisoryRepository::of(self::PACKAGIST, AdvisoryRepository::NO_FEED)->records(), AdvisoryRepository::of(self::PACKAGIST, AdvisoryRepository::NO_FEED)->packagesWithRecords()]);
    }

    public function testARepositoryNameKeepsNoCredentials(): void
    {
        $repository = AdvisoryRepository::of('composer repo (https://user:secret@repo.example.test/?token=abc)', AdvisoryRepository::FAILED, 'transport', 'HTTP 401');

        self::assertStringNotContainsString('secret', $repository->composerRepository());
        self::assertStringNotContainsString('abc', $repository->composerRepository());
    }
}
