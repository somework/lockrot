<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Signal\ConstraintOpenness;
use Lockrot\Signal\Rule\OldPromiseRule;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class OldPromiseRuleTest extends TestCase
{
    private function rule(string $target = '8.4'): OldPromiseRule
    {
        return new OldPromiseRule(new ConstraintOpenness(), PhpReleaseDates::load(), $target);
    }

    /** phpzip/phpzip 2.0.8: written for PHP 5 in 2015, and PHP 8.4 installs it because `>=5.3.0` lets it. */
    public function testOldReleaseWithOpenConstraint(): void
    {
        $signal = $this->rule()->evaluate(F::facts(F::package(['time' => '2015-11-16T16:30:51+00:00', 'php' => '>=5.3.0'])));
        self::assertNotNull($signal);
        self::assertSame(Signal::S5, $signal->id());
        self::assertSame(Signal::LEVEL_WARN, $signal->level());
        self::assertSame('released 2015-11-16 for PHP 5 (php ">=5.3.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested', $signal->summary());
        self::assertSame([
            'target_php' => '8.4',
            'target_major' => '8.0',
            'ga_date' => '2020-11-26',
            'php_constraint' => '>=5.3.0',
            'written_for_php' => 5,
            'released' => '2015-11-16T16:30:51+00:00',
        ], JsonPath::decoded($signal->data()));
    }

    /**
     * The line is the GA of the target's major, not of the target minor. geoip2/geoip2 2.13.0
     * (2022-08, `>=7.2`) was written with PHP 8.1 on every CI matrix. It is not an old promise about
     * PHP 8, and differs from a `^7.2 || ^8.0` of the same day in spelling alone.
     */
    public function testAReleaseOfTheTargetMajorsEraIsNotAPromiseAboutThatMajor(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['time' => '2022-08-05', 'php' => '>=7.2']))));
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['time' => '2020-11-26T00:00:00+00:00', 'php' => '>=5.3.0']))), 'released on the GA day: PHP 8 existed');
        self::assertNotNull($this->rule()->evaluate(F::facts(F::package(['time' => '2020-11-25T23:59:59+00:00', 'php' => '>=5.3.0']))), 'the day before, it did not');
    }

    public function testExplicitMajorIsNotAPromise(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['time' => '2019-01-01', 'php' => '^7.1 || ^8.0']))));
    }

    public function testAConstraintWithNoLowerBoundIsSaidAsSuch(): void
    {
        $signal = $this->rule()->evaluate(F::facts(F::package(['time' => '2015-01-01T00:00:00+00:00', 'php' => '*'])));
        self::assertNotNull($signal);
        self::assertSame('released 2015-01-01 with php "*", before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested', $signal->summary());
        self::assertNull(JsonPath::decoded($signal->data())['written_for_php']);
    }

    /** `>=7.4` from 2019 is an old promise about PHP 8.0 exactly as about 8.4. */
    public function testTheTargetsOwnMinorIsNamedAsWhatIsAdmitted(): void
    {
        $signal = $this->rule('8.0')->evaluate(F::facts(F::package(['time' => '2019-11-28', 'php' => '>=7.4'])));
        self::assertNotNull($signal);
        self::assertStringEndsWith('before PHP 8 existed (8.0 GA 2020-11-26); admits 8.0 untested', $signal->summary());
    }

    public function testATargetMajorWithoutAGaDateIsNull(): void
    {
        self::assertNull($this->rule('9.0')->evaluate(F::facts(F::package(['time' => '2024-01-01', 'php' => '>=8.1']))));
    }

    public function testReleasedAfterGaIsNull(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['time' => '2025-01-01', 'php' => '>=7.4']))));
    }

    public function testMissingDataIsNull(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['php' => '>=5.3']))));
        self::assertNull($this->rule()->evaluate(F::facts(F::package(['time' => '2015-01-01']))));
        self::assertNull($this->rule('9.9')->evaluate(F::facts(F::package(['time' => '2015-01-01', 'php' => '>=5.3']))));
    }

    public function testRandomCompat99100IsAPromiseUntilAllowlisted(): void
    {
        self::assertNotNull($this->rule()->evaluate(F::facts(F::package(['time' => '2020-10-15', 'php' => '>= 7']))));
    }

    /**
     * A symfony 4.4 split: three tags on one commit dated before the PHP 8.0 GA. That date is the
     * commit's, not the release's (docs/verdicts.md#dates-from-the-monorepo).
     */
    private static function splitMetadata(?string $parentDate): PackageMetadata
    {
        $tag = static function (string $name, string $version, string $commit, string $time, array $replace = []): CompletePackage {
            $config = ['name' => $name, 'version' => $version, 'time' => $time, 'require' => ['php' => '>=7.1.3'], 'source' => ['type' => 'git', 'url' => 'https://github.com/'.$name.'.git', 'reference' => $commit]];
            if ($replace !== []) {
                $config['replace'] = $replace;
            }
            $package = (new ArrayLoader())->load($config);
            self::assertInstanceOf(CompletePackage::class, $package);

            return $package;
        };
        $now = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');
        $child = PackageMetadata::fromPackages('symfony/http-foundation', [
            $tag('symfony/http-foundation', 'v4.4.31', 'split', '2020-10-01T00:00:00+00:00'),
            $tag('symfony/http-foundation', 'v4.4.30', 'split', '2020-10-01T00:00:00+00:00'),
            $tag('symfony/http-foundation', 'v4.4.29', 'split', '2020-10-01T00:00:00+00:00'),
        ], $now, 'v4.4.30');
        if ($parentDate === null) {
            return $child;
        }

        return $child->datedBy(PackageMetadata::fromPackages('symfony/symfony', [
            $tag('symfony/symfony', 'v4.4.30', 'parent', $parentDate, ['symfony/http-foundation' => 'self.version']),
        ], $now));
    }

    /** @param array<string, mixed> $o */
    private static function splitPackage(array $o = []): \Lockrot\Lock\LockedPackage
    {
        return F::package($o + ['name' => 'symfony/http-foundation', 'version' => 'v4.4.30', 'time' => '2020-10-01T00:00:00+00:00', 'php' => '>=7.1.3']);
    }

    public function testASharedCommitDateWithoutAParentDateIsNoRelease(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(self::splitPackage(), self::splitMetadata(null))));
    }

    public function testAParentDateAfterTheGaClearsTheSplitsEarlierCommitDate(): void
    {
        self::assertNull($this->rule()->evaluate(F::facts(self::splitPackage(), self::splitMetadata('2021-08-30T00:00:00+00:00'))));
    }

    public function testAParentDateBeforeTheGaIsTheReleaseDateThatS5Judges(): void
    {
        $signal = $this->rule()->evaluate(F::facts(self::splitPackage(), self::splitMetadata('2020-09-15T00:00:00+00:00')));

        self::assertNotNull($signal);
        self::assertSame('2020-09-15T00:00:00+00:00', JsonPath::decoded($signal->data())['released']);
        self::assertStringStartsWith('released 2020-09-15 for PHP 7', $signal->summary());
    }

    public function testAParentDatesALockEntryThatCarriesNoTime(): void
    {
        $package = F::package(['name' => 'symfony/http-foundation', 'version' => 'v4.4.30', 'php' => '>=7.1.3']);

        self::assertNotNull($this->rule()->evaluate(F::facts($package, self::splitMetadata('2020-09-15T00:00:00+00:00'))));
    }

    /** A branch snapshot is code of its commit's day, so that commit's date is the one S5 judges. */
    public function testABranchSnapshotIsJudgedByItsCommitDate(): void
    {
        $signal = $this->rule()->evaluate(F::facts(F::package(['version' => 'dev-master', 'time' => '2019-05-01T00:00:00+00:00', 'php' => '>=7.1']), F::metadata([['1.0.0', '2018-01-01']])));

        self::assertNotNull($signal);
        self::assertStringStartsWith('released 2019-05-01 for PHP 7', $signal->summary());
    }

    public function testASharedCommitDateBeforeTheGaLeavesS5Undecided(): void
    {
        self::assertTrue($this->rule()->missesATrustedDate(F::facts(self::splitPackage(), self::splitMetadata(null))));
    }

    public function testATrustedDateLeavesNothingUndecided(): void
    {
        self::assertFalse($this->rule()->missesATrustedDate(F::facts(self::splitPackage(), self::splitMetadata('2021-08-30T00:00:00+00:00'))), 'the parent dates it');
        self::assertFalse($this->rule()->missesATrustedDate(F::facts(self::splitPackage())), 'without metadata the lock date stands');
        self::assertFalse($this->rule()->missesATrustedDate(F::facts(F::package(['php' => '>=7.1.3', 'time' => '2019-01-01T00:00:00+00:00']))), 'without metadata nothing says the date is a commit\'s');
    }

    public function testNoLockTimeAndNoMetadataLeavesS5Undecided(): void
    {
        self::assertTrue($this->rule()->missesATrustedDate(F::facts(F::package(['php' => '>=7.1.3']))));
    }

    public function testAnUntrustedDateThatS5CouldNotUseLeavesNothingUndecided(): void
    {
        self::assertFalse($this->rule()->missesATrustedDate(F::facts(self::splitPackage(['time' => '2020-11-26T00:00:00+00:00']), self::splitMetadata(null))), 'a shared commit on the GA day: the release came later still');
        self::assertFalse($this->rule()->missesATrustedDate(F::facts(self::splitPackage(['php' => '^7.1.3 || ^8.0']), self::splitMetadata(null))), 'not open-ended for the target');
        self::assertFalse($this->rule('9.0')->missesATrustedDate(F::facts(self::splitPackage(), self::splitMetadata(null))), 'no GA for the target major');
    }

    public function testAnUndatedLockEntryWithoutAParentDateLeavesS5Undecided(): void
    {
        $package = F::package(['name' => 'symfony/http-foundation', 'version' => 'v4.4.30', 'php' => '>=7.1.3']);

        self::assertTrue($this->rule()->missesATrustedDate(F::facts($package, self::splitMetadata(null))));
    }

    public function testABranchSnapshotWithoutADateLeavesNothingUndecided(): void
    {
        self::assertFalse($this->rule()->missesATrustedDate(F::facts(F::package(['version' => 'dev-master', 'php' => '>=7.1']), F::metadata([['1.0.0', '2018-01-01']]))));
    }
}
