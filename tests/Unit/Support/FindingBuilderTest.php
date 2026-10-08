<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\Origins;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FindingBuilderTest extends TestCase
{
    public function testTheDefaultsAreTheFindingWrittenByHand(): void
    {
        $byHand = new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/pkg'], null, null);
        $built = (new FindingBuilder())->withoutFlags()->build();

        self::assertEquals($byHand, $built);
    }

    public function testWithoutItsFlagsAFindingTakesThemFromItsSignalsOrItsVerdict(): void
    {
        self::assertSame(['stale'], (new FindingBuilder())->withVerdict(Verdict::STALE)->build()->flagIds());
        self::assertSame('stale', (new FindingBuilder())->withVerdict(Verdict::STALE)->build()->lead());
        self::assertSame(['old-promise'], (new FindingBuilder())->withVerdict(Verdict::STALE)->withSignals([new Signal(Signal::S5, Signal::LEVEL_WARN, 'old')])->build()->flagIds());
        self::assertSame('ok', (new FindingBuilder())->build()->grade());
        self::assertSame('unknown', (new FindingBuilder())->withVerdict(Verdict::UNKNOWN)->build()->grade());
        self::assertSame('finished', (new FindingBuilder())->withVerdict(Verdict::STALE)->withAllowlistReason('kept')->build()->grade());
    }

    /**
     * The builder that with*() was called on keeps its own value.
     *
     * @param \Closure(FindingBuilder): FindingBuilder $with
     *
     * @dataProvider oneFieldEach
     */
    #[DataProvider('oneFieldEach')]
    public function testEachWithChangesOneFieldOnly(\Closure $with, Finding $expected): void
    {
        $builder = (new FindingBuilder())->withoutFlags();
        $changed = $with($builder);
        self::assertNotSame($builder, $changed);
        $built = $changed->build();

        self::assertEquals($expected, $built);
        self::assertEquals(new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/pkg'], null, null), $builder->build());
    }

    /** @return iterable<string, array{\Closure(FindingBuilder): FindingBuilder, Finding}> */
    public static function oneFieldEach(): iterable
    {
        $signals = [new Signal(Signal::S2, Signal::LEVEL_WARN, 'old')];
        $at = new \DateTimeImmutable('2026-09-14T00:00:00+00:00');
        $libyears = LibyearsMeasurement::of(2.74);
        $origin = Origins::of(false);
        $flags = FlagSet::fromSignals($signals, null, []);

        yield 'package' => [static fn (FindingBuilder $b): FindingBuilder => $b->withPackage('acme/other'), new Finding('acme/other', '1.0.0', Verdict::OK, [], ['vendor/pkg'], null, null)];
        yield 'version' => [static fn (FindingBuilder $b): FindingBuilder => $b->withVersion('2.3.4'), new Finding('vendor/pkg', '2.3.4', Verdict::OK, [], ['vendor/pkg'], null, null)];
        yield 'verdict' => [static fn (FindingBuilder $b): FindingBuilder => $b->withVerdict(Verdict::STALE), new Finding('vendor/pkg', '1.0.0', Verdict::STALE, [], ['vendor/pkg'], null, null)];
        yield 'signals' => [static fn (FindingBuilder $b): FindingBuilder => $b->withSignals($signals), new Finding('vendor/pkg', '1.0.0', Verdict::OK, $signals, ['vendor/pkg'], null, null)];
        yield 'chain' => [static fn (FindingBuilder $b): FindingBuilder => $b->withChain(['vendor/root', 'vendor/pkg']), new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/root', 'vendor/pkg'], null, null)];
        yield 'allowlist reason' => [static fn (FindingBuilder $b): FindingBuilder => $b->withAllowlistReason('interfaces'), new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/pkg'], 'interfaces', null)];
        yield 'data date' => [static fn (FindingBuilder $b): FindingBuilder => $b->withDataDate($at), new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/pkg'], null, $at)];
        yield 'note' => [static fn (FindingBuilder $b): FindingBuilder => $b->withNote('a note'), new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/pkg'], null, null, 'a note')];
        yield 'dev' => [static fn (FindingBuilder $b): FindingBuilder => $b->withDev(true), new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/pkg'], null, null, null, true)];
        yield 'direct dependents' => [static fn (FindingBuilder $b): FindingBuilder => $b->withDirectDependents(['vendor/pkg']), new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/pkg'], null, null, null, false, ['vendor/pkg'])];
        yield 'libyears' => [static fn (FindingBuilder $b): FindingBuilder => $b->withLibyears($libyears), new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/pkg'], null, null, null, false, [], $libyears)];
        yield 'origin' => [static fn (FindingBuilder $b): FindingBuilder => $b->withOrigin($origin), new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/pkg'], null, null, null, false, [], null, $origin)];
        yield 'replacement named by' => [static fn (FindingBuilder $b): FindingBuilder => $b->withReplacementNamedBy(PackageOrigin::PACKAGIST), new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/pkg'], null, null, null, false, [], null, null, PackageOrigin::PACKAGIST)];
        yield 'flags' => [static fn (FindingBuilder $b): FindingBuilder => $b->withFlags($flags, false), (new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/pkg'], null, null))->withFlags($flags, false)->withDetails(FindingBuilder::detailsOf(new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/pkg'], null, null), false))];
    }
}
