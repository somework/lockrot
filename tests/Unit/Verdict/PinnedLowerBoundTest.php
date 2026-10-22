<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Clock;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\FixFinder;
use Lockrot\Security\LinkIndex;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpCap;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Latest;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveBuilder;
use Lockrot\Verdict\MoveContext;
use Lockrot\Verdict\MoveDraft;
use Lockrot\Verdict\Moves;
use Lockrot\Verdict\MoveSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The score without `pinned` is a lower bound when a tag can be left behind on the row the tag move lands on. */
final class PinnedLowerBoundTest extends TestCase
{
    private const NOW = '2026-10-08T00:00:00+00:00';

    /** @return iterable<string, array{array<string, string>, bool}> */
    public static function lines(): iterable
    {
        yield 'its own line stopped while a higher line releases' => [['4.0.0' => '2020-01-01', '5.0.0' => '2026-06-01'], true];
        yield 'its own line still releases' => [['4.0.0' => '2026-06-01', '5.0.0' => '2026-07-01'], false];
    }

    /**
     * An alias snapshot of 4.x that also carries `abandoned`, so the finding has a row without `pinned`.
     *
     * @param array<string, string> $releases version => release date
     *
     * @dataProvider lines
     */
    #[DataProvider('lines')]
    public function testThePinnedRowIsALowerBoundExactlyWhenTheTagMoveCanBeLeftBehind(array $releases, bool $atLeast): void
    {
        self::assertSame($atLeast, self::pinnedRowAtLeast(self::releases($releases), false, null));
    }

    /** The security move beside the lead can be the tag move: its row counts as the lead's would. */
    public function testATagMoveInAlsoMakesThePinnedRowALowerBound(): void
    {
        self::assertTrue(self::pinnedRowAtLeast(self::releases(['4.0.0' => '2020-01-01', '5.0.0' => '2026-06-01']), true, null));
    }

    /** A higher branch without a date leaves S8's rule unverified on the tag's row. */
    public function testAnUndatedHigherBranchMakesThePinnedRowALowerBound(): void
    {
        self::assertTrue(self::pinnedRowAtLeast(self::releases(['4.0.0' => '2020-01-01', '5.0.0' => null]), false, null));
    }

    /** `capped` on the tag's row is no left-behind risk: only S8's rule makes the row a lower bound. */
    public function testATagMoveThatAddsOnlyCappedLeavesThePinnedRowExact(): void
    {
        $atLeast = self::pinnedRowAtLeast(self::releases(['4.0.0' => '2026-06-01', '5.0.0' => '2026-07-01'], ['4.0.0' => '>=7.4, <8.5']), false, new PhpCap(['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5']));

        self::assertFalse($atLeast);
    }

    /**
     * @param array<string, ?string> $dates version => release date, null for an undated release
     * @param array<string, string>  $php   version => the php that it requires
     *
     * @return list<BasePackage>
     */
    private static function releases(array $dates, array $php = []): array
    {
        $packages = [];
        foreach ($dates as $version => $date) {
            $release = (new ArrayLoader())->load(['name' => 'a/b', 'version' => (string) $version]
                + ($date === null ? [] : ['time' => $date.'T00:00:00+00:00'])
                + (isset($php[$version]) ? ['require' => ['php' => $php[$version]]] : []));
            self::assertInstanceOf(BasePackage::class, $release);
            $packages[] = $release;
        }

        return $packages;
    }

    /**
     * `score.without[pinned].at_least` of a/b at 4.x-dev with `abandoned` and `pinned`, after the tag
     * move to 4.0.0 on the 4.x row: the lead itself, or the security move beside an `abandoned` lead.
     *
     * @param list<BasePackage> $packages
     */
    private static function pinnedRowAtLeast(array $packages, bool $inAlso, ?PhpCap $cap): bool
    {
        $signals = [new Signal(Signal::LEVEL_HIGH, 'marked', SignalDataStub::of(Signal::S1, ['marked_by' => 'lock'])), new Signal(Signal::LEVEL_WARN, 'pinned', SignalDataStub::of(Signal::S6, ['reason' => 'branch_snapshot', 'version' => '4.x-dev']))];
        $finding = (new FindingBuilder())->withPackage('a/b')->withVersion('4.x-dev')->withSignals($signals)->withFlags(FlagSet::fromSignals($signals, null, []))->build();
        $lock = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '4.x-dev', 'notification-url' => 'https://packagist.org/downloads/']]]);
        $locked = $lock->find('a/b');
        self::assertNotNull($locked);
        $metadata = PackageMetadata::fromPackages('a/b', $packages, new \DateTimeImmutable(self::NOW), '4.x-dev');
        $floor = new PhpFloor('8.4');
        $rows = BranchRow::all(new PackageFacts($locked, $metadata, null, []), $floor, Clock::fixed(self::NOW));
        $own = array_values(array_filter($rows, static fn (BranchRow $row): bool => $row->branch() === '4.x'));
        self::assertCount(1, $own);
        $context = new MoveContext($finding, new MoveSubject('a/b', '4.x-dev', true, [], '8.4', null), $metadata, $rows, null, new FixFinder($floor, LinkIndex::of($lock, ProjectConfig::empty())), $floor, new \DateTimeImmutable(self::NOW), 3, null, [], [], true);
        $context = $cap === null ? $context : $context->withPhpCap($cap);
        $tag = MoveBuilder::build(MoveDraft::of(Move::TAG, FlagSet::PINNED)->withLatest(new Latest('4.0.0', '2020-01-01T00:00:00+00:00', false, null, null), $own[0]), [], null, $context);
        $lead = $inAlso ? MoveBuilder::build(MoveDraft::of(Move::NO_MOVE, FlagSet::ABANDONED)->withReason('package_quiet'), [], null, $context)->asLead([], [$tag]) : $tag;

        $without = JsonPath::arrayAt(JsonPath::decoded($finding->withMoves(new Moves($lead, null, null, null, []))->toArray()), ['score', 'without']);
        self::assertSame(FlagSet::PINNED, JsonPath::stringAt($without, [1, 'remove', 'id']));

        return JsonPath::boolAt($without, [1, 'at_least']);
    }
}
