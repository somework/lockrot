<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockFile;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\ThroughCheck;
use PHPUnit\Framework\TestCase;

/**
 * For a package the project does not require, whether each direct dependent's
 * newest stable release still requires it. lockrot reads the `require` names of the metadata it
 * already loaded.
 */
final class ThroughCheckTest extends TestCase
{
    private const NOW = '2026-10-08T00:00:00+00:00';
    private const PACKAGIST = 'https://packagist.org/downloads/';

    public function testADependentWhoseNewestReleaseDropsThePackageIsMeasured(): void
    {
        $entries = self::check(['d/x' => [['1.0.0', '2024-01-01', true], ['2.0.0', '2026-01-01', false]]]);

        self::assertSame([[
            'package' => 'd/x',
            'installed_version' => '1.0.0',
            'installed_requires' => true,
            'newest_stable' => ['version' => '2.0.0', 'released' => '2026-01-01T00:00:00+00:00'],
            'newest_requires' => false,
            'newest_requires_unmeasured' => null,
            'local' => false,
            'newest_is_installed' => false,
        ]], $entries);
    }

    public function testADependentWhoseLockedReleaseIsItsNewestStableReleaseSaysSo(): void
    {
        $entries = self::check(['d/x' => [['1.0.0', '2024-01-01', true]], 'd/y' => null]);

        self::assertSame([true, null], [$entries[0]['newest_is_installed'], $entries[1]['newest_is_installed']]);
    }

