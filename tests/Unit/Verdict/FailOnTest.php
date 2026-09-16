<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Exception\ConfigException;
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
        return new Finding('a/pkg', '1.0.0', $verdict, [], $chain, null, new \DateTimeImmutable(self::AT), null, $dev);
    }

    public function testTheAllowedValuesAreNoneTheFlaggedVerdictsAndThePriorities(): void
    {
        self::assertSame(['none', 'abandoned', 'silent', 'pinned', 'old-promise', 'stale', 'critical', 'high', 'medium', 'low'], FailOn::allowed());
        foreach (FailOn::allowed() as $value) {
            self::assertSame($value, FailOn::fromString($value)->value());
        }
    }

    /** @dataProvider rejected */
    #[DataProvider('rejected')]
    public function testAnythingElseIsRejectedWithTheFullList(string $value): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('fail-on must be one of none, abandoned, silent, pinned, old-promise, stale, critical, high, medium, low; got "'.$value.'"');
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
        self::assertFalse($none->isPriority());
        self::assertSame('none', $none->value());
        self::assertFalse($none->reaches(self::finding(Verdict::ABANDONED)));
        self::assertTrue(FailOn::fromString('none')->isNone());
    }

    public function testAVerdictThresholdIsInclusiveAndIgnoresWhereThePackageSits(): void
    {
        $silent = FailOn::fromString(Verdict::SILENT);
        self::assertFalse($silent->isPriority());
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
        self::assertTrue($high->isPriority());
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
