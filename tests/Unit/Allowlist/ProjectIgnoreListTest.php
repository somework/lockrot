<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Allowlist;

use Lockrot\Allowlist\ProjectIgnoreList;
use Lockrot\Exception\ConfigException;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectIgnoreListTest extends TestCase
{
    public function testParsesEntries(): void
    {
        $list = ProjectIgnoreList::fromExtra(['ignore' => [
            ['package' => 'acme/legacy', 'reason' => 'replaced in Q4', 'expires' => '2027-01-31'],
            ['package' => 'acme/*', 'reason' => 'in-house'],
        ]]);
        $entry = $list->match(F::package(['name' => 'acme/legacy']), null, new \DateTimeImmutable(F::NOW));
        self::assertNotNull($entry);
        self::assertSame('replaced in Q4', $entry->reason());
        $expires = $entry->expires();
        self::assertNotNull($expires);
        self::assertSame(
            '2027-01-31T23:59:59+00:00',
            $expires->format(\DATE_ATOM),
            'a date-only "expires" covers the whole of that day in UTC, whatever the machine timezone'
        );
        self::assertSame('project', $entry->source());
    }

    public function testALeapDayIsAcceptedAsAnExpiryDate(): void
    {
        $list = ProjectIgnoreList::fromExtra(['ignore' => [
            ['package' => 'a/b', 'reason' => 'until the fix lands', 'expires' => '2024-02-29'],
        ]]);

        $entry = $list->entries()[0];
        $expires = $entry->expires();
        self::assertNotNull($expires);
        self::assertSame('2024-02-29T23:59:59+00:00', $expires->format(\DATE_ATOM));
    }

    public function testEmptyExtra(): void
    {
        self::assertNull(ProjectIgnoreList::fromExtra([])->match(F::package(), null, new \DateTimeImmutable(F::NOW)));
    }

    public function testMissingReasonThrows(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('reason');
        ProjectIgnoreList::fromExtra(['ignore' => [['package' => 'acme/legacy']]]);
    }

    public function testWhitespaceOnlyReasonThrows(): void
    {
        // The schema's minLength only counts characters, so a reason that is only whitespace
        // passes it. trim($reason) === '' is the check the schema cannot express.
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('reason');
        ProjectIgnoreList::fromExtra(['ignore' => [['package' => 'a/b', 'reason' => '   ']]]);
    }

    /**
     * The schema's pattern only checks the YYYY-MM-DD shape. checkdate() catches a date that looks
     * right but is not a real calendar day. "2026-02-30" tells the two apart: every month has at
     * least 28 days, so the month alone cannot make it impossible.
     *
     * @dataProvider impossibleDates
     */
    #[DataProvider('impossibleDates')]
    public function testCalendarInvalidDateThrows(string $expires): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('a/b');
        ProjectIgnoreList::fromExtra(['ignore' => [['package' => 'a/b', 'reason' => 'x', 'expires' => $expires]]]);
    }

    /** @return iterable<string, array{string}> */
    public static function impossibleDates(): iterable
    {
        yield 'no such month and no such day' => ['2026-13-45'];
        yield 'a day past the end of that month' => ['2026-02-30'];
        yield 'a leap day in a non-leap year' => ['2026-02-29'];
    }

    public function testIgnoreNotAnArrayThrows(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('extra.lockrot.ignore');
        ProjectIgnoreList::fromExtra(['ignore' => 'acme/legacy']);
    }

    public function testStringEntryThrows(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('ignore[0]');
        ProjectIgnoreList::fromExtra(['ignore' => ['acme/legacy']]);
    }

    public function testMissingPackageThrows(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('ignore[0]');
        ProjectIgnoreList::fromExtra(['ignore' => [['reason' => 'because']]]);
    }

    public function testEmptyPackageThrows(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('ignore[0]');
        ProjectIgnoreList::fromExtra(['ignore' => [['package' => '', 'reason' => 'because']]]);
    }

    public function testBadExpiresShapeThrows(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('ignore[0]');
        ProjectIgnoreList::fromExtra(['ignore' => [['package' => 'a/b', 'reason' => 'x', 'expires' => 'soon']]]);
    }

    public function testNonStringVersionThrows(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('ignore[0]');
        ProjectIgnoreList::fromExtra(['ignore' => [['package' => 'a/b', 'reason' => 'x', 'version' => 123]]]);
    }

    public function testAnEntryKeepsEachListedFlagOnceInItsOrder(): void
    {
        $list = ProjectIgnoreList::fromExtra(['ignore' => [['package' => 'acme/legacy', 'reason' => 'kept', 'flags' => ['stale', 'pinned', 'stale']]]]);
        $entry = $list->match(F::package(['name' => 'acme/legacy']), null, new \DateTimeImmutable(F::NOW));

        self::assertNotNull($entry);
        self::assertSame(['stale', 'pinned'], $entry->flags());
    }

    public function testAnEntryReadFromExtraRefusesAnAdvisoryFlag(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("extra.lockrot.ignore[0] \"flags\": the allowlist accepts maintenance flags; accept advisories in Composer's audit policy");

        ProjectIgnoreList::fromExtra(['ignore' => [['package' => 'acme/legacy', 'reason' => 'kept', 'flags' => ['vulnerable']]]]);
    }

    public function testAnEmptyFlagsListIsRefusedAfterAnEntryWithout(): void
    {
        $refusals = ProjectIgnoreList::flagRefusals([['package' => 'acme/a', 'reason' => 'r'], ['package' => 'acme/b', 'reason' => 'r', 'flags' => []]]);

        self::assertSame([1], array_keys($refusals));
        self::assertSame('extra.lockrot.ignore[1] "flags" must list at least one maintenance flag', $refusals[1]->getMessage());
    }

    /** config-1 read any `flags` value, so a 0.13 config can hold one flag as a string. */
    public function testFlagsThatAreNoArrayNameTheArrayForm(): void
    {
        $refusals = ProjectIgnoreList::flagRefusals([['package' => 'acme/a', 'reason' => 'r', 'flags' => 'stale']]);

        self::assertSame([0], array_keys($refusals));
        self::assertSame('extra.lockrot.ignore[0] "flags" must be an array of maintenance flags, such as ["stale"]', $refusals[0]->getMessage());
    }

    public function testEveryEntryWithBadFlagsIsRefused(): void
    {
        $refusals = ProjectIgnoreList::flagRefusals([
            ['package' => 'acme/a', 'reason' => 'r', 'flags' => ['stale', 'dead']],
            ['package' => 'acme/b', 'reason' => 'r', 'flags' => ['stale']],
            ['package' => 'acme/c', 'reason' => 'r', 'flags' => 'stale'],
        ]);

        self::assertSame([0, 2], array_keys($refusals));
        self::assertSame('extra.lockrot.ignore[0] "flags" must list maintenance flags only: abandoned, silent, pinned, left-behind, old-promise, stale, capped', $refusals[0]->getMessage());
    }

    public function testALongIndexIsCutAt255Bytes(): void
    {
        $refusals = ProjectIgnoreList::flagRefusals([str_repeat('k', 300) => ['flags' => []]]);

        self::assertSame(['extra.lockrot.ignore['.str_repeat('k', 255).'…] "flags" must list at least one maintenance flag'], array_map(static fn (ConfigException $e): string => $e->getMessage(), array_values($refusals)));
    }
}
