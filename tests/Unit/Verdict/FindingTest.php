<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class FindingTest extends TestCase
{
    public function testEvidenceAndArray(): void
    {
        $signals = [
            new Signal('S2', 'high', 'last release 2015-11-16 (10.8 years ago)', ['years' => 10.8]),
            new Signal('S4', 'high', 'last push 2015-11-16 (10.8 years ago)', ['years' => 10.8]),
        ];
        $finding = new Finding('phpzip/phpzip', '2.0.8', Verdict::SILENT, $signals, ['wallabag/wallabag', 'phpzip/phpzip'], null, new \DateTimeImmutable('2026-09-14T10:00:00+00:00'));
        self::assertSame('last release 2015-11-16 (10.8 years ago); last push 2015-11-16 (10.8 years ago)', $finding->evidence());
        $array = $finding->toArray();
        self::assertSame('phpzip/phpzip', $array['package']);
        self::assertSame('2.0.8', $array['version']);
        self::assertSame('silent', $array['verdict']);
        self::assertIsArray($array['signals']);
        self::assertSame(['S2', 'S4'], array_column($array['signals'], 'id'));
        self::assertSame(['high', 'high'], array_column($array['signals'], 'level'));
        self::assertSame(
            ['last release 2015-11-16 (10.8 years ago)', 'last push 2015-11-16 (10.8 years ago)'],
            array_column($array['signals'], 'summary')
        );
        self::assertSame([['years' => 10.8], ['years' => 10.8]], array_column($array['signals'], 'data'));
        self::assertSame('last release 2015-11-16 (10.8 years ago); last push 2015-11-16 (10.8 years ago)', $array['evidence']);
        self::assertSame('2026-09-14T10:00:00+00:00', $array['data_date']);
        self::assertSame(['wallabag/wallabag', 'phpzip/phpzip'], $array['chain']);
    }

    public function testNoteWhenNoSignals(): void
    {
        $finding = new Finding('private/thing', '3.0.0', Verdict::UNKNOWN, [], [], null, null, 'not from a Composer repository, not checked');
        self::assertSame('not from a Composer repository, not checked', $finding->evidence());
        self::assertNull($finding->toArray()['data_date']);
    }

    public function testEvidenceIsEmptyStringWhenNoSignalsAndNoNote(): void
    {
        $finding = new Finding('private/thing', '3.0.0', Verdict::UNKNOWN, [], [], null, null);
        self::assertSame('', $finding->evidence());
    }

    public function testToArrayIncludesAllowlistReasonAndNote(): void
    {
        $finding = new Finding('vendor/pkg', '1.2.3', Verdict::FINISHED, [], ['vendor/pkg'], 'audited by security team', null, 'no signals fired');
        $array = $finding->toArray();
        self::assertSame('vendor/pkg', $array['package']);
        self::assertSame('1.2.3', $array['version']);
        self::assertSame('finished', $array['verdict']);
        self::assertSame('no signals fired', $array['evidence']);
        self::assertSame('audited by security team', $array['allowlist_reason']);
        self::assertSame('no signals fired', $array['note']);
        self::assertSame(['vendor/pkg'], $array['chain']);
    }
}
