<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\TransitiveExposure;
use Lockrot\Graph\DependencyGraph;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Priority;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class TransitiveExposureTest extends TestCase
{
    /**
     * A small lock: two roots, `root/a` and `root/b`, both reaching `vendor/leaf` (b through
     * `vendor/mid`), `root/b` also reaching `root/a`, and `vendor/lonely` reached by nothing.
     *
     * @param list<string> $dev
     */
    private function graph(array $dev = []): DependencyGraph
    {
        $packages = [
            ['name' => 'root/a', 'version' => '1.0.0', 'require' => ['vendor/leaf' => '^1']],
            ['name' => 'root/b', 'version' => '1.0.0', 'require' => ['vendor/mid' => '^1', 'root/a' => '^1']],
            ['name' => 'vendor/mid', 'version' => '1.0.0', 'require' => ['vendor/leaf' => '^1']],
            ['name' => 'vendor/leaf', 'version' => '1.0.0'],
            ['name' => 'vendor/lonely', 'version' => '1.0.0'],
        ];
        $lock = LockFile::fromArray(['packages' => $packages]);
        $project = ProjectConfig::fromArray(['require' => ['root/a' => '^1', 'root/b' => '^1'], 'require-dev' => array_fill_keys($dev, '^1')]);

        return DependencyGraph::fromLock($lock, $project, $dev !== []);
    }

    /** @param list<Signal> $signals */
    private function finding(DependencyGraph $graph, string $package, string $verdict, array $signals = []): Finding
    {
        return new Finding($package, '1.0.0', $verdict, $signals, $graph->shortestChain($package), null, null, null, false, array_keys($graph->chainsTo($package)));
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<string, Finding>
     */
    private function byName(array $findings): array
    {
        $byName = [];
        foreach ($findings as $finding) {
            $byName[$finding->package()] = $finding;
        }

        return $byName;
    }

    private static function s7(Finding $finding): ?Signal
    {
        foreach ($finding->signals() as $signal) {
            if ($signal->id() === Signal::S7) {
                return $signal;
            }
        }

        return null;
    }

    public function testEveryRootReachingAFlaggedPackageGetsS7WithItsOwnChain(): void
    {
        $graph = $this->graph();
        $stale = new Signal(Signal::S2, Signal::LEVEL_WARN, 'last release 2022-05-20 (4.3 years ago)');
        $findings = [
            $this->finding($graph, 'root/a', Verdict::OK),
            $this->finding($graph, 'root/b', Verdict::OK),
            $this->finding($graph, 'vendor/mid', Verdict::OK),
            $this->finding($graph, 'vendor/leaf', Verdict::STALE, [$stale]),
            $this->finding($graph, 'vendor/lonely', Verdict::ABANDONED),
        ];

        $f = $this->byName(TransitiveExposure::attach($findings, $graph));

        $a = self::s7($f['root/a']);
        self::assertNotNull($a);
        self::assertSame(Signal::LEVEL_INFO, $a->level());
        self::assertSame('pulls in 1 flagged package: vendor/leaf (stale)', $a->summary());
        self::assertSame(
            ['flagged' => 1, 'packages' => [['package' => 'vendor/leaf', 'verdict' => Verdict::STALE, 'chain' => ['root/a', 'vendor/leaf']]]],
            $a->data()
        );
        $b = self::s7($f['root/b']);
        self::assertNotNull($b);
        // The chain is from this parent, not the one the finding's own chain starts from.
        self::assertSame([['package' => 'vendor/leaf', 'verdict' => Verdict::STALE, 'chain' => ['root/b', 'vendor/mid', 'vendor/leaf']]], $b->data()['packages']);
        // Intermediate packages are not parents; nothing reaches vendor/lonely; the leaf itself keeps its signals.
        self::assertNull(self::s7($f['vendor/mid']));
        self::assertNull(self::s7($f['vendor/lonely']));
        self::assertSame([$stale], $f['vendor/leaf']->signals());
    }

    public function testS7IsAppendedAfterThePackagesOwnSignalsAndLeavesTheVerdictAlone(): void
    {
        $graph = $this->graph();
        $own = new Signal(Signal::S6, Signal::LEVEL_WARN, 'pinned to branch snapshot dev-main');
        $findings = [
            $this->finding($graph, 'root/a', Verdict::PINNED, [$own]),
            $this->finding($graph, 'vendor/leaf', Verdict::ABANDONED, [new Signal(Signal::S1, Signal::LEVEL_HIGH, 'marked abandoned by its repository')]),
        ];

        $f = $this->byName(TransitiveExposure::attach($findings, $graph));

        self::assertSame(Verdict::PINNED, $f['root/a']->verdict());
        self::assertSame(Priority::HIGH, $f['root/a']->priority());
        self::assertSame([Signal::S6, Signal::S7], array_map(static fn (Signal $s): string => $s->id(), $f['root/a']->signals()));
        self::assertSame('pinned to branch snapshot dev-main; pulls in 1 flagged package: vendor/leaf (abandoned)', $f['root/a']->evidence());
    }

    public function testAFlaggedDirectPackageIsNobodysExposureEvenWhenAnotherRootReachesIt(): void
    {
        $graph = $this->graph();
        $findings = [
            $this->finding($graph, 'root/a', Verdict::STALE, [new Signal(Signal::S2, Signal::LEVEL_WARN, 'old')]),
            $this->finding($graph, 'root/b', Verdict::OK),
        ];

        self::assertSame(['root/a', 'root/b'], $findings[0]->directDependents());
        self::assertSame($findings, TransitiveExposure::attach($findings, $graph));
    }

    public function testUnflaggedDescendantsDoNotCountAndUntouchedFindingsAreReturnedAsIs(): void
    {
        $graph = $this->graph();
        $findings = [
            $this->finding($graph, 'root/a', Verdict::OK),
            $this->finding($graph, 'vendor/leaf', Verdict::UNKNOWN),
        ];

        self::assertSame($findings, TransitiveExposure::attach($findings, $graph));
    }

    public function testAParentOutsideTheAnalysedSetIsNotInvented(): void
    {
        // The install-time path analyses only the transaction: root/b is in the lock and reaches
        // the leaf, but it is not in the list, so no finding for it appears.
        $graph = $this->graph();
        $findings = [$this->finding($graph, 'vendor/leaf', Verdict::STALE, [new Signal(Signal::S2, Signal::LEVEL_WARN, 'old')])];

        $result = TransitiveExposure::attach($findings, $graph);

        self::assertCount(1, $result);
        self::assertSame(['root/a', 'root/b'], $result[0]->directDependents());
    }

    public function testSummaryNamesFiveDescendantsInReportOrderThenCountsTheRest(): void
    {
        $packages = [['name' => 'root/a', 'version' => '1.0.0', 'require' => []]];
        $findings = [];
        $names = ['vendor/z-abandoned', 'vendor/a-stale', 'vendor/b-stale', 'vendor/c-stale', 'vendor/d-stale', 'vendor/e-stale', 'vendor/f-silent'];
        foreach ($names as $name) {
            $packages[0]['require'][$name] = '^1';
            $packages[] = ['name' => $name, 'version' => '1.0.0'];
        }
        $graph = DependencyGraph::fromLock(LockFile::fromArray(['packages' => $packages]), ProjectConfig::fromArray(['require' => ['root/a' => '^1']]), false);
        $findings[] = $this->finding($graph, 'root/a', Verdict::OK);
        foreach ($names as $name) {
            $verdict = strpos($name, 'abandoned') !== false ? Verdict::ABANDONED : (strpos($name, 'silent') !== false ? Verdict::SILENT : Verdict::STALE);
            $findings[] = $this->finding($graph, $name, $verdict);
        }

        $f = $this->byName(TransitiveExposure::attach($findings, $graph));
        $s7 = self::s7($f['root/a']);
        self::assertNotNull($s7);
        // Report order: priority (abandoned/silent transitive -> high, stale transitive -> low), then severity, then name.
        self::assertSame(
            'pulls in 7 flagged packages: vendor/z-abandoned (abandoned), vendor/f-silent (silent), vendor/a-stale (stale), vendor/b-stale (stale), vendor/c-stale (stale) and 2 more',
            $s7->summary()
        );
        self::assertSame(7, $s7->data()['flagged']);
        $pulledIn = $s7->data()['packages'];
        self::assertIsArray($pulledIn);
        self::assertCount(7, $pulledIn);
    }

    public function testAPackageReachedFromMoreThanTheCapIsNobodysExposure(): void
    {
        $roots = [];
        $packages = [['name' => 'vendor/shared', 'version' => '1.0.0'], ['name' => 'vendor/leaf', 'version' => '1.0.0']];
        for ($i = 1; $i <= TransitiveExposure::MAX_FAN_IN + 1; ++$i) {
            $root = \sprintf('root/r%02d', $i);
            $roots[$root] = '^1';
            // Every root reaches the shared package; only the first also reaches the leaf.
            $packages[] = ['name' => $root, 'version' => '1.0.0', 'require' => $i === 1 ? ['vendor/shared' => '^1', 'vendor/leaf' => '^1'] : ['vendor/shared' => '^1']];
        }
        $graph = DependencyGraph::fromLock(LockFile::fromArray(['packages' => $packages]), ProjectConfig::fromArray(['require' => $roots]), false);
        $stale = new Signal(Signal::S2, Signal::LEVEL_WARN, 'old');
        $findings = [$this->finding($graph, 'vendor/shared', Verdict::STALE, [$stale]), $this->finding($graph, 'vendor/leaf', Verdict::STALE, [$stale])];
        foreach (array_keys($roots) as $root) {
            $findings[] = $this->finding($graph, $root, Verdict::OK);
        }

        self::assertCount(TransitiveExposure::MAX_FAN_IN + 1, $findings[0]->directDependents());
        self::assertFalse(TransitiveExposure::attributable($findings[0]));
        self::assertTrue(TransitiveExposure::attributable($findings[1]));

        $f = $this->byName(TransitiveExposure::attach($findings, $graph));
        $first = self::s7($f['root/r01']);
        self::assertNotNull($first);
        self::assertSame('pulls in 1 flagged package: vendor/leaf (stale)', $first->summary());
        self::assertNull(self::s7($f['root/r02']));
    }

    public function testAttributableRequiresFlaggedAndTransitive(): void
    {
        $graph = $this->graph();
        self::assertTrue(TransitiveExposure::attributable($this->finding($graph, 'vendor/leaf', Verdict::STALE)));
        self::assertFalse(TransitiveExposure::attributable($this->finding($graph, 'vendor/leaf', Verdict::OK)));
        self::assertFalse(TransitiveExposure::attributable($this->finding($graph, 'vendor/leaf', Verdict::UNKNOWN)));
        self::assertFalse(TransitiveExposure::attributable($this->finding($graph, 'root/a', Verdict::STALE)));
        self::assertFalse(TransitiveExposure::attributable($this->finding($graph, 'vendor/lonely', Verdict::STALE)), 'nothing reaches it, so nobody is answerable');
    }

    public function testAPackageReachedFromExactlyAsManyRootsAsTheCapIsStillEveryRootsExposure(): void
    {
        $roots = [];
        $packages = [['name' => 'vendor/shared', 'version' => '1.0.0']];
        for ($i = 1; $i <= TransitiveExposure::MAX_FAN_IN; ++$i) {
            $root = \sprintf('root/r%02d', $i);
            $roots[$root] = '^1';
            $packages[] = ['name' => $root, 'version' => '1.0.0', 'require' => ['vendor/shared' => '^1']];
        }
        $graph = DependencyGraph::fromLock(LockFile::fromArray(['packages' => $packages]), ProjectConfig::fromArray(['require' => $roots]), false);
        $findings = [$this->finding($graph, 'vendor/shared', Verdict::STALE, [new Signal(Signal::S2, Signal::LEVEL_WARN, 'old')])];
        foreach (array_keys($roots) as $root) {
            $findings[] = $this->finding($graph, $root, Verdict::OK);
        }

        self::assertCount(TransitiveExposure::MAX_FAN_IN, $findings[0]->directDependents());
        self::assertTrue(TransitiveExposure::attributable($findings[0]));
        $parents = \array_slice(TransitiveExposure::attach($findings, $graph), 1);
        self::assertCount(TransitiveExposure::MAX_FAN_IN, $parents);
        foreach ($parents as $parent) {
            $s7 = self::s7($parent);
            self::assertNotNull($s7);
            self::assertSame('pulls in 1 flagged package: vendor/shared (stale)', $s7->summary());
        }
    }

    public function testASummaryWithExactlyFiveDescendantsNamesThemAllAndCountsNothing(): void
    {
        $packages = [['name' => 'root/a', 'version' => '1.0.0', 'require' => []]];
        $names = ['vendor/a-stale', 'vendor/b-stale', 'vendor/c-stale', 'vendor/d-stale', 'vendor/e-stale'];
        foreach ($names as $name) {
            $packages[0]['require'][$name] = '^1';
            $packages[] = ['name' => $name, 'version' => '1.0.0'];
        }
        $graph = DependencyGraph::fromLock(LockFile::fromArray(['packages' => $packages]), ProjectConfig::fromArray(['require' => ['root/a' => '^1']]), false);
        $findings = [$this->finding($graph, 'root/a', Verdict::OK)];
        foreach ($names as $name) {
            $findings[] = $this->finding($graph, $name, Verdict::STALE);
        }

        $s7 = self::s7($this->byName(TransitiveExposure::attach($findings, $graph))['root/a']);
        self::assertNotNull($s7);
        self::assertSame(
            'pulls in 5 flagged packages: vendor/a-stale (stale), vendor/b-stale (stale), vendor/c-stale (stale), vendor/d-stale (stale), vendor/e-stale (stale)',
            $s7->summary()
        );
    }
}
