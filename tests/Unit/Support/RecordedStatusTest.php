<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use Lockrot\Tests\Support\RecordedStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecordedStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{int, bool, bool}>
     */
    public static function answers(): iterable
    {
        yield 'a 200' => [200, false, true];
        yield 'a 404' => [404, false, true];
        yield 'a GitHub 200' => [200, true, true];
        yield 'a GitHub 404' => [404, true, true];
        yield 'a transport failure' => [0, false, false];
        yield 'a GitHub transport failure' => [0, true, false];
        yield 'a GitHub 403, its rate limit' => [403, true, false];
        yield 'a 403 of a repository that refuses anonymous access' => [403, false, true];
        yield 'a 429' => [429, false, false];
        yield 'a GitHub 429' => [429, true, false];
        yield 'a 500' => [500, false, false];
        yield 'a 503' => [503, false, false];
        yield 'a 599' => [599, false, false];
    }

    /**
     * @dataProvider answers
     */
    #[DataProvider('answers')]
    public function testOnlyAnAnswerThatARerunGetsAgainIsKept(int $status, bool $github, bool $kept): void
    {
        self::assertSame($kept, RecordedStatus::keeps($status, $github));
    }

    public function testAnEnvelopeOnDiskCountsAsRecordedOnlyWithAKeptStatus(): void
    {
        $dir = sys_get_temp_dir().'/lockrot-recorded-status-'.bin2hex(random_bytes(8));
        mkdir($dir);
        try {
            file_put_contents($dir.'/ok.json', '{"status":200,"body":"{}"}');
            file_put_contents($dir.'/limited.json', '{"status":403,"body":"{}"}');
            file_put_contents($dir.'/down.json', '{"status":503,"body":null}');
            file_put_contents($dir.'/broken.json', 'not json');

            self::assertSame(
                [true, true, false, false, false, false],
                [
                    RecordedStatus::isRecorded($dir.'/ok.json', true),
                    RecordedStatus::isRecorded($dir.'/limited.json', false),
                    RecordedStatus::isRecorded($dir.'/limited.json', true),
                    RecordedStatus::isRecorded($dir.'/down.json', false),
                    RecordedStatus::isRecorded($dir.'/broken.json', false),
                    RecordedStatus::isRecorded($dir.'/missing.json', false),
                ],
            );
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }
}
