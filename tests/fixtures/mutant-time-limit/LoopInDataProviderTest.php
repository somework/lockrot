<?php

declare(strict_types=1);

namespace Lockrot\Tests\Fixtures\MutantTimeLimit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LoopInDataProviderTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function rows(): iterable
    {
        while (getenv('INFECTION') === '1') {
            usleep(1000);
        }
        yield 'never' => [true];
    }

    /**
     * @dataProvider rows
     */
    #[DataProvider('rows')]
    public function testNeverRuns(bool $value): void
    {
        self::assertTrue($value);
    }
}
