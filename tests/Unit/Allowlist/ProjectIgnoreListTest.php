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

    /** A leap day is a real date and must be accepted. */
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
        // passes it; trim($reason) === '' is the check the schema cannot express, and stays here.
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('reason');
        ProjectIgnoreList::fromExtra(['ignore' => [['package' => 'a/b', 'reason' => '   ']]]);
    }

    /**
     * The schema's pattern only checks the YYYY-MM-DD shape; checkdate() catches a date that looks
     * right but is not a real calendar day, which stays here. "2026-02-30" is the case that needs
     * the day to be read as the day: every month has at least 28 days, so a date the month alone
     * cannot make impossible is the only one that tells the two apart.
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
}
