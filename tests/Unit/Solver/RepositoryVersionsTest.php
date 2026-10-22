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
use Lockrot\Tests\Support\RecordingComposerRepository;
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
}
