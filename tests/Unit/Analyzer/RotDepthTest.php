<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Allowlist\Allowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\RotDepth;
use Lockrot\Clock;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Json\Schemas;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class RotDepthTest extends TestCase
{
    use ValidatesJsonSchemas;

    /**
     * A graded finding by default: `stale` scores above 0.
     *
     * @param list<string> $chain
     * @param list<ProjectConfig::REQUIRE|ProjectConfig::REQUIRE_DEV> $namedIn
     */
    private static function finding(string $package, array $chain, array $namedIn, bool $dev = false, string $verdict = Verdict::STALE, bool $composerJsonRead = true): Finding
    {
        return (new FindingBuilder())->withPackage($package)->withVerdict($verdict)->withChain($chain)->withNamedIn($namedIn)->withDev($dev)->withComposerJsonRead($composerJsonRead)->build();
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array{flagged: int, measured: int, unmeasured: array<string, int>, by_reach: array<string, int>, not_in_composer_json: int, require_dev_not_walked: int, production: int, development: int, by_depth: list<array{depth: int, flagged: int}>, max_depth: ?int, deepest: list<array{package: string, chain: list<string>}>}
     */
    private static function block(array $findings): array
    {
        return RotDepth::of($findings)->jsonSerialize();
    }

    public function testNoGradedFindingGivesTheZeroBlock(): void
    {
        self::assertSame([
            'flagged' => 0,
            'measured' => 0,
            'unmeasured' => ['unreached' => 0, 'no_composer_json' => 0],
            'by_reach' => ['direct' => 0, 'transitive' => 0, 'unreached' => 0],
            'not_in_composer_json' => 0,
            'require_dev_not_walked' => 0,
            'production' => 0,
            'development' => 0,
            'by_depth' => [],
            'max_depth' => null,
            'deepest' => [],
        ], self::block([self::finding('ok/one', ['ok/one'], ['require'], false, Verdict::OK)]));
    }

    public function testEveryDepthUpToTheDeepestHasARowZerosIncluded(): void
    {
        $block = self::block([
            self::finding('a/one', ['r/a', 'a/one'], []),
            self::finding('b/two', ['r/b', 'b/two'], []),
            self::finding('c/three', ['r/c', 'x/x', 'y/y', 'c/three'], []),
            self::finding('d/four', ['r/d', 'x/x', 'y/y', 'd/four'], []),
        ]);

        self::assertSame([['depth' => 0, 'flagged' => 0], ['depth' => 1, 'flagged' => 2], ['depth' => 2, 'flagged' => 0], ['depth' => 3, 'flagged' => 2]], $block['by_depth']);
        self::assertSame(3, $block['max_depth']);
        self::assertSame(4, $block['measured']);
    }

    public function testAnUnreachedFindingCountsByReachAndNotByDepth(): void
    {
        $block = self::block([self::finding('lost/one', [], []), self::finding('a/direct', ['a/direct'], ['require'])]);

        self::assertSame(['direct' => 1, 'transitive' => 0, 'unreached' => 1], $block['by_reach']);
        self::assertSame(['unreached' => 1, 'no_composer_json' => 0], $block['unmeasured']);
        self::assertSame(1, $block['not_in_composer_json']);
        self::assertSame([['depth' => 0, 'flagged' => 1]], $block['by_depth']);
        self::assertSame([], $block['deepest'], 'the deepest are only below depth 0');
    }

    /** The case `lock-only‡`: the run read no composer.json. */
    public function testALockOnlyRunCountsEveryGradedFindingUnderNoComposerJson(): void
    {
        $block = self::block([self::finding('a/one', [], [], false, Verdict::STALE, false), self::finding('b/two', [], [], false, Verdict::PINNED, false)]);

        self::assertSame(['unreached' => 0, 'no_composer_json' => 2], $block['unmeasured']);
        self::assertSame(0, $block['not_in_composer_json']);
        self::assertSame(0, $block['measured']);
        self::assertSame(2, $block['flagged']);
    }

    public function testARequireDevNameReachedThroughAProductionPackageIsNotWalked(): void
    {
        $withoutDev = self::block([self::finding('dev/tool', ['app/core', 'dev/tool'], ['require-dev'])]);
        $withDev = self::block([self::finding('dev/tool', ['dev/tool'], ['require-dev'], true)]);

        self::assertSame(1, $withoutDev['require_dev_not_walked']);
        self::assertSame(0, $withoutDev['development'], 'a production package');
        self::assertSame(0, $withDev['require_dev_not_walked']);
        self::assertSame([1, 0], [$withDev['development'], $withDev['production']]);
    }

    public function testTheDeepestAreSortedBytewiseEachWithItsChain(): void
    {
        $block = self::block([
            self::finding('b/deep', ['r/one', 'x/x', 'b/deep'], []),
            self::finding('B/deep', ['r/two', 'y/y', 'B/deep'], []),
            self::finding('a/deep', ['r/one', 'z/z', 'a/deep'], []),
            self::finding('c/shallow', ['r/one', 'c/shallow'], []),
        ]);

        self::assertSame([['package' => 'B/deep', 'chain' => ['r/two', 'y/y', 'B/deep']], ['package' => 'a/deep', 'chain' => ['r/one', 'z/z', 'a/deep']], ['package' => 'b/deep', 'chain' => ['r/one', 'x/x', 'b/deep']]], $block['deepest']);
    }

    public function testUnreachedFindingsAloneHaveNoDeepest(): void
    {
        $block = self::block([self::finding('lost/one', [], []), self::finding('lost/two', [], [], true)]);

        self::assertSame([null, [], [], 1], [$block['max_depth'], $block['deepest'], $block['by_depth'], $block['development']]);
    }

    public function testOnlyGradedFindingsCount(): void
    {
        $block = self::block([
            self::finding('a/graded', ['a/graded'], ['require']),
            self::finding('f/finished', ['r/r', 'x/1', 'x/2', 'x/3', 'x/4', 'f/finished'], [], false, Verdict::FINISHED),
        ]);

        self::assertSame(1, $block['flagged']);
        self::assertSame(0, $block['max_depth']);
    }

    /** The parts of the partition add up to `flagged`, as do the reaches and the two kinds of package. */
    public function testThePartitionsAddUpToTheGradedFindings(): void
    {
        $block = self::block([
            self::finding('a/direct', ['a/direct'], ['require']),
            self::finding('b/deep', ['a/direct', 'b/deep'], []),
            self::finding('c/dev', ['a/direct', 'c/dev'], ['require-dev']),
            self::finding('d/lost', [], []),
            self::finding('e/dev-direct', ['e/dev-direct'], ['require-dev'], true),
        ]);

        self::assertSame($block['flagged'], $block['by_reach']['direct'] + $block['require_dev_not_walked'] + $block['not_in_composer_json'] + $block['unmeasured']['no_composer_json']);
        self::assertSame($block['flagged'], array_sum($block['by_reach']));
        self::assertSame($block['flagged'], $block['production'] + $block['development']);
        self::assertSame($block['flagged'], $block['measured'] + array_sum($block['unmeasured']));
        self::assertSame($block['by_reach']['direct'], $block['by_depth'][0]['flagged']);
    }

    /**
     * composer.json requires ocramius/proxy-manager and the lock holds friendsofphp/proxy-manager-lts,
     * which replaces it: the replacer is named in `require`, direct, and counted once.
     */
    public function testAReplacerOfARequiredNameIsDirectAndNamedInRequire(): void
    {
        $lock = LockFile::fromArray(['packages' => [
            ['name' => 'friendsofphp/proxy-manager-lts', 'version' => 'v1.0.18', 'time' => '2019-01-01T00:00:00+00:00', 'replace' => ['ocramius/proxy-manager' => '^2.1'], 'notification-url' => 'https://packagist.org/downloads/'],
        ]]);
        $project = ProjectConfig::fromArray(['require' => ['ocramius/proxy-manager' => '^2.1']]);
        $clock = Clock::fixed('2026-09-14T00:00:00+00:00');
        $analyzer = new Analyzer(self::loader(), new ActivityClient(self::http(), ForgeAuth::anonymous()), new ActivityFetchPlanner(ForgeAuth::anonymous()), new RepoLocator(), new Allowlist([]), SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()), $clock, false);
        $report = $analyzer->analyze($lock, $project, false);
        $document = JsonPath::decoded($report->toArray());
        $finding = JsonPath::arrayAt($document, ['findings', 0]);

        self::assertSame([['require'], 0, null, 'direct'], [$finding['named_in'], $finding['depth'], $finding['depth_unmeasured'], $finding['reach']]);
        self::assertSame($report->findings()[0]->isGraded() ? 1 : 0, JsonPath::arrayAt($document, ['rot_depth', 'by_reach'])['direct']);
        self::assertSame(0, JsonPath::arrayAt($document, ['rot_depth'])['not_in_composer_json']);
        $this->assertValid(Schemas::REPORT, (new JsonFormatter())->format($report), 'the replace fixture');
    }

    private static function loader(): MetadataLoaderInterface
    {
        return new class () implements MetadataLoaderInterface {
            public function load(array $installedByName): MetadataBatch
            {
                return new MetadataBatch([], [], []);
            }
        };
    }

    private static function http(): HttpClientInterface
    {
        return new class () implements HttpClientInterface {
            public function fetchAll(array $urls, array $headers = []): array
            {
                return [];
            }
        };
    }
}
