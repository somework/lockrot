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
 * C08 (SPEC §5.6): for a package the project does not require, whether each direct dependent's
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
        ]], $entries);
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

    public function testADirectPackageHasNoEntries(): void
    {
        self::assertSame([], ThroughCheck::of('a/b', true, ['a/b'], LockFile::fromArray(['packages' => []]), []));
    }

    /** The lead move of a transitive package lists the entries, ends its commands with `why-not`, and its text names the dependent. */
    public function testTheLeadMoveCarriesTheEntriesAndTheWhyNotCommand(): void
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
        self::assertSame(['composer', 'why-not', 'guzzlehttp/guzzle', '8.2.0'], $move['commands'][\count($move['commands']) - 1]);
    }

    /**
     * One dependent d/x of a/b, through which a/b comes in.
     *
     * @param array<string, list<array{0: string, 1: string, 2: bool}>|null> $dependents d/x's releases: version, date, whether it requires a/b; null for no metadata
     * @param list<string>                                                   $outside    the dependents installed from vcs
     *
     * @return list<array<string, mixed>>
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
                $loaded = (new ArrayLoader())->load(['name' => $name, 'version' => $version, 'time' => $date.'T00:00:00+00:00', 'require' => $requires ? ['a/b' => '^1.0'] : []]);
                self::assertInstanceOf(BasePackage::class, $loaded);
                $versions[] = $loaded;
            }
            $metadata[$name] = PackageMetadata::fromPackages($name, $versions, new \DateTimeImmutable(self::NOW), '1.0.0.0');
        }
        $entries = [];
        foreach (ThroughCheck::of('a/b', false, array_keys($dependents), LockFile::fromArray(['packages' => $packages]), $metadata) as $entry) {
            $decoded = JsonPath::decoded($entry);
            self::assertIsArray($decoded);
            $entries[] = $decoded;
        }

        return $entries;
    }
}
