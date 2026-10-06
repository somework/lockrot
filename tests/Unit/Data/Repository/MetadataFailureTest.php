<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Repository;

use Lockrot\Data\Repository\MetadataFailure;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use PHPUnit\Framework\TestCase;

final class MetadataFailureTest extends TestCase
{
    public function testLockrotsOwnReasonsHaveCodesAndEveryOtherMessageIsAFetchFailure(): void
    {
        self::assertSame(['offline', 'install_time_budget', 'no_versions', 'fetch_failed'], MetadataFailure::REASONS);
        self::assertSame(MetadataFailure::OFFLINE, MetadataFailure::reason(MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON));
        self::assertSame(MetadataFailure::INSTALL_TIME_BUDGET, MetadataFailure::reason(MetadataLoaderInterface::BUDGET_REASON));
        self::assertSame(MetadataFailure::NO_VERSIONS, MetadataFailure::reason(MetadataLoaderInterface::NO_VERSIONS_REASON));
        foreach (['HTTP 503', '', 'offline', ' '.MetadataLoaderInterface::BUDGET_REASON, strtoupper(MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON)] as $message) {
            self::assertSame(MetadataFailure::FETCH_FAILED, MetadataFailure::reason($message), var_export($message, true));
        }
    }

    /** A reason constant added to the loader without a code of its own will read as a fetch failure. */
    public function testEveryReasonTheLoaderDeclaresHasACodeOfItsOwn(): void
    {
        $reasons = [];
        foreach ((new \ReflectionClass(MetadataLoaderInterface::class))->getConstants() as $name => $value) {
            if (substr($name, -\strlen('_REASON')) === '_REASON') {
                self::assertIsString($value);
                $reasons[$name] = MetadataFailure::reason($value);
            }
        }
        self::assertCount(3, $reasons);
        self::assertNotContains(MetadataFailure::FETCH_FAILED, $reasons);
        self::assertSame(array_values(array_unique($reasons)), array_values($reasons), 'one code per reason');
    }
}
