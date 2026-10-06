<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Exception\ConfigException;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Priority;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FailOnTest extends TestCase
{
    private const AT = '2026-09-14T00:00:00+00:00';

    /** @param list<string> $chain */
    private static function finding(string $verdict, array $chain = ['a/pkg'], bool $dev = false): Finding
    {
        return (new FindingBuilder())->withPackage('a/pkg')->withVerdict($verdict)->withChain($chain)->withDataDate(new \DateTimeImmutable(self::AT))->withDev($dev)->build();
    }

    /**
     * `unchecked` is neither a verdict nor a priority: it fails on a finding whose check did not
     * run, whatever that finding otherwise says, which is how a pipeline demands a complete run.
     */
    public function testUncheckedFailsOnTheFindingsThatCarryS10(): void
    {
        $threshold = FailOn::fromString(FailOn::UNCHECKED);
        $notChecked = new Signal(Signal::S10, Signal::LEVEL_INFO, 'repository activity not checked', ['unchecked' => [], 'blocks' => ['S3', 'S4']]);
        $ok = (new FindingBuilder())->withPackage('vendor/ok')->withChain(['vendor/ok'])->build();
        $okUnchecked = (new FindingBuilder())->withPackage('vendor/ok')->withSignals([$notChecked])->withChain(['vendor/ok'])->build();
        $abandoned = (new FindingBuilder())->withPackage('vendor/gone')->withVerdict(Verdict::ABANDONED)->withSignals([new Signal(Signal::S1, Signal::LEVEL_HIGH, 'marked abandoned', [])])->withChain(['vendor/gone'])->build();

        self::assertTrue($threshold->reaches($okUnchecked), 'the check behind this ok did not run');
        self::assertFalse($threshold->reaches($ok), 'checked, and nothing was found');
        self::assertFalse($threshold->reaches($abandoned), 'every check that mattered ran; the verdict thresholds are for this');
        self::assertFalse(FailOn::fromString(Verdict::STALE)->reaches($okUnchecked), 'a verdict threshold still reads the verdict');
    }

    public function testTheAllowedValuesAreNoneTheFlaggedVerdictsAndThePriorities(): void
    {
        self::assertSame(['none', 'abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'critical', 'high', 'medium', 'low', 'unchecked'], FailOn::allowed());
        foreach (FailOn::allowed() as $value) {
            self::assertSame($value, FailOn::fromString($value)->value());
        }
    }

    /**
     * `kind()` is the partition allowed() lists in: a report writes it beside `fail_on`, so a reader
     * never has to know which words are verdicts and which are priorities.
     */
    public function testEveryAllowedValueHasTheKindItIsListedAs(): void
    {
        self::assertSame(['none', 'verdict', 'priority', 'unchecked'], FailOn::KINDS);
        $kinds = [];
        foreach (FailOn::allowed() as $value) {
            $kinds[$value] = FailOn::fromString($value)->kind();
        }

        self::assertSame([
            'none' => 'none',
            'abandoned' => 'verdict',
            'silent' => 'verdict',
            'pinned' => 'verdict',
            'left-behind' => 'verdict',
            'old-promise' => 'verdict',
            'stale' => 'verdict',
            'critical' => 'priority',
            'high' => 'priority',
            'medium' => 'priority',
            'low' => 'priority',
            'unchecked' => 'unchecked',
        ], $kinds);
        self::assertSame(FailOn::KIND_NONE, FailOn::none()->kind());
    }

    /** @dataProvider rejected */
    #[DataProvider('rejected')]
    public function testAnythingElseIsRejectedWithTheFullList(string $value): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('fail-on must be one of none, abandoned, silent, pinned, left-behind, old-promise, stale, critical, high, medium, low, unchecked; got "'.$value.'"');
        FailOn::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'a made-up word' => ['dead'];
        yield 'an unflagged verdict' => [Verdict::UNKNOWN];
        yield 'ok' => [Verdict::OK];
        yield 'finished' => [Verdict::FINISHED];
        yield 'the priority none is not a level' => [Priority::NONE.' '];
        yield 'empty' => [''];
        yield 'case matters' => ['High'];
    }

    public function testNoneReachesNothing(): void
    {
        $none = FailOn::none();
        self::assertTrue($none->isNone());
        self::assertSame('none', $none->value());
        self::assertFalse($none->reaches(self::finding(Verdict::ABANDONED)));
        self::assertTrue(FailOn::fromString('none')->isNone());
    }

    public function testAVerdictThresholdIsInclusiveAndIgnoresWhereThePackageSits(): void
    {
        $silent = FailOn::fromString(Verdict::SILENT);
        self::assertFalse($silent->isNone());
        self::assertTrue($silent->reaches(self::finding(Verdict::SILENT)));
        self::assertTrue($silent->reaches(self::finding(Verdict::ABANDONED)));
        self::assertTrue($silent->reaches(self::finding(Verdict::ABANDONED, ['a/root', 'a/pkg'], true)), 'a transitive dev package still reaches a verdict threshold');
        self::assertFalse($silent->reaches(self::finding(Verdict::PINNED)));
        self::assertFalse($silent->reaches(self::finding(Verdict::OK)));
        self::assertTrue(FailOn::fromString(Verdict::STALE)->reaches(self::finding(Verdict::STALE)));
        self::assertFalse(FailOn::fromString(Verdict::STALE)->reaches(self::finding(Verdict::UNKNOWN)));
    }

    public function testAPriorityThresholdIsInclusiveAndReadsThePriority(): void
    {
        $high = FailOn::fromString(Priority::HIGH);
        self::assertFalse($high->isNone());
        self::assertTrue($high->reaches(self::finding(Verdict::ABANDONED)), 'critical reaches high');
        self::assertTrue($high->reaches(self::finding(Verdict::PINNED)), 'high, direct prod');
        self::assertTrue($high->reaches(self::finding(Verdict::ABANDONED, ['a/root', 'a/pkg'])), 'abandoned, transitive prod: high');
        self::assertFalse($high->reaches(self::finding(Verdict::ABANDONED, ['a/root', 'a/pkg'], true)), 'abandoned, transitive dev: medium');
        self::assertFalse($high->reaches(self::finding(Verdict::STALE)), 'stale, direct prod: medium');
        self::assertFalse($high->reaches(self::finding(Verdict::OK)), 'no priority at all');

        self::assertTrue(FailOn::fromString(Priority::LOW)->reaches(self::finding(Verdict::STALE, ['a/root', 'a/pkg'], true)));
        self::assertFalse(FailOn::fromString(Priority::LOW)->reaches(self::finding(Verdict::FINISHED)));
        self::assertTrue(FailOn::fromString(Priority::CRITICAL)->reaches(self::finding(Verdict::SILENT)));
        self::assertFalse(FailOn::fromString(Priority::CRITICAL)->reaches(self::finding(Verdict::SILENT, ['a/root', 'a/pkg'])));
        self::assertTrue(FailOn::fromString(Priority::MEDIUM)->reaches(self::finding(Verdict::STALE)));
        self::assertFalse(FailOn::fromString(Priority::MEDIUM)->reaches(self::finding(Verdict::STALE, ['a/root', 'a/pkg'])));
    }
}
