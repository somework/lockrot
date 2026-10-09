<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Score\MaintenanceTerm;
use Lockrot\Score\ScoreText;
use Lockrot\Tests\Support\ScoreSweep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one grammar (text grammar 1): the score line of each worked row of score model 1, of the
 * unreached shape and of the score-0 shape. The line is a contract that other renderers match.
 */
final class ScoreTextTest extends TestCase
{
    /**
     * @dataProvider workedRows
     *
     * @param list<string>                $flags
     * @param list<array{string, string}> $advisories
     */
    #[DataProvider('workedRows')]
    public function testAWorkedRowPrintsItsScoreLine(array $flags, array $advisories, string $reach, bool $dev, ?string $accepted, string $line): void
    {
        $inputs = ['axis' => 'base', 'flags' => $flags, 'advisories' => $advisories, 'reach' => $reach, 'dev' => $dev, 'under' => null, 'accepted' => $accepted];
        $basis = ScoreSweep::basis($inputs);

        self::assertSame($line, ScoreText::render($basis));
        self::assertSame($line, $basis->toArray()['text']);
    }

    /** @return iterable<string, array{list<string>, list<array{string, string}>, string, bool, ?string, string}> */
    public static function workedRows(): iterable
    {
        yield 'A twig: raise-php is reachable' => [[], [['critical', 'raise-php'], ['high', 'raise-php'], ['low', 'raise-php']], 'direct', false, null, '32 = vulnerable 32 [critical advisory]'];
        yield 'B monolog-bridge' => [['left-behind', 'old-promise'], [['high', 'upgrade']], 'direct', false, null, '36 = left-behind 16 + old-promise 4 [¼ of 16] + vulnerable 16 [high advisory]'];
        yield 'C symfony/debug' => [['abandoned', 'old-promise'], [], 'direct', false, null, '36 = abandoned 32 + old-promise 4 [¼ of 16]'];
        yield 'D cssmin' => [['old-promise', 'stale'], [], 'direct', false, null, '18 = old-promise 16 + stale 2 [¼ of 8]'];
        yield 'E yaml' => [[], [['low', 'update'], ['low', 'update']], 'direct', false, null, '2 = vulnerable 2 [low advisory]'];
        yield 'F polyfill: reach never halves security' => [[], [['low', 'update']], 'transitive', false, null, '2 = vulnerable 2 [low advisory]'];
        yield 'G oauth-server-bundle' => [['pinned', 'stale'], [], 'direct', false, null, '18 = pinned 16 + stale 2 [¼ of 8]'];
        yield 'wp-config' => [['old-promise'], [], 'direct', false, null, '16 = old-promise 16'];
        yield 'hoa/consistency' => [['abandoned', 'old-promise'], [], 'transitive', false, null, '18 = (abandoned 32 + old-promise 4 [¼ of 16]) ÷ 2 transitive'];
        yield 'guzzle 6.5.8' => [['left-behind'], [['high', 'raise-php']], 'direct', false, null, '32 = left-behind 16 + vulnerable 16 [high advisory]'];
        yield 'yaml v3.4.47' => [['left-behind', 'old-promise'], [['low', 'update']], 'direct', false, null, '22 = left-behind 16 + old-promise 4 [¼ of 16] + vulnerable 2 [low advisory]'];
        yield 'psr7' => [['left-behind'], [['medium', 'update']], 'transitive', false, null, '16 = left-behind 16 ÷ 2 transitive + vulnerable 8 [medium advisory]'];
        yield 'twig v1.43.1' => [['old-promise'], [['critical', 'update']], 'direct', false, null, '48 = old-promise 16 + vulnerable 32 [critical advisory]'];
        yield 'xmlseclibs' => [['old-promise'], [['high', 'update']], 'transitive', false, null, '24 = old-promise 16 ÷ 2 transitive + vulnerable 16 [high advisory]'];
        yield 'flysystem' => [['left-behind'], [['low', 'update']], 'transitive', false, null, '10 = left-behind 16 ÷ 2 transitive + vulnerable 2 [low advisory]'];
        yield 'phpasn1: unreached' => [['abandoned'], [], 'unreached', false, null, '16 = abandoned 32 ÷ 2 unreached'];
        yield 'mautic/core-lib: an unknown fix does not double' => [['pinned'], [['high', 'unknown']], 'direct', false, null, '32 = pinned 16 + vulnerable 16 [high advisory]'];
        yield 'abandoned + a critical advisory nothing fixes' => [['abandoned'], [['critical', 'none']], 'direct', false, null, '96 = abandoned 32 + vulnerable 64 [critical advisory 32 × 2: no reachable fix]'];
        yield 'transitive abandoned + a medium advisory nothing fixes' => [['abandoned'], [['medium', 'none']], 'transitive', false, null, '32 = abandoned 32 ÷ 2 transitive + vulnerable 16 [medium advisory 8 × 2: no reachable fix]'];
        yield 'a high advisory held by an abandoned holder: no × 2' => [[], [['high', 'upgrade']], 'direct', false, null, '16 = vulnerable 16 [high advisory]'];
        yield 'one high advisory no release fixes' => [[], [['high', 'none']], 'direct', false, null, '32 = vulnerable 32 [high advisory 16 × 2: no reachable fix]'];
        yield 'twig on php 8.0: blocked' => [[], [['critical', 'blocked']], 'direct', false, null, '64 = vulnerable 64 [critical advisory 32 × 2: no reachable fix]'];
        yield 'twig as packages-dev' => [[], [['critical', 'raise-php']], 'direct', true, null, '16 = vulnerable 32 [critical advisory] ÷ 2 dev'];
        yield 'symfony/debug, transitive, dev' => [['abandoned', 'old-promise'], [], 'transitive', true, null, '9 = ((abandoned 32 + old-promise 4 [¼ of 16]) ÷ 2 transitive) ÷ 2 dev'];
        yield 'symfony/debug, direct, dev' => [['abandoned', 'old-promise'], [], 'direct', true, null, '18 = (abandoned 32 + old-promise 4 [¼ of 16]) ÷ 2 dev'];
        yield 'old-promise, transitive, dev' => [['old-promise'], [], 'transitive', true, null, '4 = (old-promise 16 ÷ 2 transitive) ÷ 2 dev'];
        yield 'old-promise, unreached, dev' => [['old-promise'], [], 'unreached', true, null, '4 = (old-promise 16 ÷ 2 unreached) ÷ 2 dev'];
        yield 'left-behind + stale, transitive, dev: rounded down' => [['left-behind', 'stale'], [], 'transitive', true, null, '4 = ((left-behind 16 + stale 2 [¼ of 8]) ÷ 2 transitive) ÷ 2 dev (4.5, rounded down)'];
        yield 'the deciding severity is not the worst' => [[], [['medium', 'update'], ['unrated', 'none']], 'direct', false, null, '16 = vulnerable 16 [unrated advisory 8 × 2: no reachable fix]'];
        yield 'a tie at 32: severity order decides' => [[], [['high', 'none'], ['critical', 'update']], 'direct', false, null, '32 = vulnerable 32 [critical advisory]'];
        yield 'an accepted flag after the line' => [['old-promise', 'stale'], [], 'direct', false, 'stale', '16 = old-promise 16 (stale accepted)'];
        yield 'one advisory, dev' => [[], [['low', 'update']], 'transitive', true, null, '1 = vulnerable 2 [low advisory] ÷ 2 dev'];
        yield 'score 0, an accepted flag' => [['left-behind'], [], 'direct', false, 'left-behind', '0 (left-behind accepted)'];
        yield 'score 0, an accepted flag, halved' => [['stale'], [], 'transitive', true, 'stale', '0 (stale accepted)'];
    }

