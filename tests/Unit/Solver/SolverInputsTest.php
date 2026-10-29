<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Config;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Lockrot\Solver\AdvisoryBlocking;
use Lockrot\Solver\PoolAdvisories;
use Lockrot\Solver\PoolAdvisoryFeed;
use Lockrot\Solver\SolverInputs;
use PHPUnit\Framework\TestCase;

/** @phpstan-type Spec array{pool: list<BasePackage>, unacceptable: list<BasePackage>, fixed: list<BasePackage>, locked: list<BasePackage>, requires: array<string, ConstraintInterface>, removed?: list<BasePackage>, blocking?: AdvisoryBlocking, records?: array<string, list<PartialSecurityAdvisory>>} */
final class SolverInputsTest extends TestCase
{
    public function testTwoCopiesOfTheRootWithTheSamePoolAndRequestGiveTheSameInputs(): void
    {
        $lib = self::package('acme/lib', '1.0.0');
        $locked = self::package('acme/locked', '1.0.0');
        $php = self::package('php', '8.4.99');
        $first = self::package('acme/app', '1.0.0', RootPackage::class);
        $second = self::package('acme/app', '1.0.0', RootPackage::class);

        self::assertSame(
            self::inputs(['pool' => [$first, $lib], 'unacceptable' => [], 'fixed' => [$first, $php], 'locked' => [$locked], 'requires' => ['acme/lib' => self::constraint('^1.0')]], $first),
            self::inputs(['pool' => [$second, $lib], 'unacceptable' => [], 'fixed' => [$second, $php], 'locked' => [$locked], 'requires' => ['acme/lib' => self::constraint('^1.0')]], $second)
        );
    }

    /** A constraint keeps the bounds that it computed once read: they are no input of the solver. */
    public function testAConstraintWhoseBoundsWereReadGivesTheSameInputs(): void
    {
        $root = self::package('acme/app', '1.0.0', RootPackage::class);
        $spec = ['pool' => [$root], 'unacceptable' => [], 'fixed' => [$root], 'locked' => []];
        $read = self::constraint('^1.0');
        $read->getLowerBound();

        self::assertSame(self::inputs($spec + ['requires' => ['acme/lib' => self::constraint('^1.0')]], $root), self::inputs($spec + ['requires' => ['acme/lib' => $read]], $root));
    }

    /**
     * @dataProvider changes
     *
     * @param \Closure(Spec, BasePackage): Spec $change
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('changes')]
    public function testInputsDifferWhenOneThingThatTheSolverReadsDiffers(\Closure $change): void
    {
        $root = self::package('acme/app', '1.0.0', RootPackage::class);
        $spec = ['pool' => [$root, self::package('acme/lib', '1.0.0')], 'unacceptable' => [self::package('acme/old', '1.0.0')], 'fixed' => [$root, self::package('php', '8.4.99')], 'locked' => [self::package('acme/locked', '1.0.0')], 'requires' => ['acme/lib' => self::constraint('^1.0')]];

        self::assertNotSame(self::inputs($spec, $root), self::inputs($change($spec, $root), $root));
    }

    /** @return iterable<string, array{\Closure}> */
    public static function changes(): iterable
    {
        yield 'another package of the same name and version in the pool' => [static fn (array $spec, BasePackage $root): array => ['pool' => [$root, self::package('acme/lib', '1.0.0')]] + $spec];
        yield 'another unacceptable package of the same name and version' => [static fn (array $spec, BasePackage $root): array => ['unacceptable' => [self::package('acme/old', '1.0.0')]] + $spec];
        yield 'another fixed package of the same name and version' => [static fn (array $spec, BasePackage $root): array => ['fixed' => [$root, self::package('php', '8.4.99')]] + $spec];
        yield 'another locked package' => [static fn (array $spec, BasePackage $root): array => ['locked' => [self::package('acme/locked', '1.0.0')]] + $spec];
        yield 'another constraint written the same' => [static fn (array $spec, BasePackage $root): array => ['requires' => ['acme/lib' => self::constraint('^2.0', '^1.0')]] + $spec];
        yield 'the same constraint written another way' => [static fn (array $spec, BasePackage $root): array => ['requires' => ['acme/lib' => self::constraint('1.*')]] + $spec];
        yield 'a version that the advisory filter removed' => [static fn (array $spec, BasePackage $root): array => ['removed' => [self::package('acme/lib', '1.1.0')], 'blocking' => AdvisoryBlocking::everyAdvisory(), 'records' => ['acme/lib' => [self::advisory('acme/lib', '==1.1.0')]]] + $spec];
        yield 'a version that the abandoned filter removed' => [static fn (array $spec, BasePackage $root): array => ['removed' => [self::abandoned('acme/lib', '1.1.0')], 'blocking' => self::abandonedBlocking()] + $spec];
    }

    /**
     * @param Spec $spec
     */
    private static function inputs(array $spec, BasePackage $root): string
    {
        $request = new Request();
        foreach ($spec['fixed'] as $package) {
            $request->fixPackage($package);
        }
        foreach ($spec['locked'] as $package) {
            $request->lockPackage($package);
        }
        foreach ($spec['requires'] as $name => $constraint) {
            $request->requireName($name, $constraint);
        }

        $pool = new Pool(array_merge($spec['pool'], $spec['removed'] ?? []), $spec['unacceptable']);
        $filter = isset($spec['blocking']) ? $spec['blocking']->poolFilter() : null;
        if ($filter !== null) {
            $records = $spec['records'] ?? [];
            $pool = $filter->filter($pool, [new PoolAdvisoryFeed(new PoolAdvisories($spec['blocking'], static fn (array $names): array => array_intersect_key($records, array_flip($names))))], $request);
        }

        return SolverInputs::of($pool, $request, $root);
    }

    private static function abandonedBlocking(): AdvisoryBlocking
    {
        $config = new Config(false);
        $config->merge(['config' => ['audit' => ['block-abandoned' => true]]]);

        return AdvisoryBlocking::fromConfig($config);
    }

    private static function abandoned(string $name, string $version): BasePackage
    {
        $package = self::package($name, $version);
        self::assertInstanceOf(CompletePackage::class, $package);
        $package->setAbandoned(true);

        return $package;
    }

    private static function advisory(string $name, string $affected): PartialSecurityAdvisory
    {
        return PartialSecurityAdvisory::create($name, ['advisoryId' => 'PKSA-aaaa-bbbb-cccc', 'affectedVersions' => $affected, 'title' => 'A flaw', 'sources' => [['name' => 'GitHub', 'remoteId' => 'GHSA-aaaa-bbbb-cccc']], 'reportedAt' => '2026-01-01 00:00:00', 'cve' => null, 'link' => null, 'severity' => 'high'], new VersionParser());
    }

    private static function constraint(string $constraint, ?string $written = null): ConstraintInterface
    {
        $parsed = (new VersionParser())->parseConstraints($constraint);
        if ($written !== null) {
            $parsed->setPrettyString($written);
        }

        return $parsed;
    }

    /** @param class-string<CompletePackage> $class */
    private static function package(string $name, string $version, string $class = CompletePackage::class): BasePackage
    {
        $package = (new ArrayLoader())->load(['name' => $name, 'version' => $version], $class);
        self::assertInstanceOf(BasePackage::class, $package);

        return $package;
    }
}
