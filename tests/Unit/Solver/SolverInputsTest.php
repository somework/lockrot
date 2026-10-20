<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Lockrot\Solver\SolverInputs;
use PHPUnit\Framework\TestCase;

/** @phpstan-type Spec array{pool: list<BasePackage>, unacceptable: list<BasePackage>, fixed: list<BasePackage>, locked: list<BasePackage>, requires: array<string, ConstraintInterface>} */
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

        return SolverInputs::of(new Pool($spec['pool'], $spec['unacceptable']), $request, $root);
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