    /** The project's own path package: its dependent entry is `local`, and lockrot reads none of its releases. */
    public function testADependentInstalledFromALocalPathIsLocal(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => self::PACKAGIST],
            ['name' => 'app/module', 'version' => 'dev-main', 'dist' => ['type' => 'path', 'url' => './modules/app']],
        ]]);

        $entry = JsonPath::decoded(ThroughCheck::of('a/b', false, ['app/module'], $lock, [])[0]);

        self::assertSame([true, null, 'not_from_composer_repository'], [$entry['local'], $entry['newest_is_installed'], $entry['newest_requires_unmeasured']]);
    }

    public function testADependentWhoseNewestReleaseStillRequiresThePackageIsMeasured(): void
    {
        $entries = self::check(['d/x' => [['1.0.0', '2024-01-01', true], ['2.0.0', '2026-01-01', true]]]);

        self::assertSame([true, true, null], [$entries[0]['installed_requires'], $entries[0]['newest_requires'], $entries[0]['newest_requires_unmeasured']]);
    }

    /** Pre-releases and releases dated after the run clock are not the newest stable release. */
    public function testAReleaseDatedAfterTheRunClockIsNotTheNewest(): void
    {
        $entries = self::check(['d/x' => [['1.0.0', '2024-01-01', true], ['2.0.0', '2027-01-01', false]]]);

        self::assertSame(['1.0.0', true], [JsonPath::stringAt($entries, [0, 'newest_stable', 'version']), $entries[0]['newest_requires']]);
    }

    public function testADependentOutsideEveryRegistryIsNotMeasured(): void
    {
        $entries = self::check(['d/x' => [['1.0.0', '2024-01-01', true]]], ['d/x']);

        self::assertSame([null, null, null, 'not_from_composer_repository'], [$entries[0]['installed_requires'], $entries[0]['newest_stable'], $entries[0]['newest_requires'], $entries[0]['newest_requires_unmeasured']]);
    }

    public function testADependentWhoseMetadataDidNotComeIsNotMeasured(): void
    {
        $entries = self::check(['d/x' => null]);

        self::assertSame([null, 'releases_unknown'], [$entries[0]['newest_requires'], $entries[0]['newest_requires_unmeasured']]);
    }

    /** The installed release is not among the releases read, such as a branch snapshot. */
    public function testADependentWhoseInstalledReleaseIsUnlistedIsNotMeasured(): void
    {
        $entries = self::check(['d/x' => [['1.5.0', '2024-06-01', true]]]);

        self::assertSame([null, 'installed_unlisted'], [$entries[0]['installed_requires'], $entries[0]['newest_requires_unmeasured']]);
    }

    /** The dependent's own `require` says nothing: the package arrives through another one. */
    public function testADependentThatRequiresItThroughAnotherPackageIsNotMeasured(): void
    {
        $entries = self::check(['d/x' => [['1.0.0', '2024-01-01', false], ['2.0.0', '2026-01-01', false]]]);

        self::assertSame([false, null, 'through_other_package'], [$entries[0]['installed_requires'], $entries[0]['newest_requires'], $entries[0]['newest_requires_unmeasured']]);
    }

    public function testADependentWithNoStableReleaseOnTheRunDateIsNotMeasured(): void
    {
        $entries = self::check(['d/x' => [['1.0.0', '2027-01-01', true]]]);

        self::assertSame([true, null, null, 'no_stable_release'], [$entries[0]['installed_requires'], $entries[0]['newest_stable'], $entries[0]['newest_requires'], $entries[0]['newest_requires_unmeasured']]);
    }

    /** A newest release on a commit that other tags share keeps its metadata time: it is out on the run date. */
    public function testANewestReleaseOnASharedCommitIsMeasured(): void
    {
        $versions = [];
        foreach (['1.0.0' => ['a/b' => '^1.0'], '2.0.0' => [], '2.0.1' => [], '2.0.2' => []] as $version => $require) {
            $loaded = (new ArrayLoader())->load(['name' => 'd/x', 'version' => $version, 'time' => '2026-01-01T00:00:00+00:00', 'require' => $require, 'source' => ['type' => 'git', 'url' => 'https://github.com/d/x.git', 'reference' => $version === '1.0.0' ? 'one' : 'shared']]);
            self::assertInstanceOf(BasePackage::class, $loaded);
            $versions[] = $loaded;
        }
        $metadata = PackageMetadata::fromPackages('d/x', $versions, new \DateTimeImmutable(self::NOW), '1.0.0.0');
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => self::PACKAGIST], ['name' => 'd/x', 'version' => '1.0.0', 'notification-url' => self::PACKAGIST]]]);

        $entry = JsonPath::decoded(ThroughCheck::of('a/b', false, ['d/x'], $lock, ['d/x' => $metadata])[0]);

        self::assertSame([false, '2.0.2'], [$entry['newest_requires'], JsonPath::stringAt($entry, ['newest_stable', 'version'])]);
    }

    /** An undated newest release is not known to be out on the run date. */
    public function testAnUndatedNewestReleaseIsNoStableRelease(): void
    {
        $entries = self::check(['d/x' => [['1.0.0', '2024-01-01', true], ['2.0.0', null, false]]]);

        self::assertSame([null, null, 'no_stable_release'], [$entries[0]['newest_stable'], $entries[0]['newest_requires'], $entries[0]['newest_requires_unmeasured']]);
    }

    /** The dependent's `require` names the package in lower case, whatever case the lock gives it. */
    public function testAPackageNameIsMatchedInLowerCase(): void
    {
        $loaded = (new ArrayLoader())->load(['name' => 'd/x', 'version' => '1.0.0', 'time' => '2024-01-01T00:00:00+00:00', 'require' => ['a/b' => '^1.0']]);
        self::assertInstanceOf(BasePackage::class, $loaded);
        $metadata = PackageMetadata::fromPackages('d/x', [$loaded], new \DateTimeImmutable(self::NOW), '1.0.0.0');
        $lock = LockFile::fromArray(['packages' => [['name' => 'A/B', 'version' => '1.0.0', 'notification-url' => self::PACKAGIST], ['name' => 'd/x', 'version' => '1.0.0', 'notification-url' => self::PACKAGIST]]]);

        $entry = JsonPath::decoded(ThroughCheck::of('A/B', false, ['d/x'], $lock, ['d/x' => $metadata])[0]);

        self::assertSame([true, true], [$entry['installed_requires'], $entry['newest_requires']]);
    }

    public function testADirectPackageHasNoEntries(): void
    {
        self::assertSame([], ThroughCheck::of('a/b', true, ['a/b'], LockFile::fromArray(['packages' => []]), []));
    }

    /** The lead move of a transitive package lists the entries, and its `why-not` step asks about the moved package, not the dependent. */
    public function testTheLeadMoveCarriesTheEntriesAndAWhyNotStepOnTheMovedPackage(): void
    {
        $inputs = JsonPath::arrayAt(CaseHydrator::case('psr7'), ['inputs']);
        $inputs['dependents'] = ['guzzlehttp/guzzle' => [
            ['version' => '6.5.8', 'time' => '2022-06-20T22:16:07+00:00', 'require' => ['guzzlehttp/psr7' => '^1.9']],
            ['version' => '8.2.0', 'time' => '2026-09-06T13:55:09+00:00', 'require' => []],
        ]];
        $finding = null;
        foreach (JsonPath::arrayAt(JsonPath::decoded(CaseHydrator::report(['id' => 'psr7', 'inputs' => $inputs])->toArray()), ['findings']) as $row) {
            self::assertIsArray($row);
            $finding = $row['package'] === 'guzzlehttp/psr7' ? $row : $finding;
        }
        self::assertIsArray($finding);
        $move = JsonPath::arrayAt($finding, ['next_step']);

        self::assertSame([false], array_column(JsonPath::arrayAt($move, ['through']), 'newest_requires'));
        self::assertSame([['composer', 'why-not', 'guzzlehttp/psr7', '2.13.0', '--locked']], array_values(array_filter(JsonPath::arrayAt($move, ['commands']), static fn ($command): bool => \is_array($command) && ($command[1] ?? null) === 'why-not')));
    }

    /**
     * One dependent d/x of a/b, through which a/b comes in.
     *
     * @param array<string, list<array{0: string, 1: ?string, 2: bool}>|null> $dependents d/x's releases: version, date or null, and whether it requires a/b, or null for no metadata
     * @param list<string>                                                   $outside    the dependents installed from vcs
     *
     * @return list<array<mixed, mixed>>
     */
    private static function check(array $dependents, array $outside = []): array
    {
        $packages = [['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => self::PACKAGIST]];
        $metadata = [];
        foreach ($dependents as $name => $releases) {
            $packages[] = ['name' => $name, 'version' => '1.0.0'] + (\in_array($name, $outside, true) ? [] : ['notification-url' => self::PACKAGIST]);
            if ($releases === null) {
                continue;
            }
            $versions = [];
            foreach ($releases as [$version, $date, $requires]) {
                $loaded = (new ArrayLoader())->load(['name' => $name, 'version' => $version, 'require' => $requires ? ['a/b' => '^1.0'] : []] + ($date === null ? [] : ['time' => $date.'T00:00:00+00:00']));
                self::assertInstanceOf(BasePackage::class, $loaded);
                $versions[] = $loaded;
            }
            $metadata[$name] = PackageMetadata::fromPackages($name, $versions, new \DateTimeImmutable(self::NOW), '1.0.0.0');
        }
        $entries = [];
        foreach (ThroughCheck::of('a/b', false, array_keys($dependents), LockFile::fromArray(['packages' => $packages]), $metadata) as $entry) {
            $entries[] = JsonPath::decoded($entry);
        }

        return $entries;
    }
}
