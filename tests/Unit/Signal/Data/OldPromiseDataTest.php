<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Data;

use Lockrot\Json\JsonWriter;
use Lockrot\Signal\Data\OldPromiseData;
use PHPUnit\Framework\TestCase;

/** The S5 data of paragonie/random_compat v9.99.100 in the BookStack fixture app, with `--dev`. */
final class OldPromiseDataTest extends TestCase
{
    public function testItWritesTheS5KeysInOrder(): void
    {
        $data = new OldPromiseData('8.4', '8.0', '2020-11-26', '>= 7', 7, '2020-10-15T08:29:30+00:00');

        self::assertSame('{"target_php":"8.4","target_major":"8.0","ga_date":"2020-11-26","php_constraint":">= 7","written_for_php":7,"released":"2020-10-15T08:29:30+00:00"}', JsonWriter::encode($data, \JSON_UNESCAPED_SLASHES));
        self::assertSame('8.4', $data->targetPhp());
        self::assertSame('8.0', $data->targetMajor());
        self::assertSame('2020-11-26', $data->gaDate());
        self::assertSame('>= 7', $data->phpConstraint());
        self::assertSame(7, $data->writtenForPhp());
        self::assertSame('2020-10-15T08:29:30+00:00', $data->released());
    }

    public function testAConstraintWithNoLowerMajorWritesANull(): void
    {
        self::assertStringContainsString('"written_for_php":null,', (string) JsonWriter::encode(new OldPromiseData('8.4', '8.0', '2020-11-26', '*', null, '2020-10-15T08:29:30+00:00'), 0));
    }
}
