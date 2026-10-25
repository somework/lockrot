<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Data\Repository\StableRelease;
use Lockrot\Verdict\ThroughEntry;
use PHPUnit\Framework\TestCase;

final class ThroughEntryTest extends TestCase
{
    public function testTheNewestIsInstalledOnlyWhenTheLockedReleaseIsTheNewestStableOne(): void
    {
        $entry = ThroughEntry::measured('acme/dependent', '1.0.0', 'acme/lib', ['acme/lib'], new StableRelease('1.1.0.0', '1.1.0', new \DateTimeImmutable('2025-01-01'), null, false), ['acme/lib']);

        self::assertSame(
            [[false, true], [true, false]],
            [[$entry->withInstalled(false, '1.1.0.0')->isLocal(), $entry->withInstalled(false, '1.1.0.0')->newestIsInstalled()], [$entry->withInstalled(true, '1.0.0.0')->isLocal(), $entry->withInstalled(true, '1.0.0.0')->newestIsInstalled()]],
        );
    }

    public function testWithoutADatedNewestStableReleaseNobodyKnowsWhetherTheNewestIsInstalled(): void
    {
        $entry = ThroughEntry::measured('acme/dependent', '1.0.0', 'acme/lib', ['acme/lib'], new StableRelease('1.1.0.0', '1.1.0', null, null, false), ['acme/lib']);

        self::assertNull($entry->withInstalled(false, '1.0.0.0')->newestIsInstalled());
    }

    public function testTheCopyLeavesTheEntryAsItWas(): void
    {
        $entry = ThroughEntry::measured('acme/dependent', '1.0.0', 'acme/lib', ['acme/lib'], new StableRelease('1.0.0.0', '1.0.0', new \DateTimeImmutable('2025-01-01'), null, false), ['acme/lib']);
        $before = [$entry->isLocal(), $entry->newestIsInstalled()];

        $entry->withInstalled(true, '1.0.0.0');

        self::assertSame($before, [$entry->isLocal(), $entry->newestIsInstalled()]);
    }
}
