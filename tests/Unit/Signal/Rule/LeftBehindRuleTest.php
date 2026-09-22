<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Composer\Package\Loader\ArrayLoader;
use Lockrot\Clock;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Rule\LeftBehindRule;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class LeftBehindRuleTest extends TestCase
{
    private function rule(?PhpFloor $floor = null): LeftBehindRule
    {
        return new LeftBehindRule(Clock::fixed(F::NOW), new Thresholds(), $floor);
    }

    public function testHighWhenTheInstalledMajorStoppedFiveYearsAgoAndANewerMajorReleased(): void
    {
        $meta = F::metadata([['3.4.1', '2026-06-01'], ['3.0.0', '2024-01-10'], ['2.2.0', '2021-05-05'], ['1.9.2', '2019-03-02'], ['1.0.0', '2015-01-01']]);

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => 'v1.8.0']), $meta));

        self::assertNotNull($signal);
        self::assertSame(Signal::S8, $signal->id());
        self::assertSame(Signal::LEVEL_HIGH, $signal->level());
        self::assertSame('branch 1.x last released 2019-03-02 (7.5 years ago); 3.x released 3.4.1 (2026-06-01)', $signal->summary());
        self::assertSame([
            'branch' => '1.x',
            'branch_last_release' => '2019-03-02T00:00:00+00:00',
            'branch_last_version' => '1.9.2',
            'years' => 7.5,
            'newest_branch' => '3.x',
            'newest_version' => '3.4.1',
            'newest_release' => '2026-06-01T00:00:00+00:00',
            'newest_php' => null,
            'newest_within_reach' => true,
            'floor_php' => null,
            'floor_source' => null,
            'reachable_branch' => '3.x',
            'reachable_version' => '3.4.1',
            'reachable_release' => '2026-06-01T00:00:00+00:00',
            'suggested_constraint' => '^3.4',
            'dated_by' => null,
        ], $signal->data());
    }

    public function testWarnBetweenThreeAndFiveYears(): void
    {
        $meta = F::metadata([['2.0.0', '2024-01-10'], ['1.9.2', '2022-08-01']]);

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta));

        self::assertNotNull($signal);
        self::assertSame(Signal::LEVEL_WARN, $signal->level());
    }

    public function testExactlyAtTheWarnThresholdIsWarn(): void
    {
        $meta = F::metadata([['2.0.0', '2024-01-10'], ['1.9.2', '2023-09-14T00:00:00+00:00']]);

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta));

        self::assertNotNull($signal);
        self::assertSame(Signal::LEVEL_WARN, $signal->level());
    }

    public function testJustPastTheHighThresholdIsHigh(): void
    {
        // Five calendar years back is 1826 days, a hair under 5 × 365.25; one more day crosses it.
        $meta = F::metadata([['2.0.0', '2024-01-10'], ['1.9.2', '2021-09-13T00:00:00+00:00']]);

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta));

        self::assertNotNull($signal);
        self::assertSame(Signal::LEVEL_HIGH, $signal->level());
    }

    public function testABranchStillReleasingIsNull(): void
    {
        $meta = F::metadata([['2.0.0', '2024-01-10'], ['1.9.9', '2025-01-01'], ['1.9.2', '2019-03-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta)));
    }

    public function testNoHigherBranchIsNull(): void
    {
        // The whole package is old: S2 speaks, not S8.
        $meta = F::metadata([['1.9.2', '2019-03-02'], ['1.0.0', '2015-01-01']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta)));
    }

    public function testAHigherBranchThatStoppedBeforeOursIsNull(): void
    {
        // A 2.0 that was abandoned before 1.x got its last release is not the upstream moving on.
        $meta = F::metadata([['2.0.0-beta1', '2017-01-01'], ['2.0.0', '2017-06-01'], ['1.9.2', '2019-03-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta)));
    }

    /** hoa/consistency: 1.x stopped in 2017-05, 2.x in 2017-08 — nobody moved on, the package died. S2's case. */
    public function testAHigherBranchThatIsItselfOldIsNull(): void
    {
        $meta = F::metadata([['2.17.08.29', '2017-08-29'], ['1.17.05.02', '2017-05-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.17.05.02']), $meta)));
    }

    public function testTheHigherBranchMustHaveReleasedWithinTheWarnYears(): void
    {
        // 3 × 365.25 days before NOW is 2023-09-14T06:00: exactly the warn threshold, no longer alive.
        $exactlyAtThreshold = F::metadata([['2.0.0', '2023-09-14T06:00:00+00:00'], ['1.9.2', '2019-03-02']]);
        $justTooOld = F::metadata([['2.0.0', '2023-09-13T00:00:00+00:00'], ['1.9.2', '2019-03-02']]);
        $alive = F::metadata([['2.0.0', '2023-09-15T00:00:00+00:00'], ['1.9.2', '2019-03-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $exactlyAtThreshold)));
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $justTooOld)));
        self::assertNotNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $alive)));
    }

    public function testAPreReleaseOnAHigherBranchIsNotTheUpstreamMovingOn(): void
    {
        $meta = F::metadata([['2.0.0-alpha1', '2026-01-01'], ['1.9.2', '2019-03-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta)));
    }

    public function testAHigherBranchReleasedTheSameDayAsOursHasNotMovedOn(): void
    {
        $meta = F::metadata([['2.0.0', '2019-03-02'], ['1.9.2', '2019-03-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta)));
    }

    public function testTwoHigherBranchesReleasedTheSameDayKeepTheFirstListed(): void
    {
        // Packagist lists the highest version first, so a tie names the highest branch.
        $meta = F::metadata([['3.0.0', '2026-01-01'], ['2.5.0', '2026-01-01'], ['1.9.2', '2019-03-02']]);

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta));

        self::assertNotNull($signal);
        self::assertStringEndsWith('; 3.x released 3.0.0 (2026-01-01)', $signal->summary());
        self::assertSame('3.x', $signal->data()['reachable_branch'], 'the branch within reach ties the same way');
        self::assertSame('^3.0', $signal->data()['suggested_constraint']);
    }

    public function testAHigherBranchWithoutADateIsNotEvidence(): void
    {
        $meta = F::metadata([['2.0.0', null], ['1.9.2', '2019-03-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta)));
    }

    public function testTheInstalledBranchWithoutADateIsNull(): void
    {
        $meta = F::metadata([['2.0.0', '2024-01-10'], ['1.9.2', null]]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta)));
    }

    public function testALowerBranchStillReleasingDoesNotCount(): void
    {
        // Installed 2.x went quiet, 1.x LTS still gets releases: nothing moved on from 2.x.
        $meta = F::metadata([['2.0.0', '2019-03-02'], ['1.9.9', '2026-01-01']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '2.0.0']), $meta)));
    }

    public function testTheNewestOfSeveralHigherBranchesIsNamed(): void
    {
        $meta = F::metadata([['3.0.0', '2023-01-01'], ['2.5.0', '2026-02-02'], ['1.9.2', '2019-03-02']]);

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta));

        self::assertNotNull($signal);
        self::assertStringEndsWith('; 2.x released 2.5.0 (2026-02-02)', $signal->summary());
    }

    /** A branch listed first that is not above ours must not end the search for one that is. */
    public function testALowerBranchListedFirstDoesNotHideAHigherOne(): void
    {
        $meta = F::metadata([['0.9.0', '2015-01-01'], ['2.0.0', '2026-01-01'], ['1.9.2', '2019-03-02']]);

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta));

        self::assertNotNull($signal);
        self::assertStringEndsWith('; 2.x released 2.0.0 (2026-01-01)', $signal->summary());
    }

    public function testZeroDotBranchesArePerMinor(): void
    {
        $meta = F::metadata([['0.5.0', '2026-01-01'], ['0.3.9', '2019-03-02']]);

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => '0.3.1']), $meta));

        self::assertNotNull($signal);
        self::assertSame('branch 0.3.x last released 2019-03-02 (7.5 years ago); 0.5.x released 0.5.0 (2026-01-01)', $signal->summary());
    }

    public function testADevInstalledVersionIsNull(): void
    {
        $meta = F::metadata([['2.0.0', '2026-01-01'], ['1.9.2', '2019-03-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => 'dev-master']), $meta)));
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.x-dev']), $meta)));
    }

    public function testAnInstalledBranchAbsentFromTheMetadataIsNull(): void
    {
        $meta = F::metadata([['2.0.0', '2026-01-01'], ['1.9.2', '2019-03-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '0.9.0']), $meta)));
    }

    /** A lock written against a tag the repository no longer lists: the branch's last date says nothing about it. */
    public function testAnInstalledVersionAboveEverythingListedOnItsBranchIsNull(): void
    {
        $meta = F::metadata([['2.0.0', '2026-01-01'], ['1.9.2', '2019-03-02']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => 'v1.9.5']), $meta)));
        self::assertNotNull($this->rule()->evaluate(F::facts(F::package(['version' => 'v1.9.2']), $meta)), 'the listed version itself is measured');
        self::assertNotNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.0.0']), $meta)), 'an older install on the branch is measured by the branch');
    }

    /**
     * illuminate/macroable 10.x: the newest dated tag says 2023, the undated ones above it are 2025.
     * A branch whose highest tag carries no date has an age nobody can read, so it carries no S8.
     */
    public function testAnUndatedHighestTagOnTheInstalledBranchMeansNoSignal(): void
    {
        $meta = F::metadata([['13.32.0', '2026-09-13T00:00:00+00:00'], ['10.49.0', null], ['10.13.1', '2023-03-17T00:00:00+00:00']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '10.48.28']), $meta)));
    }

    /** Two branches released the same second: neither came after the other, and the upstream did not move on. */
    public function testAHigherBranchReleasedAtTheSameInstantIsNotAMoveOn(): void
    {
        $meta = F::metadata([['2.0.0', '2022-09-14T00:00:00+00:00'], ['1.0.0', '2022-09-14T00:00:00+00:00']]);

        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.0.0']), $meta)));
    }

    /** php-http/promise: the highest 1.x tag is older than a later backport on a lower minor. */
    public function testTheTopOfADeadBranchIsMeasuredEvenWhenABackportBelowItIsNewer(): void
    {
        $meta = F::metadata([['2.3.0', '2025-06-01'], ['1.5.0', '2018-01-01'], ['1.4.9', '2019-06-01']]);

        $onTheTop = $this->rule()->evaluate(F::facts(F::package(['version' => '1.5.0']), $meta));
        $onTheBackport = $this->rule()->evaluate(F::facts(F::package(['version' => '1.4.9']), $meta));

        self::assertNotNull($onTheTop, 'the installed version is listed, so it is not ahead of the branch');
        self::assertSame('branch 1.x last released 2019-06-01 (7.3 years ago); 2.x released 2.3.0 (2025-06-01)', $onTheTop->summary());
        self::assertNotNull($onTheBackport);
        self::assertSame($onTheTop->summary(), $onTheBackport->summary());
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.6.0']), $meta)), 'above the highest tag: not listed');
    }

    /**
     * A higher branch dated only by a commit its tags share still counts as the upstream moving
     * on: the commit's date is when the directory last changed, which is at or before the release
     * that carried it — a lower bound. If that lower bound is recent, the release is more recent
     * still, so the S8 it supports is real; an understated date can only make a move-on less
     * likely, never invent one. The installed branch is measured by its own, release-dated tag.
     */
    public function testAHigherBranchDatedByASharedCommitStillCountsAsAMoveOn(): void
    {
        $loader = new ArrayLoader();
        $on = static fn (string $version, string $commit, string $time): array => ['name' => 'vendor/pkg', 'version' => $version, 'time' => $time, 'source' => ['type' => 'git', 'url' => 'https://github.com/vendor/pkg.git', 'reference' => $commit]];
        $meta = PackageMetadata::fromPackages('vendor/pkg', [
            $loader->load($on('2.3.0', 'split', '2025-06-01T00:00:00+00:00')),
            $loader->load($on('2.2.0', 'split', '2025-06-01T00:00:00+00:00')),
            $loader->load($on('2.1.0', 'split', '2025-06-01T00:00:00+00:00')),
            $loader->load($on('1.5.0', 'own', '2019-06-01T00:00:00+00:00')),
        ], new \DateTimeImmutable(F::NOW));

        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => '1.5.0']), $meta));

        self::assertNotNull($signal);
        self::assertSame('branch 1.x last released 2019-06-01 (7.3 years ago); 2.x released 2.3.0 (2025-06-01)', $signal->summary());
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '2.2.0']), $meta)), 'the shared-commit branch itself is not measured');
    }

    public function testNoMetadataIsNull(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['version' => '1.0.0']))));
    }

    /**
     * The signal carries the constraint that follows the upstream, written as `composer require`
     * writes it: `^8.2` from 8.2.0, `^0.4.3` below 1.0 (a caret there stays inside the minor).
     */
    public function testTheSignalSuggestsTheConstraintThatFollowsTheNewestBranch(): void
    {
        $major = $this->rule()->evaluate(F::facts(F::package(['version' => '6.5.8']), F::metadata([['v8.2.0', '2026-09-06'], ['7.15.5', '2026-08-24'], ['6.5.8', '2022-06-20']])));
        $minor = $this->rule()->evaluate(F::facts(F::package(['version' => '0.2.1']), F::metadata([['0.4.3', '2026-01-01'], ['0.2.1', '2020-01-01']])));

        self::assertNotNull($major);
        self::assertSame('^8.2', $major->data()['suggested_constraint']);
        self::assertSame('8.x', $major->data()['newest_branch']);
        self::assertNotNull($minor);
        self::assertSame('^0.4.3', $minor->data()['suggested_constraint']);
    }

    /** A branch the monorepo parent dated ({@see PackageMetadata::datedBy()}) says so in the summary and the data. */
    public function testABranchDatedByTheMonorepoNamesIt(): void
    {
        /** @return array{version: string, at: \DateTimeImmutable, highest: array{normalized: string, pretty: string, at: \DateTimeImmutable}, dated_by?: string, php: ?string} */
        $branch = static function (string $version, string $at, ?string $datedBy): array {
            $row = ['version' => $version, 'at' => new \DateTimeImmutable($at), 'highest' => ['normalized' => $version.'.0', 'pretty' => 'v'.$version, 'at' => new \DateTimeImmutable($at)], 'php' => null];

            return $datedBy === null ? $row : $row + ['dated_by' => $datedBy];
        };
        $meta = new PackageMetadata('illuminate/contracts', false, null, true, new \DateTimeImmutable('2026-09-01'), 'v13.24.0', 2, null, 'library', new \DateTimeImmutable(F::NOW), [
            '13' => $branch('13.24.0', '2026-09-01T00:00:00+00:00', 'laravel/framework'),
            '8' => $branch('8.83.29', '2021-11-20T15:55:41+00:00', 'laravel/framework'),
        ], [], 'laravel/framework');

        $signal = $this->rule()->evaluate(F::facts(F::package(['name' => 'illuminate/contracts', 'version' => 'v8.83.27']), $meta));

        self::assertNotNull($signal);
        self::assertSame(Signal::LEVEL_WARN, $signal->level());
        self::assertSame('branch 8.x last released 2021-11-20 (4.8 years ago, dated by laravel/framework); 13.x released 13.24.0 (2026-09-01)', $signal->summary());
        self::assertSame('laravel/framework', $signal->data()['dated_by']);
        self::assertSame('^13.24', $signal->data()['suggested_constraint']);
    }

    /**
     * Matomo 5.13.0 requires php >=7.2.5 and locks monolog 1.27.1. monolog 3.x needs php >=8.1 — the
     * project cannot require it without dropping PHP 7 — and 2.x, alive and within reach, is what the
     * signal points at: the newest branch is still the proof the upstream moved on, the reachable one
     * is where the project can follow.
     */
    public function testTheBranchNamedToFollowIsTheNewestOneWithinTheProjectsReach(): void
    {
        $meta = F::metadata([['3.12.0', '2026-09-09', '>=8.1'], ['2.11.1', '2026-09-02', '>=7.2'], ['1.27.1', '2022-06-09', '>=5.3.0']]);

        $signal = $this->rule(new PhpFloor('8.4', '>=7.2.5'))->evaluate(F::facts(F::package(['version' => '1.27.1']), $meta));

        self::assertNotNull($signal);
        self::assertSame(Signal::LEVEL_WARN, $signal->level());
        self::assertSame('branch 1.x last released 2022-06-09 (4.3 years ago); 3.x released 3.12.0 (2026-09-09), needs php >=8.1 above the project\'s php >=7.2.5; 2.x released 2.11.1 (2026-09-02)', $signal->summary());
        $data = $signal->data();
        self::assertSame('3.x', $data['newest_branch']);
        self::assertSame('>=8.1', $data['newest_php']);
        self::assertFalse($data['newest_within_reach']);
        self::assertSame('>=7.2.5', $data['floor_php']);
        self::assertSame('project', $data['floor_source']);
        self::assertSame('2.x', $data['reachable_branch']);
        self::assertSame('2.11.1', $data['reachable_version']);
        self::assertSame('2026-09-02T00:00:00+00:00', $data['reachable_release']);
        self::assertSame('^2.11', $data['suggested_constraint']);
    }

    /** phpBB 3.3 on symfony 3.4: 8.x needs php >=8.4.1, 4.x stopped in 2022 — nothing within `^7.2 || ^8.0.0` is releasing. */
    public function testNoBranchWithinReachIsSaidAndNothingIsSuggested(): void
    {
        $meta = F::metadata([['v8.1.7', '2026-09-10', '>=8.4.1'], ['v4.4.49', '2022-11-04', '>=7.1.3'], ['v3.4.47', '2020-10-24', '^5.5.9|>=7.0.8']]);

        $signal = $this->rule(new PhpFloor('8.4', '^7.2 || ^8.0.0'))->evaluate(F::facts(F::package(['version' => 'v3.4.47']), $meta));

        self::assertNotNull($signal);
        self::assertSame(Signal::LEVEL_HIGH, $signal->level());
        self::assertSame('branch 3.x last released 2020-10-24 (5.9 years ago); 8.x released v8.1.7 (2026-09-10), needs php >=8.4.1 above the project\'s php ^7.2 || ^8.0.0; no releasing branch within reach', $signal->summary());
        $data = $signal->data();
        self::assertFalse($data['newest_within_reach']);
        self::assertNull($data['reachable_branch']);
        self::assertNull($data['reachable_version']);
        self::assertNull($data['reachable_release']);
        self::assertNull($data['suggested_constraint']);
    }

    /** A project that declares no php: the target PHP — `config.platform.php` or the running one — is the floor, and is named as such. */
    public function testTheTargetPhpIsTheFloorWhenTheProjectDeclaresNone(): void
    {
        $meta = F::metadata([['3.12.0', '2026-09-09', '>=8.1'], ['2.11.1', '2026-09-02', '>=7.2'], ['1.27.1', '2022-06-09', '>=5.3.0']]);

        $signal = $this->rule(new PhpFloor('7.2'))->evaluate(F::facts(F::package(['version' => '1.27.1']), $meta));

        self::assertNotNull($signal);
        self::assertStringEndsWith('; 3.x released 3.12.0 (2026-09-09), needs php >=8.1 above the target PHP 7.2; 2.x released 2.11.1 (2026-09-02)', $signal->summary());
        self::assertSame('7.2', $signal->data()['floor_php']);
        self::assertSame('target', $signal->data()['floor_source']);
        self::assertSame('^2.11', $signal->data()['suggested_constraint']);
    }

    /** A branch within reach that stopped releasing is no branch to follow: the one named has to be alive, as the newest one has to be. */
    public function testABranchWithinReachThatWentQuietIsNotNamed(): void
    {
        $meta = F::metadata([['3.0.0', '2026-01-01', '>=8.1'], ['2.5.0', '2022-06-01', '>=7.2'], ['1.9.2', '2019-03-02', '>=5.6']]);

        $signal = $this->rule(new PhpFloor('7.4'))->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta));

        self::assertNotNull($signal);
        self::assertStringEndsWith('needs php >=8.1 above the target PHP 7.4; no releasing branch within reach', $signal->summary());
        self::assertNull($signal->data()['suggested_constraint']);
    }

    /** The newest branch within reach is the one named, whatever is between: 2.x here, not the higher 3.x that also fits but released earlier. */
    public function testTheNewestReleasingBranchWithinReachIsNamed(): void
    {
        $meta = F::metadata([['4.0.0', '2026-06-01', '>=8.2'], ['3.4.0', '2025-01-01', '>=7.4'], ['2.9.0', '2026-03-01', '>=7.4'], ['1.9.2', '2019-03-02', '>=5.6']]);

        $signal = $this->rule(new PhpFloor('7.4'))->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta));

        self::assertNotNull($signal);
        self::assertStringEndsWith('; 4.x released 4.0.0 (2026-06-01), needs php >=8.2 above the target PHP 7.4; 2.x released 2.9.0 (2026-03-01)', $signal->summary());
        self::assertSame('^2.9', $signal->data()['suggested_constraint']);
    }

    /** With the newest branch within reach the summary reads as before: the floor changes nothing it does not have to. */
    public function testANewestBranchWithinReachIsNamedAsBefore(): void
    {
        $meta = F::metadata([['2.0.0', '2024-01-10', '>=7.2'], ['1.9.2', '2022-08-01', '>=5.6']]);

        $signal = $this->rule(new PhpFloor('8.4', '>=7.2.5'))->evaluate(F::facts(F::package(['version' => '1.9.2']), $meta));

        self::assertNotNull($signal);
        self::assertSame('branch 1.x last released 2022-08-01 (4.1 years ago); 2.x released 2.0.0 (2024-01-10)', $signal->summary());
        self::assertTrue($signal->data()['newest_within_reach']);
        self::assertSame('>=7.2', $signal->data()['newest_php']);
        self::assertSame('^2.0', $signal->data()['suggested_constraint']);
    }
}
