<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Lockrot\Clock;
use Lockrot\Output\AgeText;
use Lockrot\Signal\Rule\LeftBehindRule;
use Lockrot\Signal\Rule\NoPushRule;
use Lockrot\Signal\Rule\NoReleaseRule;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\FlagSentence;
use Lockrot\Verdict\FlagSet;
use PHPUnit\Framework\TestCase;

/**
 * A Composer plugin in the same process can set LC_NUMERIC to a comma locale. A summary must still
 * print its years with a point, as its data and the age line do.
 */
final class YearsUnderACommaLocaleTest extends TestCase
{
    /** @var string|false */
    private $numeric;

    protected function setUp(): void
    {
        $this->numeric = setlocale(\LC_NUMERIC, '0');
        if (setlocale(\LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'de_DE.utf8') === false) {
            self::markTestSkipped('the machine has no de_DE locale, so no comma locale to set');
        }
        self::assertSame('4,5', \sprintf('%.1f', 4.5), 'the locale writes a comma, or this test proves nothing');
    }

    protected function tearDown(): void
    {
        setlocale(\LC_NUMERIC, $this->numeric === false ? 'C' : $this->numeric);
    }

    public function testTheS2SummaryAndTheStaleSentenceWriteAPoint(): void
    {
        $signal = (new NoReleaseRule(Clock::fixed(F::NOW), new Thresholds()))->evaluate(F::facts(F::package(), F::metadata([['2.0.8', '2015-11-16T16:30:51+00:00']])));

        self::assertNotNull($signal);
        self::assertSame('last release 2015-11-16 (10.8 years ago)', $signal->summary());
        self::assertSame('last release 2015-11-16 (10.8 years ago)', FlagSentence::maintenance(FlagSet::STALE, [$signal->data()]));
    }

    public function testTheAgeLineYearsWriteAPoint(): void
    {
        self::assertSame('10.8 years ago', AgeText::ago(10.84));
    }

    public function testTheS4SummaryWritesAPoint(): void
    {
        $signal = (new NoPushRule(Clock::fixed(F::NOW), new Thresholds()))->evaluate(F::facts(F::package(), null, F::activity(false, '2015-11-16T16:30:51+00:00')));

        self::assertNotNull($signal);
        self::assertStringContainsString('2015-11-16 (10.8 years ago)', $signal->summary());
    }

    public function testTheS8SummaryWritesAPoint(): void
    {
        $metadata = F::metadata([['2.4.0', '2026-06-01T00:00:00+00:00'], ['1.9.2', '2019-03-02T00:00:00+00:00']]);

        $signal = (new LeftBehindRule(Clock::fixed(F::NOW), new Thresholds()))->evaluate(F::facts(F::package(['version' => '1.9.2']), $metadata));

        self::assertNotNull($signal);
        self::assertStringContainsString('last released 2019-03-02 (7.5 years ago)', $signal->summary());
    }
}