    public function testAScoreWithNoTermReadsZero(): void
    {
        self::assertSame('0', ScoreText::render(ScoreSweep::basis(['axis' => 'base', 'flags' => [], 'advisories' => [], 'reach' => 'direct', 'dev' => false, 'under' => null, 'accepted' => null])));
    }

    public function testAZeroEffectReachHalvingIsNotPrinted(): void
    {
        $basis = ScoreSweep::basis(['axis' => 'base', 'flags' => [], 'advisories' => [['high', 'update']], 'reach' => 'unreached', 'dev' => false, 'under' => null, 'accepted' => null]);

        self::assertSame([['reason' => 'unreached', 'applies_to' => 'maintenance', 'divide_by' => 2, 'before' => 0, 'after' => 0]], ScoreSweep::graded($basis->toArray())['modifiers']);
        self::assertSame('16 = vulnerable 16 [high advisory]', ScoreText::render($basis));
    }

    public function testAShareWithNoGlyphIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('text grammar 1 has no glyph for a share of 1/2');
        ScoreText::corroborating(new MaintenanceTerm('stale', 'corroborating', 8, 2, 4, 8));
    }

    public function testAQuarterShareNamesItsGlyphAndWeight(): void
    {
        self::assertSame('stale 2 [¼ of 8]', ScoreText::corroborating(new MaintenanceTerm('stale', 'corroborating', 8, 4, 2, 4)));
    }
}
