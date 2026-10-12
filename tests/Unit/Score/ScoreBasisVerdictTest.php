<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Score;

use Lockrot\Allowlist\AllowlistEntry;
use Lockrot\Score\ScoreBasis;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Score;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\TestCase;

/** SPEC §4.1: the score-0 word. */
final class ScoreBasisVerdictTest extends TestCase
{
    public function testAnEntryThatListsEveryFlagAndAcceptsNoFiredFlagReadsOk(): void
    {
        $flags = FlagSet::fromSignals([], new AllowlistEntry('acme/pkg', null, 'all six by name', null, AllowlistEntry::BY_PROJECT, array_keys(ScoreModel::POINTS)), []);

        self::assertSame('ok', ScoreBasis::verdict(Score::of($flags, Score::DIRECT, false), $flags, true));
    }

    public function testAnEntryForTheWholePackageReadsFinishedWithNothingFired(): void
    {
        $flags = FlagSet::fromSignals([], new AllowlistEntry('acme/pkg', null, 'feature-complete', null, AllowlistEntry::BY_PROJECT), []);

        self::assertSame('finished', ScoreBasis::verdict(Score::of($flags, Score::DIRECT, false), $flags, true));
    }

    public function testNoEntryReadsOkOrUnknown(): void
    {
        $flags = FlagSet::fromSignals([], null, []);

        self::assertSame('ok', ScoreBasis::verdict(Score::of($flags, Score::DIRECT, false), $flags, true));
        self::assertSame('unknown', ScoreBasis::verdict(Score::of($flags, Score::DIRECT, false), $flags, false));
    }
}
