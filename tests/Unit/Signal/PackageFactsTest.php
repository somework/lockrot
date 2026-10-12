<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Lockrot\Data\Forge\ActivityLookup;
use Lockrot\Data\Forge\LocatedRepository;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Security\PackageFixes;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class PackageFactsTest extends TestCase
{
    public function testWithFixesReturnsACopyAndKeepsTheFactsItWasCalledOn(): void
    {
        $facts = F::facts(F::package());
        $fixes = new PackageFixes([], [], null, null, null);

        $withFixes = $facts->withFixes($fixes);

        self::assertNotSame($facts, $withFixes);
        self::assertSame($fixes, $withFixes->fixes());
        self::assertNull($facts->fixes());
    }

    public function testWithActivityLookupReturnsACopyAndKeepsTheFactsItWasCalledOn(): void
    {
        $facts = F::facts(F::package());
        $lookup = ActivityLookup::notRead(ActivityLookup::HOST_NOT_SUPPORTED, LocatedRepository::unsupported('codeberg.org', null));

        $withLookup = $facts->withActivityLookup($lookup);

        self::assertSame($lookup, $withLookup->activityLookup());
        self::assertSame(ActivityLookup::HOST_NOT_SUPPORTED, $withLookup->activityNotChecked());
        self::assertNull($facts->activityNotChecked());
        $this->expectException(\LogicException::class);
        $facts->activityLookup();
    }

    public function testFactsBuiltWithAReadAreAnAnonymousRead(): void
    {
        $facts = F::facts(F::package(), null, F::activity(null, '2026-02-01T00:00:00+00:00', RepoRef::GITLAB));

        self::assertSame([ActivityLookup::READ, ActivityLookup::NO_TOKEN], [$facts->activityLookup()->status(), $facts->activityLookup()->archivedUnread()]);
    }
}
