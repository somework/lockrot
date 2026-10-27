<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Downloader\TransportException;
use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Repository\ArrayRepository;
use Composer\Repository\ComposerRepository;
use Composer\Repository\FilterRepository;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Deadline;
use Lockrot\Solver\RepositoryVersions;
use Lockrot\Solver\VersionSource;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\RecordingComposerRepository;
use PHPUnit\Framework\TestCase;
use Seld\JsonLint\ParsingException;

final class RepositoryVersionsTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeTree($dir);
        }
        $this->tempDirs = [];
    }

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

    /** A Composer repository can answer with other names too, such as a package that provides or replaces the name. */
    public function testOnlyTheVersionsOfTheAskedNameAreKept(): void
    {
        $repository = new class ([self::package('acme/other', '1.0.0'), self::package('acme/lib', '1.0.0'), self::package('acme/lib', '1.1.0')]) extends ComposerRepository {
            /** @var list<BasePackage> */
            private array $answer;
            public int $loads = 0;

            /** @param list<BasePackage> $answer */
            public function __construct(array $answer)
            {
                $this->answer = $answer;
            }

            public function loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = []): array
            {
                ++$this->loads;

                return ['namesFound' => ['acme/lib', 'acme/other'], 'packages' => $this->answer];
            }
        };
        $versions = new RepositoryVersions([$repository], [], false, Deadline::never());

        self::assertSame(['1.0.0', '1.1.0'], self::pretty($versions->versions('acme/lib')));
        self::assertSame([0, 1], array_keys($versions->versions('acme/lib')));
        self::assertSame(1, $repository->loads, 'a name loads once');
        self::assertSame(['1.0.0'], self::pretty($versions->versions('acme/other')));
        self::assertSame(2, $repository->loads, 'another name in an answer is not loaded with the asked name');
    }

    public function testANameWithNoTaggedReleaseLoadsItsDevVersions(): void
    {
        $versions = new RepositoryVersions([self::repository(['acme/lib' => ['dev-main']])], [], false, Deadline::never());

        self::assertSame(['dev-main'], self::pretty($versions->versions('acme/lib')));
    }

    public function testATaggedNameLoadsNoDevVersionsUnderARootThatAdmitsOnlyStable(): void
    {
        $repository = self::recording(['acme/lib' => ['1.0.0', 'dev-main']]);
        $versions = new RepositoryVersions([$repository], [], false, Deadline::never(), 'stable', []);

        self::assertSame(['1.0.0'], self::pretty($versions->versions('acme/lib')));
        self::assertSame([[['acme/lib'], 'stable']], $repository->asked);
    }

    /** Composer's repository loads the `~dev` file of each name that the root admits at `dev`. */
    public function testATaggedNameLoadsItsDevVersionsUnderARootWhoseMinimumStabilityIsDev(): void
    {
        $repository = self::recording(['acme/lib' => ['1.0.0', 'dev-main'], 'acme/branch' => ['dev-main']]);
        $versions = new RepositoryVersions([$repository], [], false, Deadline::never(), 'dev', []);

        $loaded = $versions->versionsOf(['acme/lib', 'acme/branch']);

        self::assertSame(['acme/lib' => ['1.0.0', 'dev-main'], 'acme/branch' => ['dev-main']], array_map(static fn (array $packages): array => self::pretty($packages), $loaded));
        self::assertSame([[['acme/lib', 'acme/branch'], 'stable'], [['acme/branch', 'acme/lib'], 'dev']], $repository->asked);
    }

    public function testAStabilityFlagAtDevAdmitsTheDevVersionsOfItsNameOnly(): void
    {
        $repository = self::recording(['acme/flagged' => ['1.0.0', 'dev-main'], 'acme/beta' => ['1.0.0', 'dev-main'], 'acme/plain' => ['1.0.0', 'dev-main']]);
        $versions = new RepositoryVersions([$repository], [], false, Deadline::never(), 'stable', ['acme/flagged' => BasePackage::STABILITY_DEV, 'acme/beta' => BasePackage::STABILITY_BETA]);

        $loaded = $versions->versionsOf(['acme/flagged', 'acme/beta', 'acme/plain']);

        self::assertSame(['acme/flagged' => ['1.0.0', 'dev-main'], 'acme/beta' => ['1.0.0'], 'acme/plain' => ['1.0.0']], array_map(static fn (array $packages): array => self::pretty($packages), $loaded));
    }

    /** The dev versions come from the repository that lists the tagged ones, as a canonical repository shadows the later ones. */
    public function testTheDevVersionsOfAnAdmittedNameComeFromTheRepositoryThatListsItsTaggedRelease(): void
    {
        $versions = new RepositoryVersions([self::recording(['acme/lib' => ['1.0.0', 'dev-main']]), self::recording(['acme/lib' => ['2.0.0', 'dev-other']])], [], false, Deadline::never(), 'dev', []);

        self::assertSame(['1.0.0', 'dev-main'], self::pretty($versions->versions('acme/lib')));
    }

    /** A failed dev request of several names loads each admitted name alone: its tags alone are a partial pool. */
    public function testAFailedDevRequestOfSeveralNamesLoadsEachAdmittedNameAlone(): void
    {
        $served = [self::package('acme/lib', '1.0.0'), self::package('acme/lib', 'dev-main'), self::package('acme/other', '1.0.0'), self::package('acme/other', 'dev-main')];
        $repository = new class ($served) extends ComposerRepository {
            /** @var list<BasePackage> */
            private array $served;

            /** @param list<BasePackage> $served */
            public function __construct(array $served)
            {
                $this->served = $served;
            }

            public function loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = []): array
            {
                if (isset($acceptableStabilities['dev']) && \count($packageNameMap) > 1) {
                    throw new TransportException('The "https://repo.example.org/p2/acme/lib~dev.json" file could not be downloaded');
                }
                $packages = array_values(array_filter($this->served, static fn (BasePackage $package): bool => \array_key_exists($package->getName(), $packageNameMap) && isset($acceptableStabilities[$package->getStability()])));

                return ['namesFound' => array_map(static fn (BasePackage $package): string => $package->getName(), $packages), 'packages' => $packages];
            }
        };
        $versions = new RepositoryVersions([$repository], [], false, Deadline::never(), 'dev', []);

        $loaded = $versions->versionsOf(['acme/lib', 'acme/other']);

        self::assertSame(['acme/lib' => ['1.0.0', 'dev-main'], 'acme/other' => ['1.0.0', 'dev-main']], array_map(static fn (array $packages): array => self::pretty($packages), $loaded));
        self::assertSame([null, null], [$versions->failure('acme/lib'), $versions->failure('acme/other')]);
    }

    /** A name whose tags and dev versions both loaded keeps them when a later request fails, though the budget runs out. */
    public function testAFailedRequestKeepsTheVersionsOfANameThatFinishedLoading(): void
    {
        $clock = new class () {
            public float $now = 0.0;
        };
        $first = self::recording(['acme/lib' => ['1.0.0', 'dev-main']]);
        $second = self::recording([], ['acme/next'], static function () use ($clock): void {
            $clock->now = 10.0;
        });
        $versions = new RepositoryVersions([$first, $second], [], false, Deadline::inSeconds(5.0, static fn (): float => $clock->now), 'dev', []);

        $loaded = $versions->versionsOf(['acme/lib', 'acme/next']);

        self::assertSame(['1.0.0', 'dev-main'], self::pretty($loaded['acme/lib']));
        self::assertSame([null, VersionSource::BUDGET], [$versions->failure('acme/lib'), $versions->failure('acme/next')]);
    }

    public function testAFailedRequestKeepsTheVersionsThatEveryEarlierRepositoryLoaded(): void
    {
        $clock = new class () {
            public float $now = 0.0;
        };
        $third = self::recording([], ['acme/next'], static function () use ($clock): void {
            $clock->now = 10.0;
        });
        $versions = new RepositoryVersions([self::recording(['acme/lib' => ['1.0.0']]), self::recording(['acme/other' => ['2.0.0']]), $third], [], false, Deadline::inSeconds(5.0, static fn (): float => $clock->now), 'stable', []);

        $loaded = $versions->versionsOf(['acme/lib', 'acme/other', 'acme/next']);

        self::assertSame(['acme/lib' => ['1.0.0'], 'acme/other' => ['2.0.0'], 'acme/next' => []], array_map(static fn (array $packages): array => self::pretty($packages), $loaded));
        self::assertSame([null, null, VersionSource::BUDGET], [$versions->failure('acme/lib'), $versions->failure('acme/other'), $versions->failure('acme/next')]);
    }

    /** G3: the first repository that lists the name wins, so a failed dev request there fails the name. */
    public function testAnAdmittedNameWhoseDevRequestFailsWhereItsTagsAreListedIsFailed(): void
    {
        $first = new class ([self::package('acme/lib', '1.0.0')]) extends ComposerRepository {
            /** @var list<BasePackage> */
            private array $served;

            /** @param list<BasePackage> $served */
            public function __construct(array $served)
            {
                $this->served = $served;
            }

            public function loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = []): array
            {
                if (isset($acceptableStabilities['dev'])) {
                    throw new TransportException('The "https://repo.example.org/p2/acme/lib~dev.json" file could not be downloaded');
                }

                return ['namesFound' => ['acme/lib'], 'packages' => $this->served];
            }
        };
        $versions = new RepositoryVersions([$first, self::recording(['acme/lib' => ['2.0.0', 'dev-main']])], [], false, Deadline::never(), 'dev', []);

        self::assertSame([], $versions->versions('acme/lib'));
        self::assertSame(VersionSource::FAILED, $versions->failure('acme/lib'));
    }

    public function testANameThatLoadsAloneUnderAStableRootLoadsItsDevVersionsOnlyWithoutATag(): void
    {
        $versions = new RepositoryVersions([self::recording(['acme/tagged' => ['1.0.0', 'dev-main'], 'acme/branch' => ['dev-main']], ['acme/bad'])], [], false, Deadline::never(), 'stable', []);

        $loaded = $versions->versionsOf(['acme/tagged', 'acme/branch', 'acme/bad']);

        self::assertSame(['1.0.0'], self::pretty($loaded['acme/tagged']));
        self::assertSame(['dev-main'], self::pretty($loaded['acme/branch']));
    }

    public function testANameThatLoadsAloneAfterAFailedRequestLoadsItsDevVersionsWhenTheRootAdmitsThem(): void
    {
        $versions = new RepositoryVersions([self::recording(['acme/good' => ['1.0.0', 'dev-main']], ['acme/bad'])], [], false, Deadline::never(), 'dev', []);

        self::assertSame(['1.0.0', 'dev-main'], self::pretty($versions->versionsOf(['acme/good', 'acme/bad'])['acme/good']));
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

    /** Offline, Composer reads a `~dev` file that its cache lacks as a 404: that is no answer, not a name without dev versions (ruling G4). */
    public function testOfflineAnAdmittedNameWhoseDevFileComposerHasNotCachedIsOffline(): void
    {
        $server = $this->server(['acme/lib' => ['1.0.0'], 'acme/lib~dev' => ['dev-main']]);
        $server->start();
        try {
            self::assertSame(['1.0.0'], self::pretty((new RepositoryVersions($server->repositories(), [], false, Deadline::never()))->versions('acme/lib')));
            putenv('COMPOSER_DISABLE_NETWORK=1');
            try {
                $offline = new RepositoryVersions($server->repositories(), [], true, Deadline::never(), 'dev', []);

                self::assertSame([[], VersionSource::OFFLINE], [$offline->versions('acme/lib'), $offline->failure('acme/lib')]);
            } finally {
                putenv('COMPOSER_DISABLE_NETWORK');
            }
        } finally {
            $server->stop();
        }
    }

    public function testOfflineAnAdmittedNameWhoseDevFileComposerHasCachedLoadsItsDevVersions(): void
    {
        $server = $this->server(['acme/lib' => ['1.0.0'], 'acme/lib~dev' => ['dev-main']]);
        $server->start();
        try {
            (new RepositoryVersions($server->repositories(), [], false, Deadline::never(), 'dev', []))->versions('acme/lib');
            putenv('COMPOSER_DISABLE_NETWORK=1');
            try {
                $offline = new RepositoryVersions($server->repositories(), [], true, Deadline::never(), 'dev', []);

                self::assertEqualsCanonicalizing(['1.0.0', 'dev-main'], self::pretty($offline->versions('acme/lib')));
                self::assertNull($offline->failure('acme/lib'));
            } finally {
                putenv('COMPOSER_DISABLE_NETWORK');
            }
        } finally {
            $server->stop();
        }
    }

    public function testOnlineAnAdmittedNameWhoseRepositoryHasNoDevFileLoadsItsTags(): void
    {
        $server = $this->server(['acme/lib' => ['1.0.0']]);
        $server->start();
        try {
            $versions = new RepositoryVersions($server->repositories(), [], false, Deadline::never(), 'dev', []);

            self::assertSame([['1.0.0'], null], [self::pretty($versions->versions('acme/lib')), $versions->failure('acme/lib')]);
        } finally {
            $server->stop();
        }
    }

    public function testOfflineAnAdmittedNameThatLoadsAloneWithoutADevAnswerIsOffline(): void
    {
        $versions = new RepositoryVersions([self::recording(['acme/lib' => ['1.0.0']], ['acme/bad'])], [], true, Deadline::never(), 'dev', []);

        self::assertSame([[], VersionSource::OFFLINE], [$versions->versionsOf(['acme/lib', 'acme/bad'])['acme/lib'], $versions->failure('acme/lib')]);
    }

    public function testOnlineAnAdmittedNameThatLoadsAloneWithoutADevAnswerKeepsItsTags(): void
    {
        $versions = new RepositoryVersions([self::recording(['acme/lib' => ['1.0.0']], ['acme/bad'])], [], false, Deadline::never(), 'dev', []);

        self::assertSame([['1.0.0'], null], [self::pretty($versions->versionsOf(['acme/lib', 'acme/bad'])['acme/lib']), $versions->failure('acme/lib')]);
    }

    public function testEachRepositoryIsAskedForTheNamesTogetherOncePerStability(): void
    {
        $first = self::recording(['acme/tagged' => ['1.0.0'], 'acme/branch' => ['dev-main']]);
        $second = self::recording(['acme/elsewhere' => ['2.0.0'], 'acme/tagged' => ['9.0.0']]);
        $versions = new RepositoryVersions([$first, $second], [], false, Deadline::never());

        $loaded = $versions->versionsOf(['acme/tagged', 'acme/branch', 'acme/elsewhere', 'acme/none']);

        self::assertSame(['acme/tagged' => ['1.0.0'], 'acme/branch' => ['dev-main'], 'acme/elsewhere' => ['2.0.0'], 'acme/none' => []], array_map(static fn (array $packages): array => self::pretty($packages), $loaded));
        self::assertSame([[['acme/tagged', 'acme/branch', 'acme/elsewhere', 'acme/none'], 'stable'], [['acme/branch', 'acme/elsewhere', 'acme/none'], 'dev']], $first->asked);
        self::assertSame([[['acme/elsewhere', 'acme/none'], 'stable'], [['acme/none'], 'dev']], $second->asked);
        self::assertNull($versions->failure('acme/none'));
    }

    public function testANameLoadsOnceAndALaterAskNamesOnlyTheNewNames(): void
    {
        $repository = self::recording(['acme/a' => ['1.0.0'], 'acme/b' => ['1.0.0']]);
        $versions = new RepositoryVersions([$repository], [], false, Deadline::never());

        $versions->versions('acme/a');
        $loaded = $versions->versionsOf(['acme/b', 'acme/a']);

        self::assertSame(['acme/b' => ['1.0.0'], 'acme/a' => ['1.0.0']], array_map(static fn (array $packages): array => self::pretty($packages), $loaded));
        self::assertSame([[['acme/a'], 'stable'], [['acme/b'], 'stable']], $repository->asked);
    }

    /** One name whose request fails must not fail the names asked with it. */
    public function testAFailedRequestOfSeveralNamesFailsOnlyTheNameThatFailsAlone(): void
    {
        $versions = new RepositoryVersions([self::recording(['acme/good' => ['1.0.0']], ['acme/bad']), self::recording(['acme/good' => ['9.0.0']])], [], false, Deadline::never());

        $loaded = $versions->versionsOf(['acme/good', 'acme/bad']);

        self::assertSame(['acme/good' => ['1.0.0'], 'acme/bad' => []], array_map(static fn (array $packages): array => self::pretty($packages), $loaded));
        self::assertSame([null, VersionSource::FAILED], [$versions->failure('acme/good'), $versions->failure('acme/bad')]);
    }

    /** A name that a later repository lists loads from there, though an earlier repository failed on it. */
    public function testANameThatFailsInOneRepositoryLoadsFromTheNext(): void
    {
        $versions = new RepositoryVersions([self::recording([], ['acme/lib']), self::recording(['acme/lib' => ['1.0.0']])], [], true, Deadline::never());

        self::assertSame(['1.0.0'], self::pretty($versions->versions('acme/lib')));
        self::assertNull($versions->failure('acme/lib'));
    }

    public function testANameThatFailsAndThatNoOtherRepositoryListsIsFailedEvenOffline(): void
    {
        $versions = new RepositoryVersions([self::recording([], ['acme/lib']), self::recording([])], [], true, Deadline::never());

        self::assertSame([], $versions->versions('acme/lib'));
        self::assertSame(VersionSource::FAILED, $versions->failure('acme/lib'));
    }

    public function testAfterTheDeadlineNoRepositoryIsAskedAndEachNameIsOverTheBudget(): void
    {
        $repository = self::recording(['acme/a' => ['1.0.0']]);
        $versions = new RepositoryVersions([$repository], ['acme/path'], false, Deadline::inSeconds(0.0, static fn (): float => 0.0));

        self::assertSame(['acme/a' => [], 'acme/path' => []], $versions->versionsOf(['acme/a', 'acme/path']));
        self::assertSame([VersionSource::BUDGET, null], [$versions->failure('acme/a'), $versions->failure('acme/path')]);
        self::assertSame([], $repository->asked);
    }

    /** A name that loads alone after a failed request keeps only its own versions, in the answer's order. */
    public function testANameThatLoadsAloneAfterAFailedRequestKeepsOnlyItsVersions(): void
    {
        $served = [self::package('acme/other', '2.0.0'), self::package('acme/good', '1.0.0'), self::package('acme/good', '1.1.0')];
        $versions = new RepositoryVersions([new RecordingComposerRepository($served, ['acme/bad'], null, true)], [], false, Deadline::never());

        self::assertSame(['1.0.0', '1.1.0'], self::pretty($versions->versionsOf(['acme/good', 'acme/bad'])['acme/good']));
        self::assertSame([0, 1], array_keys($versions->versions('acme/good')));
    }

    public function testANameThatNoRepositoryListsAfterAFailedRequestHasNoFailureOnline(): void
    {
        $versions = new RepositoryVersions([self::recording([], ['acme/bad'])], [], false, Deadline::never());

        self::assertSame(['acme/none' => [], 'acme/bad' => []], $versions->versionsOf(['acme/none', 'acme/bad']));
        self::assertSame([null, VersionSource::FAILED], [$versions->failure('acme/none'), $versions->failure('acme/bad')]);
    }

    /** After a failed request of several names, each name loads alone only while the budget lasts. */
    public function testTheNamesThatAFailedRequestLeavesAreOverTheBudgetOnceItRunsOut(): void
    {
        $clock = new class () {
            public float $now = 0.0;
        };
        $repository = self::recording(['acme/good' => ['1.0.0']], ['acme/bad'], static function () use ($clock): void {
            $clock->now = 10.0;
        });
        $versions = new RepositoryVersions([$repository], [], false, Deadline::inSeconds(5.0, static fn (): float => $clock->now));

        self::assertSame(['acme/good' => [], 'acme/bad' => []], $versions->versionsOf(['acme/good', 'acme/bad']));
        self::assertSame([VersionSource::BUDGET, VersionSource::BUDGET], [$versions->failure('acme/good'), $versions->failure('acme/bad')]);
        self::assertSame([[['acme/good', 'acme/bad'], 'stable']], $repository->asked);
    }

    public function testTheFailureOfANameStaysWhenALaterNameIsOffline(): void
    {
        $versions = new RepositoryVersions([self::recording([])], [], true, Deadline::never());

        $versions->versions('acme/first');
        $versions->versionsOf(['acme/second', 'acme/third']);

        self::assertSame([VersionSource::OFFLINE, VersionSource::OFFLINE], [$versions->failure('acme/first'), $versions->failure('acme/second')]);
    }

    public function testTheFailureOfANameStaysWhenALaterNameIsOverTheBudget(): void
    {
        $clock = new class () {
            public float $now = 0.0;
        };
        $versions = new RepositoryVersions([self::recording([], ['acme/bad'])], [], false, Deadline::inSeconds(5.0, static fn (): float => $clock->now));

        $versions->versions('acme/bad');
        $clock->now = 10.0;
        $versions->versionsOf(['acme/late', 'acme/later']);

        self::assertSame([VersionSource::FAILED, VersionSource::BUDGET], [$versions->failure('acme/bad'), $versions->failure('acme/late')]);
    }

    /** A path, VCS or package repository serves the name: the lock's version is its only one. */
    public function testANameThatNoComposerRepositoryServesIsNotAsked(): void
    {
        $repository = self::recording(['acme/path' => ['2.0.0'], 'acme/lib' => ['1.0.0']]);
        $versions = new RepositoryVersions([$repository], ['acme/path'], false, Deadline::never());

        self::assertSame(['acme/path' => [], 'acme/lib' => ['1.0.0']], array_map(static fn (array $packages): array => self::pretty($packages), $versions->versionsOf(['acme/path', 'acme/lib'])));
        self::assertSame([[['acme/lib'], 'stable']], $repository->asked);
        self::assertNull($versions->failure('acme/path'));
    }

    /** Composer wraps a repository that has `only`, `exclude` or `canonical` in a FilterRepository. */
    public function testARepositoryWithOnlyServesItsNamesBeforeAPackagistNamesake(): void
    {
        $filtered = new FilterRepository(self::repository(['acme/lib' => ['1.4.0', '2.0.0'], 'other/lib' => ['5.0.0']]), ['only' => ['acme/*']]);
        $packagist = self::repository(['acme/lib' => ['9.0.0'], 'other/lib' => ['1.0.0']]);
        $versions = new RepositoryVersions([$filtered, $packagist], [], false, Deadline::never());

        self::assertSame(['acme/lib' => ['1.4.0', '2.0.0'], 'other/lib' => ['1.0.0']], array_map(static fn (array $packages): array => self::pretty($packages), $versions->versionsOf(['acme/lib', 'other/lib'])));
        self::assertSame(['1.4.0', '2.0.0'], self::pretty((new RepositoryVersions([$filtered, $packagist], [], false, Deadline::never()))->versions('acme/lib')));
    }

    public function testARepositoryWithExcludeLeavesTheExcludedNameToTheNextRepository(): void
    {
        $filtered = new FilterRepository(self::repository(['acme/lib' => ['2.0.0'], 'acme/tool' => ['3.0.0']]), ['exclude' => ['acme/tool']]);
        $versions = new RepositoryVersions([$filtered, self::repository(['acme/tool' => ['1.0.0']])], [], false, Deadline::never());

        self::assertSame(['acme/lib' => ['2.0.0'], 'acme/tool' => ['1.0.0']], array_map(static fn (array $packages): array => self::pretty($packages), $versions->versionsOf(['acme/lib', 'acme/tool'])));
    }

    /** A repository that is not canonical leaves the name to the next ones too, and the earlier repository keeps a version that both list. */
    public function testANonCanonicalRepositoryAddsTheVersionsOfTheNextRepositories(): void
    {
        $first = self::package('acme/lib', '2.0.0');
        $filtered = new FilterRepository(new RecordingComposerRepository([self::package('acme/lib', '1.0.0'), $first]), ['canonical' => false]);
        $next = self::repository(['acme/lib' => ['2.0.0', '3.0.0'], 'acme/other' => ['1.0.0']]);
        $versions = new RepositoryVersions([$filtered, $next], [], false, Deadline::never());

        self::assertSame(['acme/lib' => ['1.0.0', '2.0.0', '3.0.0'], 'acme/other' => ['1.0.0']], array_map(static fn (array $packages): array => self::pretty($packages), $versions->versionsOf(['acme/lib', 'acme/other'])));
        self::assertSame($first, $versions->versions('acme/lib')[1]);
    }

    public function testANonCanonicalRepositoryAddsTheVersionsOfTheNextRepositoriesForANameThatLoadsAlone(): void
    {
        $filtered = new FilterRepository(self::recording(['acme/lib' => ['1.0.0']], ['acme/bad']), ['canonical' => false]);
        $versions = new RepositoryVersions([$filtered, self::repository(['acme/lib' => ['3.0.0']])], [], false, Deadline::never());

        $loaded = $versions->versionsOf(['acme/lib', 'acme/bad']);

        self::assertSame(['acme/lib' => ['1.0.0', '3.0.0'], 'acme/bad' => []], array_map(static fn (array $packages): array => self::pretty($packages), $loaded));
        self::assertSame(VersionSource::FAILED, $versions->failure('acme/bad'));
    }

    /** A path, VCS or package repository can serve a name that the lock does not hold, and lockrot does not ask it. */
    public function testANameThatNoComposerRepositoryListsIsUnreachedWhenAnotherRepositoryCanServeIt(): void
    {
        $withPath = new RepositoryVersions([self::repository(['acme/lib' => ['1.0.0']]), new ArrayRepository()], [], false, Deadline::never());
        $withFilteredPath = new RepositoryVersions([new FilterRepository(new ArrayRepository(), ['only' => ['acme/*']])], [], false, Deadline::never());
        $composerOnly = new RepositoryVersions([self::repository(['acme/lib' => ['1.0.0']])], [], false, Deadline::never());

        self::assertSame([[], VersionSource::UNREACHED], [$withPath->versions('acme/private'), $withPath->failure('acme/private')]);
        self::assertSame([['1.0.0'], null], [self::pretty($withPath->versions('acme/lib')), $withPath->failure('acme/lib')]);
        self::assertSame([[], VersionSource::UNREACHED], [$withFilteredPath->versions('acme/private'), $withFilteredPath->failure('acme/private')]);
        self::assertSame([[], null], [$composerOnly->versions('acme/private'), $composerOnly->failure('acme/private')]);
    }

    public function testOfflineANameThatNoComposerRepositoryListsStaysOffline(): void
    {
        $versions = new RepositoryVersions([self::repository([]), new ArrayRepository()], [], true, Deadline::never());

        self::assertSame([[], VersionSource::OFFLINE], [$versions->versions('acme/private'), $versions->failure('acme/private')]);
    }

    /**
     * @param array<string, list<string>> $versions name => its pretty versions
     * @param list<string>                $failing
     * @param ?\Closure(): void            $onFailure
     */
    private static function recording(array $versions, array $failing = [], ?\Closure $onFailure = null): RecordingComposerRepository
    {
        $packages = [];
        foreach ($versions as $name => $list) {
            foreach ($list as $version) {
                $packages[] = self::package($name, $version);
            }
        }

        return new RecordingComposerRepository($packages, $failing, $onFailure);
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

    /**
     * A repository server for the given p2 files, each file name with its versions.
     *
     * @param array<string, list<string>> $versionsByFile
     */
    private function server(array $versionsByFile): FixtureRepositoryServer
    {
        $dir = sys_get_temp_dir().'/lockrot-repository-versions-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($dir.'/envelopes', 0777, true));
        $this->tempDirs[] = $dir;
        $names = [];
        foreach ($versionsByFile as $file => $versions) {
            $name = (string) preg_replace('{~dev$}', '', $file);
            $names[$name] = ['name' => $name, 'version' => '1.0.0'];
            $entries = array_map(static fn (string $version): array => ['name' => $name, 'version' => $version, 'type' => 'library'], $versions);
            file_put_contents(RecordedHttpClient::pathFor($dir.'/envelopes', 'https://repo.packagist.org/p2/'.$file.'.json'), (string) json_encode(['status' => 200, 'fetched_at' => '2026-10-08T00:00:00+00:00', 'body' => json_encode(['packages' => [$name => $entries]]), 'error' => null]));
        }
        file_put_contents($dir.'/composer.lock', (string) json_encode(['packages' => array_values($names)]));

        return FixtureRepositoryServer::fromLockFiles([$dir.'/composer.lock'], $dir.'/envelopes');
    }

    private static function removeTree(string $dir): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
}
