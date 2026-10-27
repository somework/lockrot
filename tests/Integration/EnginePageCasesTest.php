<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Composer;
use Lockrot\Tests\Support\Contract\EnginePage;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The engine page cases of tests/fixtures/contract/page/ are what bin/lockrot writes on the recorded
 * answers. Re-record them with tools/report/record --engine-pages.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class EnginePageCasesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function cases(): iterable
    {
        foreach (array_keys(EnginePage::CASES) as $id) {
            yield $id => [$id];
        }
    }

    /**
     * The recording holds the move check of the Composer that made it, and another Composer solves,
     * blocks and words it otherwise, so the exact file holds only on that Composer.
     *
     * @dataProvider cases
     */
    #[DataProvider('cases')]
    public function testARecordingEqualsTheCommittedCase(string $id): void
    {
        if (Composer::VERSION !== self::recordedComposer($id)) {
            self::markTestSkipped('this row runs a composer/composer other than the recording');
        }

        self::assertSame((string) file_get_contents(EnginePage::DIRECTORY.$id.'.json'), EnginePage::record($id), $id.': re-record with tools/report/record --engine-pages');
    }

    /**
     * A bump of the composer/composer that the PHAR bundles fails here and asks for a re-record. The
     * engine-pages job of ci.yml installs that version, so there the comparison cannot skip.
     *
     * @dataProvider cases
     */
    #[DataProvider('cases')]
    public function testTheRecordingRanOnTheComposerOfThePharLock(string $id): void
    {
        $lock = JsonPath::decodeFile(__DIR__.'/../../build/phar/composer.lock');
        $locked = [];
        foreach (array_merge(JsonPath::arrayAt($lock, ['packages']), JsonPath::arrayAt($lock, ['packages-dev'])) as $package) {
            if (\is_array($package) && ($package['name'] ?? null) === 'composer/composer') {
                $locked[] = $package['version'] ?? null;
            }
        }

        self::assertSame($locked, [self::recordedComposer($id)], $id.': re-record with tools/report/record --engine-pages');
    }

    private static function recordedComposer(string $id): string
    {
        return JsonPath::stringAt(JsonPath::decodeFile(EnginePage::DIRECTORY.$id.'.json'), ['run', 'move_check', 'composer']);
    }
}
