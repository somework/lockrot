<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Downloader\TransportException;
use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Repository\ArrayRepository;
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
        $repository = new class () extends ComposerRepository {
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

    public function testTheFirstComposerRepositoryThatListsATaggedReleaseOfTheNameWins(): void
    {
        $versions = new RepositoryVersions([new ArrayRepository([self::package('acme/lib', '9.0.0')]), self::repository(['acme/other' => ['1.0.0']]), self::repository(['acme/lib' => ['1.0.0', 'dev-main']]), self::repository(['acme/lib' => ['2.0.0']])], [], false, Deadline::never());

        self::assertSame(['1.0.0'], self::pretty($versions->versions('acme/lib')));
        self::assertNull($versions->failure('acme/lib'));
    }

    public function testANameWithNoTaggedReleaseLoadsItsDevVersions(): void
    {
        $versions = new RepositoryVersions([self::repository(['acme/lib' => ['dev-main']])], [], false, Deadline::never());

        self::assertSame(['dev-main'], self::pretty($versions->versions('acme/lib')));
    }

    /** A repository that fails is skipped whole: its dev versions are not read in place of the tagged ones. */
    public function testARepositoryWhoseLoadFailsIsSkippedForTheNextOne(): void
    {
        $versions = new RepositoryVersions([self::repository(['acme/lib' => ['dev-main']], true), self::repository(['acme/lib' => ['1.0.0']])], [], false, Deadline::never());

        self::assertSame(['1.0.0'], self::pretty($versions->versions('acme/lib')));
    }

    public function testANameThatNoRepositoryListsHasNoFailureOnlineAndIsOfflineOffline(): void
    {
        $online = new RepositoryVersions([self::repository([])], [], false, Deadline::never());
        $offline = new RepositoryVersions([self::repository([])], [], true, Deadline::never());

        self::assertSame([[], null], [$online->versions('acme/lib'), $online->failure('acme/lib')]);
        self::assertSame([[], VersionSource::OFFLINE], [$offline->versions('acme/lib'), $offline->failure('acme/lib')]);
    }

    /**
     * A fake repository that serves each name's versions by stability, as a Composer repository does.
     *
     * @param array<string, list<string>> $versions name => its pretty versions
     */
    private static function repository(array $versions, bool $failsTagged = false): ComposerRepository
    {
        $packages = [];
        foreach ($versions as $name => $list) {
            foreach ($list as $version) {
                $packages[] = self::package($name, $version);
            }
        }

        return new class ($packages, $failsTagged) extends ComposerRepository {
            /** @var list<BasePackage> */
            private array $served;
            private bool $failsTagged;

            /** @param list<BasePackage> $served */
            public function __construct(array $served, bool $failsTagged)
            {
                $this->served = $served;
                $this->failsTagged = $failsTagged;
            }

            public function loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = []): array
            {
                if ($this->failsTagged && isset($acceptableStabilities['stable'])) {
                    throw new TransportException('The "https://repo.example.org/p2/acme/lib.json" file could not be downloaded');
                }
                $packages = array_values(array_filter($this->served, static fn (BasePackage $package): bool => \array_key_exists($package->getName(), $packageNameMap) && isset($acceptableStabilities[$package->getStability()])));

                return ['namesFound' => array_map(static fn (BasePackage $package): string => $package->getName(), $packages), 'packages' => $packages];
            }
        };
    }

    private static function package(string $name, string $version): BasePackage
    {
        $package = (new ArrayLoader())->load(['name' => $name, 'version' => $version]);
        self::assertInstanceOf(BasePackage::class, $package);

        return $package;
    }

    /**
     * @param list<BasePackage> $packages
     *
     * @return list<string>
     */
    private static function pretty(array $packages): array
    {
        return array_map(static fn (BasePackage $package): string => $package->getPrettyVersion(), $packages);
    }
}
