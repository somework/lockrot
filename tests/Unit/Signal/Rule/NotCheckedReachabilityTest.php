<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Lockrot\Clock;
use Lockrot\Data\Forge\RepositoryActivity;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Rule\ArchivedRule;
use Lockrot\Signal\Rule\LeftBehindRule;
use Lockrot\Signal\Rule\NoPushRule;
use Lockrot\Signal\Rule\NoReleaseRule;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * S10 names the signals a missing check blocked, and a name it has no business carrying is a
 * claim the data does not support. Nothing inside the rule can tell: it writes down ids, and a
 * list of ids is right or wrong only against what the rules it names could have done. Asserting
 * the list against a list is worth nothing — both sides then carry the same assumption, which is
 * how `release_dates` came to claim S8 on a branch snapshot, a version that is on no release
 * branch for S8 to measure.
 *
 * So each id S10 returns is asked directly: fill the missing check in with the answer most likely
 * to raise that signal, run that signal's own rule over the same package, and see whether it
 * fires. An id no filling can reach is a lie the finding tells; an id this test has no filling for
 * fails it outright, so a signal added to the list later has to be shown reachable before it can
 * be claimed.
 */
final class NotCheckedReachabilityTest extends TestCase
{
    /** Older than `release-high-years` and `push-high-years` from {@see F::NOW}, whatever they are set to. */
    private const LONG_AGO = '2011-03-04T00:00:00+00:00';
    /** Inside every threshold: a release or a push this recent raises nothing by itself. */
    private const RECENT = '2026-09-01T00:00:00+00:00';

    /**
     * @param PackageFacts $facts a shape {@see NotCheckedRule} raises S10 on
     */
    #[DataProvider('shapes')]
    public function testEverySignalS10NamesCouldHaveFiredHadTheCheckRun(PackageFacts $facts): void
    {
        $signal = (new NotCheckedRule())->evaluate($facts);

        self::assertNotNull($signal, 'the shape is one S10 is raised on, or this test proves nothing');
        $blocks = $signal->data()['blocks'];
        self::assertIsArray($blocks);
        self::assertNotSame([], $blocks);
        foreach ($blocks as $id) {
            self::assertIsString($id);
            self::assertNotNull(
                self::fire($id, $facts),
                $id.' is named as blocked, and the answer most likely to raise it does not'
            );
        }
    }

    /**
     * The other half of the same question: S8 is left out of a snapshot's `blocks` because no
     * release date could have raised it, not because the rule forgot. The same filling on a
     * released version does raise it, which is what makes the omission a fact rather than a bug.
     */
    public function testS8IsLeftOutOfASnapshotBecauseNoDateCouldHaveRaisedIt(): void
    {
        self::assertNull(self::fire(Signal::S8, self::facts('dev-main', self::undated(), self::activity(), null)));
        self::assertNotNull(self::fire(Signal::S8, self::facts('v1.2.0', self::undated(), self::activity(), null)));
    }

    /** @return iterable<string, array{PackageFacts}> */
    public static function shapes(): iterable
    {
        yield 'the activity round did not run' => [self::facts('v1.2.0', self::dated(), null, NotCheckedRule::NO_TOKEN)];
        yield 'the newest releases carry no date' => [self::facts('v1.2.0', self::undated(), self::activity(), null)];
        yield 'the newest releases carry no date, on a branch snapshot' => [self::facts('dev-main', self::undated(), self::activity(), null)];
        yield 'neither check ran' => [self::facts('v1.2.0', self::undated(), null, NotCheckedRule::OFFLINE)];
        yield 'neither check ran, on a branch snapshot' => [self::facts('dev-main', self::undated(), null, NotCheckedRule::OFFLINE)];
    }

    /**
     * The missing check answered the way that most favours $id, over the same package the facts
     * carry, and $id's own rule run over the result. Null is the rule saying no answer of that
     * shape reaches it.
     */
    private static function fire(string $id, PackageFacts $facts): ?Signal
    {
        $package = $facts->package();
        $clock = Clock::fixed(F::NOW);
        $thresholds = new Thresholds();
        switch ($id) {
            case Signal::S3:
                // The repository comes back archived, which is all S3 asks for.
                return (new ArchivedRule())->evaluate(new PackageFacts($package, $facts->metadata(), F::activity(true, self::RECENT), []));
            case Signal::S4:
                // And pushed longer ago than any threshold.
                return (new NoPushRule($clock, $thresholds))->evaluate(new PackageFacts($package, $facts->metadata(), F::activity(false, self::LONG_AGO), []));
            case Signal::S2:
                // Every release dated, and the newest of them older than any threshold.
                return (new NoReleaseRule($clock, $thresholds))->evaluate(new PackageFacts($package, self::datedAs(self::LONG_AGO, self::LONG_AGO), $facts->activity(), []));
            case Signal::S8:
                // Every release dated, the installed branch long quiet and a higher branch releasing.
                return (new LeftBehindRule($clock, $thresholds))->evaluate(new PackageFacts($package, self::datedAs(self::RECENT, self::LONG_AGO), $facts->activity(), []));
        }

        self::fail($id.' is named as blocked and this test knows no answer that would raise it');
    }

    private static function facts(string $version, PackageMetadata $metadata, ?RepositoryActivity $activity, ?string $notChecked): PackageFacts
    {
        return new PackageFacts(F::package(['version' => $version]), $metadata, $activity, [], $notChecked);
    }

    /** A repository that answered, with nothing in the answer to raise a signal of its own. */
    private static function activity(): RepositoryActivity
    {
        return F::activity(false, self::RECENT);
    }

    /** Two branches, the newest release recent: the `release_dates` check ran and found nothing. */
    private static function dated(): PackageMetadata
    {
        return self::datedAs(self::RECENT, self::RECENT);
    }

    /** Two branches whose highest tags carry no date: the shape `undated_releases` names. */
    private static function undated(): PackageMetadata
    {
        return F::metadata([['v2.0.0', null], ['v1.2.0', null]]);
    }

    /** The same two branches, dated — `2.x` by $above, the installed `1.x` by $installed. */
    private static function datedAs(string $above, string $installed): PackageMetadata
    {
        return F::metadata([['v2.0.0', $above], ['v1.2.0', $installed]]);
    }
}
