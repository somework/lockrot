<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Repository;

use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Data\Repository\PackageMetadata;
use PHPUnit\Framework\TestCase;

final class PackageMetadataFromPackagesTest extends TestCase
{
    private const FIXED = '2026-09-14T00:00:00+00:00';

    /** @param array<string, mixed> $config */
    private function load(array $config): CompletePackage
    {
        $loader = new ArrayLoader();
        $package = $loader->load($config);
        self::assertInstanceOf(CompletePackage::class, $package);

        return $package;
    }

    public function testAbandonedTrueWithNoReplacement(): void
    {
        $version = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'abandoned' => true]);

        $meta = PackageMetadata::fromPackages('a/b', [$version], new \DateTimeImmutable(self::FIXED));

        self::assertTrue($meta->isAbandoned());
        self::assertNull($meta->replacement());
    }

    public function testAbandonedStringReplacement(): void
    {
        $version = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'abandoned' => 'c/d']);

        $meta = PackageMetadata::fromPackages('a/b', [$version], new \DateTimeImmutable(self::FIXED));

        self::assertTrue($meta->isAbandoned());
        self::assertSame('c/d', $meta->replacement());
    }

    /**
     * Packagist repeats `abandoned` on every version entry of a package, and a rename can change
     * which package it points at. The first entry seen names the replacement, so the report says
     * one thing rather than whichever one the repository happened to list last.
     */
    public function testTheFirstAbandonedVersionSeenNamesTheReplacement(): void
    {
        $newest = $this->load(['name' => 'a/b', 'version' => '2.0.0', 'abandoned' => 'c/current']);
        $older = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'abandoned' => 'c/superseded']);

        $meta = PackageMetadata::fromPackages('a/b', [$newest, $older], new \DateTimeImmutable(self::FIXED));

        self::assertTrue($meta->isAbandoned());
        self::assertSame('c/current', $meta->replacement());
    }

    /** Two releases stamped with the same time: the first one seen stands, so the answer is stable. */
    public function testTwoReleasesWithTheSameTimeKeepTheFirstOneSeen(): void
    {
        $at = '2020-01-01T00:00:00+00:00';
        $first = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'time' => $at]);
        $second = $this->load(['name' => 'a/b', 'version' => '1.0.1', 'time' => $at]);

        self::assertSame('1.0.0', PackageMetadata::fromPackages('a/b', [$first, $second], new \DateTimeImmutable(self::FIXED))->lastStableVersion());
        self::assertSame('1.0.1', PackageMetadata::fromPackages('a/b', [$second, $first], new \DateTimeImmutable(self::FIXED))->lastStableVersion());
    }

    public function testTheHighestReleasePerBranchAndItsDateAreKept(): void
    {
        $v1a = $this->load(['name' => 'a/b', 'version' => '1.8.0', 'time' => '2019-01-01T00:00:00+00:00']);
        $v1b = $this->load(['name' => 'a/b', 'version' => '1.9.2', 'time' => '2019-06-01T00:00:00+00:00']);
        $v2 = $this->load(['name' => 'a/b', 'version' => 'v2.3.0', 'time' => '2024-01-01T00:00:00+00:00']);
        $v0 = $this->load(['name' => 'a/b', 'version' => '0.9.1']);
        $dev = $this->load(['name' => 'a/b', 'version' => 'dev-main']);

        $meta = PackageMetadata::fromPackages('a/b', [$v1b, $v2, $v1a, $v0, $dev], new \DateTimeImmutable(self::FIXED));

        $byBranch = $meta->latestStableByBranch();
        self::assertSame(['1', '2', '0.9'], array_map('strval', array_keys($byBranch)));
        self::assertSame('1.9.2', $byBranch['1']['version']);
        self::assertNotNull($byBranch['1']['at']);
        self::assertSame('2019-06-01T00:00:00+00:00', $byBranch['1']['at']->format(\DATE_ATOM));
        self::assertSame('v2.3.0', $byBranch['2']['version']);
        self::assertSame('0.9.1', $byBranch['0.9']['version']);
        self::assertNull($byBranch['0.9']['at']);
    }

    /** php-http/promise: 1.3.1 (2024-03) is the highest 1.x tag, 1.2.2 (2025-11) its last release. */
    public function testTheNewestDatedReleaseOnABranchWinsOverAHigherOlderOne(): void
    {
        $higherButOlderDate = $this->load(['name' => 'a/b', 'version' => '1.5.0', 'time' => '2020-01-01T00:00:00+00:00']);
        $lowerButNewerDate = $this->load(['name' => 'a/b', 'version' => '1.4.9', 'time' => '2021-01-01T00:00:00+00:00']);

        foreach ([[$lowerButNewerDate, $higherButOlderDate], [$higherButOlderDate, $lowerButNewerDate]] as $order) {
            $branch = PackageMetadata::fromPackages('a/b', $order, new \DateTimeImmutable(self::FIXED))->latestStableByBranch()['1'];
            self::assertSame('1.4.9', $branch['version']);
            self::assertNotNull($branch['at']);
            self::assertSame('2021-01-01T00:00:00+00:00', $branch['at']->format(\DATE_ATOM));
        }
    }

    /** As for the package's last release: two releases on one branch with the same time keep the first one seen. */
    public function testTwoReleasesOnABranchWithTheSameTimeKeepTheFirstOneSeen(): void
    {
        $first = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'time' => '2021-01-01T00:00:00+00:00']);
        $second = $this->load(['name' => 'a/b', 'version' => '1.0.1', 'time' => '2021-01-01T00:00:00+00:00']);

        self::assertSame('1.0.0', PackageMetadata::fromPackages('a/b', [$first, $second], new \DateTimeImmutable(self::FIXED))->latestStableByBranch()['1']['version']);
        self::assertSame('1.0.1', PackageMetadata::fromPackages('a/b', [$second, $first], new \DateTimeImmutable(self::FIXED))->latestStableByBranch()['1']['version']);
    }

    public function testTheBranchsHighestTagTravelsNextToItsNewestRelease(): void
    {
        $higherButOlderDate = $this->load(['name' => 'a/b', 'version' => 'v1.5.0', 'time' => '2020-01-01T00:00:00+00:00']);
        $lowerButNewerDate = $this->load(['name' => 'a/b', 'version' => '1.4.9', 'time' => '2021-01-01T00:00:00+00:00']);

        foreach ([[$lowerButNewerDate, $higherButOlderDate], [$higherButOlderDate, $lowerButNewerDate]] as $order) {
            $branch = PackageMetadata::fromPackages('a/b', $order, new \DateTimeImmutable(self::FIXED))->latestStableByBranch()['1'];
            self::assertSame('1.4.9', $branch['version']);
            self::assertSame('1.5.0.0', $branch['highest']['normalized'], 'normalized, as Composer compares it');
            self::assertSame('v1.5.0', $branch['highest']['pretty'], 'as the repository prints it, for the evidence');
            self::assertNotNull($branch['highest']['at']);
            self::assertSame('2020-01-01T00:00:00+00:00', $branch['highest']['at']->format(\DATE_ATOM));
        }
    }

    /** illuminate/macroable 10.x on Packagist: 112 of 115 tags carry no time. The branch keeps its newest dated release, but the highest tag says it is undated. */
    public function testAnUndatedHighestTagIsReportedAsSuchNextToTheNewestDatedRelease(): void
    {
        $undatedHighest = $this->load(['name' => 'a/b', 'version' => 'v10.49.0']);
        $dated = $this->load(['name' => 'a/b', 'version' => 'v10.13.1', 'time' => '2023-03-17T13:33:11+00:00']);

        foreach ([[$undatedHighest, $dated], [$dated, $undatedHighest]] as $order) {
            $branch = PackageMetadata::fromPackages('a/b', $order, new \DateTimeImmutable(self::FIXED))->latestStableByBranch()['10'];
            self::assertSame('v10.13.1', $branch['version']);
            self::assertSame('10.49.0.0', $branch['highest']['normalized']);
            self::assertNull($branch['highest']['at']);
        }
    }

    /**
     * A repository that sends `version_normalized` is trusted on it, and ArrayLoader never parses
     * `version`: whatever it says must not reach a parser here.
     */
    public function testAPrettyVersionTheParserRejectsIsNeverParsed(): void
    {
        $one = $this->load(['name' => 'a/b', 'version' => 'release-one', 'version_normalized' => '1.0.0.0']);
        $two = $this->load(['name' => 'a/b', 'version' => 'release-two', 'version_normalized' => '1.0.1.0']);

        $branch = PackageMetadata::fromPackages('a/b', [$one, $two], new \DateTimeImmutable(self::FIXED))->latestStableByBranch()['1'];

        self::assertSame('release-two', $branch['version']);
        self::assertSame('1.0.1.0', $branch['highest']['normalized']);
        self::assertSame('release-two', $branch['highest']['pretty']);
        self::assertNull($branch['at']);
    }

    /** `^0.0.3` is `>=0.0.3 <0.0.4`: a recent 0.0.4 is another branch and must not stand in for the installed 0.0.3's. */
    public function testBelowZeroPointOneEveryPatchIsItsOwnBranch(): void
    {
        $three = $this->load(['name' => 'a/b', 'version' => '0.0.3', 'time' => '2021-01-01T00:00:00+00:00']);
        $four = $this->load(['name' => 'a/b', 'version' => '0.0.4', 'time' => '2026-01-01T00:00:00+00:00']);

        $byBranch = PackageMetadata::fromPackages('a/b', [$four, $three], new \DateTimeImmutable(self::FIXED))->latestStableByBranch();

        self::assertSame(['0.0.4', '0.0.3'], array_keys($byBranch));
        self::assertSame('0.0.3', $byBranch['0.0.3']['version']);
        self::assertSame('0.0.4.0', $byBranch['0.0.4']['highest']['normalized']);
    }

    public function testAnUndatedHigherTagNeverHidesADatedReleaseOnTheBranch(): void
    {
        $undatedHigher = $this->load(['name' => 'a/b', 'version' => '1.5.0']);
        $dated = $this->load(['name' => 'a/b', 'version' => '1.4.9', 'time' => '2021-01-01T00:00:00+00:00']);

        foreach ([[$undatedHigher, $dated], [$dated, $undatedHigher]] as $order) {
            $branch = PackageMetadata::fromPackages('a/b', $order, new \DateTimeImmutable(self::FIXED))->latestStableByBranch()['1'];
            self::assertSame('1.4.9', $branch['version']);
            self::assertNotNull($branch['at']);
        }
    }

    public function testABranchWithNoDatesKeepsItsHighestTagUndated(): void
    {
        $lower = $this->load(['name' => 'a/b', 'version' => '1.4.9']);
        $higher = $this->load(['name' => 'a/b', 'version' => '1.5.0']);

        foreach ([[$lower, $higher], [$higher, $lower]] as $order) {
            $branch = PackageMetadata::fromPackages('a/b', $order, new \DateTimeImmutable(self::FIXED))->latestStableByBranch()['1'];
            self::assertSame('1.5.0', $branch['version']);
            self::assertNull($branch['at']);
        }
    }

    /** sabre/dav: a 3.3.0-alpha1 must not become branch 3's newest release, nor open a branch of its own. */
    public function testPreReleasesDoNotShapeABranch(): void
    {
        $stable = $this->load(['name' => 'a/b', 'version' => '3.2.3', 'time' => '2021-06-01T00:00:00+00:00']);
        $alpha = $this->load(['name' => 'a/b', 'version' => '3.3.0-alpha1', 'time' => '2021-02-01T00:00:00+00:00']);
        $nextMajorBeta = $this->load(['name' => 'a/b', 'version' => '4.0.0-beta1', 'time' => '2024-01-01T00:00:00+00:00']);

        $meta = PackageMetadata::fromPackages('a/b', [$nextMajorBeta, $alpha, $stable], new \DateTimeImmutable(self::FIXED));

        self::assertSame(['3'], array_map('strval', array_keys($meta->latestStableByBranch())));
        self::assertSame('3.2.3', $meta->latestStableByBranch()['3']['version']);
        self::assertSame('4.0.0-beta1', $meta->lastStableVersion(), 'S2 keeps counting every tag');
    }

    public function testDevOnlyHasNoStableRelease(): void
    {
        $version = $this->load(['name' => 'a/b', 'version' => 'dev-master']);

        $meta = PackageMetadata::fromPackages('a/b', [$version], new \DateTimeImmutable(self::FIXED));

        self::assertFalse($meta->hasStableRelease());
        self::assertNull($meta->lastStableReleaseAt());
        self::assertNull($meta->lastStableVersion());
        self::assertSame(1, $meta->releaseCount());
    }

    public function testStableOrderingPicksGreatestReleaseDate(): void
    {
        $older = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'time' => '2020-01-01T00:00:00+00:00']);
        $newer = $this->load(['name' => 'a/b', 'version' => '2.0.0', 'time' => '2024-05-01T00:00:00+00:00']);
        $devMaster = $this->load(['name' => 'a/b', 'version' => 'dev-master', 'time' => '2025-01-01T00:00:00+00:00']);

        $meta = PackageMetadata::fromPackages('a/b', [$older, $newer, $devMaster], new \DateTimeImmutable(self::FIXED));

        self::assertTrue($meta->hasStableRelease());
        $at = $meta->lastStableReleaseAt();
        self::assertNotNull($at);
        self::assertInstanceOf(\DateTimeImmutable::class, $at);
        self::assertSame('2024-05-01', $at->format('Y-m-d'));
        self::assertSame('2.0.0', $meta->lastStableVersion());
        self::assertSame(3, $meta->releaseCount());
    }

    public function testNoDatesLeavesLastStableNull(): void
    {
        $version = $this->load(['name' => 'a/b', 'version' => '1.0.0']);

        $meta = PackageMetadata::fromPackages('a/b', [$version], new \DateTimeImmutable(self::FIXED));

        self::assertTrue($meta->hasStableRelease());
        self::assertNull($meta->lastStableReleaseAt());
        self::assertNull($meta->lastStableVersion());
    }

    public function testTypeComesFromTheFirstVersionAndTheRepositoryFromTheHighest(): void
    {
        $first = $this->load([
            'name' => 'a/b',
            'version' => '1.0.0',
            'type' => 'symfony-bundle',
            'source' => ['type' => 'git', 'url' => 'https://example.test/old/b.git', 'reference' => 'abc'],
        ]);
        $second = $this->load(['name' => 'a/b', 'version' => '2.0.0', 'type' => 'library', 'source' => ['type' => 'git', 'url' => 'https://example.test/a/b.git', 'reference' => 'abc']]);

        $meta = PackageMetadata::fromPackages('a/b', [$first, $second], new \DateTimeImmutable(self::FIXED));

        self::assertSame('https://example.test/a/b.git', $meta->repositoryUrl());
        self::assertSame('symfony-bundle', $meta->type());
    }

    /**
     * The phpstan/phpstan shape: the newest releases carry no `source`, `support.source` names the
     * live repository, and a few old releases point at a one-off build repository that has since
     * been archived. The old repository must not be the one asked.
     */
    public function testTheNewestReleasesSupportSourceOutranksAnOlderReleasesSource(): void
    {
        $newest = $this->load([
            'name' => 'phpstan/phpstan',
            'version' => '2.2.14',
            'time' => '2026-09-10T00:00:00+00:00',
            'support' => ['source' => 'https://github.com/phpstan/phpstan-src'],
        ]);
        $old = $this->load([
            'name' => 'phpstan/phpstan',
            'version' => '2.1.29',
            'time' => '2025-09-30T00:00:00+00:00',
            'source' => ['type' => 'git', 'url' => 'https://github.com/phpstan/phpstan-phar-composer-source.git', 'reference' => 'abc'],
        ]);

        $meta = PackageMetadata::fromPackages('phpstan/phpstan', [$newest, $old], new \DateTimeImmutable(self::FIXED));

        self::assertSame('https://github.com/phpstan/phpstan-src', $meta->repositoryUrl());
    }

    /** A renamed repository: the highest release names the new home, older ones the old. */
    public function testTheHighestReleasesSourceWinsWhateverTheOrder(): void
    {
        $old = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'time' => '2019-01-01T00:00:00+00:00', 'source' => ['type' => 'git', 'url' => 'https://github.com/Old/Name.git', 'reference' => 'a']]);
        $new = $this->load(['name' => 'a/b', 'version' => '3.0.0', 'time' => '2026-01-01T00:00:00+00:00', 'source' => ['type' => 'git', 'url' => 'https://github.com/new/name.git', 'reference' => 'b']]);

        self::assertSame('https://github.com/new/name.git', PackageMetadata::fromPackages('a/b', [$old, $new], new \DateTimeImmutable(self::FIXED))->repositoryUrl());
        self::assertSame('https://github.com/new/name.git', PackageMetadata::fromPackages('a/b', [$new, $old], new \DateTimeImmutable(self::FIXED))->repositoryUrl());
    }

    public function testTheHighestReleasesSourceOutranksItsSupportSource(): void
    {
        $newest = $this->load([
            'name' => 'a/b',
            'version' => '2.0.0',
            'time' => '2026-01-01T00:00:00+00:00',
            'source' => ['type' => 'git', 'url' => 'https://github.com/a/b.git', 'reference' => 'a'],
            'support' => ['source' => 'https://github.com/a/b-src'],
        ]);

        self::assertSame('https://github.com/a/b.git', PackageMetadata::fromPackages('a/b', [$newest], new \DateTimeImmutable(self::FIXED))->repositoryUrl());
    }

    /** An older release's source is never substituted: a package whose highest release names no repository has none here. */
    public function testAnOlderReleasesSourceIsNotSubstituted(): void
    {
        $newest = $this->load(['name' => 'a/b', 'version' => '2.0.0', 'time' => '2026-01-01T00:00:00+00:00', 'support' => ['issues' => 'https://github.com/a/b/issues']]);
        $old = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'time' => '2019-01-01T00:00:00+00:00', 'source' => ['type' => 'git', 'url' => 'https://github.com/a/b.git', 'reference' => 'a']]);

        self::assertNull(PackageMetadata::fromPackages('a/b', [$old, $newest], new \DateTimeImmutable(self::FIXED))->repositoryUrl());
    }

    /**
     * The release is picked by version: neither a `time` (optional, and mixed up in hand-written
     * repositories) nor the list order (Packagist newest first, others unspecified) decides.
     */
    public function testTheHighestVersionIsTheAnchorWhateverTheDatesOrTheOrder(): void
    {
        $undated = $this->load(['name' => 'a/b', 'version' => '2.0.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/a/new.git', 'reference' => 'a']]);
        $dated = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'time' => '2020-01-01T00:00:00+00:00', 'source' => ['type' => 'git', 'url' => 'https://github.com/a/old-archived.git', 'reference' => 'a']]);
        $lts = $this->load(['name' => 'a/b', 'version' => '1.0.1', 'time' => '2026-01-01T00:00:00+00:00', 'source' => ['type' => 'git', 'url' => 'https://github.com/a/old-archived.git', 'reference' => 'a']]);

        self::assertSame('https://github.com/a/new.git', PackageMetadata::fromPackages('a/b', [$dated, $lts, $undated], new \DateTimeImmutable(self::FIXED))->repositoryUrl(), 'a later LTS patch does not move the anchor');
    }

    /**
     * The package's age is its highest tag's. With that tag undated, "last release 2026-01-01" would
     * date the 1.x LTS patch and say nothing about 2.0.0, which may be a day or five years old: the
     * age is unknown and S2 stays quiet. A dated highest tag reads as before, whatever is undated below it.
     */
    public function testAnUndatedHighestTagLeavesThePackagesLastReleaseUnknown(): void
    {
        $undated = $this->load(['name' => 'a/b', 'version' => '2.0.0']);
        $lts = $this->load(['name' => 'a/b', 'version' => '1.0.1', 'time' => '2026-01-01T00:00:00+00:00']);
        $datedHighest = $this->load(['name' => 'a/b', 'version' => '2.0.1', 'time' => '2026-02-01T00:00:00+00:00']);

        $unknown = PackageMetadata::fromPackages('a/b', [$lts, $undated], new \DateTimeImmutable(self::FIXED));
        self::assertTrue($unknown->hasStableRelease());
        self::assertNull($unknown->lastStableReleaseAt());
        self::assertNull($unknown->lastStableVersion());

        $known = PackageMetadata::fromPackages('a/b', [$lts, $undated, $datedHighest], new \DateTimeImmutable(self::FIXED));
        self::assertSame('2.0.1', $known->lastStableVersion());
    }

    /** An empty `source` URL is no repository; the release's `support.source` is read instead, and a mistyped one is nothing. */
    public function testAnEmptySourceUrlFallsBackToSupportSourceAndAMistypedSupportSourceToNothing(): void
    {
        $empty = new CompletePackage('a/b', '1.0.0.0', '1.0.0');
        $empty->setSourceUrl('');
        $empty->setSupport(['source' => 'https://github.com/a/b']);
        self::assertSame('https://github.com/a/b', PackageMetadata::fromPackages('a/b', [$empty], new \DateTimeImmutable(self::FIXED))->repositoryUrl());

        // ArrayLoader hands `support` over unvalidated, so a mistyped value reaches the package object.
        $mistyped = $this->load(['name' => 'a/b', 'version' => '1.0.0', 'support' => ['source' => ['url' => 'https://github.com/a/b']]]);
        self::assertNull(PackageMetadata::fromPackages('a/b', [$mistyped], new \DateTimeImmutable(self::FIXED))->repositoryUrl());
    }

    /** Packagist's default `support.source` is a `/tree/<version>` page; the repository is what is kept. */
    public function testAPackagistDefaultSupportSourceIsReducedToTheRepository(): void
    {
        $newest = $this->load(['name' => 'phpunit/phpunit', 'version' => '13.3.4', 'support' => ['source' => 'https://github.com/sebastianbergmann/phpunit/tree/13.3.4']]);

        self::assertSame('https://github.com/sebastianbergmann/phpunit', PackageMetadata::fromPackages('phpunit/phpunit', [$newest], new \DateTimeImmutable(self::FIXED))->repositoryUrl());
    }

    public function testADevOnlyPackageIsReadFromItsFirstBranch(): void
    {
        $branch = $this->load(['name' => 'a/b', 'version' => 'dev-main', 'support' => ['source' => 'https://github.com/a/b']]);
        $other = $this->load(['name' => 'a/b', 'version' => 'dev-legacy', 'source' => ['type' => 'git', 'url' => 'https://github.com/a/legacy.git', 'reference' => 'a']]);

        self::assertSame('https://github.com/a/b', PackageMetadata::fromPackages('a/b', [$branch, $other], new \DateTimeImmutable(self::FIXED))->repositoryUrl());
        self::assertNull(PackageMetadata::fromPackages('a/b', [], new \DateTimeImmutable(self::FIXED))->repositoryUrl());
    }

    public function testTypeDefaultsToLibrary(): void
    {
        $version = $this->load(['name' => 'a/b', 'version' => '1.0.0']);

        $meta = PackageMetadata::fromPackages('a/b', [$version], new \DateTimeImmutable(self::FIXED));

        self::assertSame('library', $meta->type());
    }

    public function testDataDateEqualsGivenClockValue(): void
    {
        $version = $this->load(['name' => 'a/b', 'version' => '1.0.0']);
        $dataDate = new \DateTimeImmutable(self::FIXED);

        $meta = PackageMetadata::fromPackages('a/b', [$version], $dataDate);

        self::assertSame(self::FIXED, $meta->dataDate()->format(\DATE_ATOM));
    }
}
