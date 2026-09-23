<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * S10 is the difference between "checked, nothing found" and "not checked". Every other signal
 * reports an observation; this one reports the absence of one, and names the signals that could
 * not be read without it.
 */
final class NotCheckedRuleTest extends TestCase
{
    private const NOW = '2026-09-14T00:00:00+00:00';

    private function rule(): NotCheckedRule
    {
        return new NotCheckedRule();
    }

    /** Metadata that dates its newest release, so only the activity round can be missing. */
    private static function dated(): PackageMetadata
    {
        return new PackageMetadata('vendor/pkg', false, null, true, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), '1.2.0', 12, null, 'library', new \DateTimeImmutable(self::NOW));
    }

    /** Metadata whose newest tag carries no date lockrot trusts: S2 has nothing to measure. */
    private static function undated(): PackageMetadata
    {
        return new PackageMetadata('vendor/pkg', false, null, true, null, null, 12, null, 'library', new \DateTimeImmutable(self::NOW));
    }

    public function testACheckedRunRaisesNothing(): void
    {
        $facts = new PackageFacts(F::package(), self::dated(), F::activity(false, '2026-09-01T00:00:00+00:00'), []);

        self::assertNull($this->rule()->evaluate($facts));
    }

    public function testASkippedActivityRoundNamesTheSignalsItBlocked(): void
    {
        $facts = new PackageFacts(F::package(), self::dated(), null, [], NotCheckedRule::NO_TOKEN);

        $signal = $this->rule()->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame(Signal::S10, $signal->id());
        self::assertSame(Signal::LEVEL_INFO, $signal->level(), 'it observes nothing, so it warns about nothing');
        self::assertSame('repository activity not checked (no token for the repository host), so S3 and S4 could not be read', $signal->summary());
        self::assertSame([Signal::S3, Signal::S4], $signal->data()['blocks']);
        self::assertSame([['check' => 'repository_activity', 'reason' => NotCheckedRule::NO_TOKEN, 'blocks' => [Signal::S3, Signal::S4]]], $signal->data()['unchecked']);
    }

    /**
     * @dataProvider reasons
     */
    #[DataProvider('reasons')]
    public function testEveryReasonTheRunCanGiveIsWorded(string $reason, string $words): void
    {
        $signal = $this->rule()->evaluate(new PackageFacts(F::package(), self::dated(), null, [], $reason));

        self::assertNotNull($signal);
        self::assertStringContainsString($words, $signal->summary());
        $unchecked = $signal->data()['unchecked'];
        self::assertIsArray($unchecked);
        $first = $unchecked[0];
        self::assertIsArray($first);
        self::assertSame($reason, $first['reason']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function reasons(): iterable
    {
        yield 'no token' => [NotCheckedRule::NO_TOKEN, 'no token for the repository host'];
        yield 'the anonymous budget' => [NotCheckedRule::RATE_BUDGET, 'the anonymous request budget was spent'];
        yield 'the install-time budget' => [NotCheckedRule::BUDGET, 'the install-time budget ran out'];
        yield 'a rate limit' => [NotCheckedRule::RATE_LIMIT, 'too many requests'];
        yield 'a failed request' => [NotCheckedRule::FETCH_FAILED, 'the request failed'];
        yield 'offline' => [NotCheckedRule::OFFLINE, 'the run is offline'];
    }

    public function testAReasonTheRuleDoesNotKnowRaisesNothing(): void
    {
        self::assertNull($this->rule()->evaluate(new PackageFacts(F::package(), self::dated(), null, [], 'made up')));
    }

    public function testAnUndatedNewestReleaseIsTheOtherMissingCheck(): void
    {
        $signal = $this->rule()->evaluate(new PackageFacts(F::package(), self::undated(), F::activity(false, '2026-09-01T00:00:00+00:00'), []));

        self::assertNotNull($signal);
        self::assertSame([Signal::S2, Signal::S8], $signal->data()['blocks']);
        self::assertStringContainsString('the age of the package was not read', $signal->summary());
        $unchecked = $signal->data()['unchecked'];
        self::assertIsArray($unchecked);
        self::assertSame([['check' => 'release_dates', 'reason' => 'undated_releases', 'blocks' => [Signal::S2, Signal::S8]]], $unchecked);
    }

    public function testBothChecksCanBeMissingAtOnce(): void
    {
        $signal = $this->rule()->evaluate(new PackageFacts(F::package(), self::undated(), null, [], NotCheckedRule::OFFLINE));

        self::assertNotNull($signal);
        self::assertSame([Signal::S3, Signal::S4, Signal::S2, Signal::S8], $signal->data()['blocks']);
        $unchecked = $signal->data()['unchecked'];
        self::assertIsArray($unchecked);
        self::assertCount(2, $unchecked);
        self::assertStringContainsString('; ', $signal->summary(), 'one sentence per missing check');
    }

    /**
     * S8 reads the installed version's release branch, and a branch snapshot is on none
     * ({@see \Lockrot\Data\Repository\ReleaseBranch::of()}), so the missing dates block S2 alone.
     * S2 is still worth naming: with S4 it is what turns `pinned` into `silent`.
     */
    public function testASnapshotsMissingDatesBlockTheAgeSignalOnly(): void
    {
        $signal = $this->rule()->evaluate(new PackageFacts(F::package(['version' => 'dev-main']), self::undated(), F::activity(false, '2026-09-01T00:00:00+00:00'), []));

        self::assertNotNull($signal);
        self::assertSame([Signal::S2], $signal->data()['blocks']);
        self::assertSame([['check' => 'release_dates', 'reason' => 'undated_releases', 'blocks' => [Signal::S2]]], $signal->data()['unchecked']);
        self::assertStringContainsString('so S2 could not measure it', $signal->summary());
        self::assertStringNotContainsString('S8', $signal->summary());
    }

    public function testAVersionOnAReleaseBranchBlocksBothAgeSignals(): void
    {
        $signal = $this->rule()->evaluate(new PackageFacts(F::package(['version' => 'v1.2.0']), self::undated(), F::activity(false, '2026-09-01T00:00:00+00:00'), []));

        self::assertNotNull($signal);
        self::assertSame([Signal::S2, Signal::S8], $signal->data()['blocks']);
        self::assertStringContainsString('so S2 and S8 could not measure it', $signal->summary());
    }

    public function testAPackageWithoutMetadataIsNotToldItsAgeWasNotRead(): void
    {
        // Nothing is known about it at all, which is what `unknown` says; the run's notes carry why.
        $signal = $this->rule()->evaluate(new PackageFacts(F::package(), null, F::activity(false, '2026-09-01T00:00:00+00:00'), []));

        self::assertNull($signal);
    }

    public function testAPackageWithNoStableReleaseIsNotToldItsAgeWasNotRead(): void
    {
        // S6 already says the package has no stable release; there is no age to read, not a missing check.
        $metadata = new PackageMetadata('vendor/pkg', false, null, false, null, null, 3, null, 'library', new \DateTimeImmutable(self::NOW));

        self::assertNull($this->rule()->evaluate(new PackageFacts(F::package(), $metadata, F::activity(false, '2026-09-01T00:00:00+00:00'), [])));
    }
}
