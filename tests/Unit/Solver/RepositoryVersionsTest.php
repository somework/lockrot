<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Repository\ComposerRepository;
use Lockrot\Deadline;
use Lockrot\Solver\RepositoryVersions;
use Lockrot\Solver\VersionSource;
use PHPUnit\Framework\TestCase;
use Seld\JsonLint\ParsingException;

final class RepositoryVersionsTest extends TestCase
{
    /** A repository answer that does not parse is a failed load of that name, never a stopped run. */
    public function testANameWhoseRepositoryAnswerDoesNotParseFailsToLoad(): void
    {
        $repository = new class() extends ComposerRepository {
            public function __construct()
            {
            }

            public function loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = []): array
            {
                throw new ParsingException('"https://repo.example.org/p2/acme/lib.json" does not contain valid JSON');
            }
        };
        $versions = new RepositoryVersions([$repository], [], false, Deadline::never());

        self::assertSame([], $versions->versions('acme/lib'));
        self::assertSame(VersionSource::FAILED, $versions->failure('acme/lib'));
    }
}
