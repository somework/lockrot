<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Analyzer\PhpWindow;
use Lockrot\Clock;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Gate G-D: the `minors` of each set equal Composer's recorded answers
 * (tests/fixtures/php-window-oracle/README.md). It runs no Composer. It covers nothing, so that a
 * mutant is killed by the tests that state the rules, not by this oracle.
 *
 * @coversNothing
 */
#[CoversNothing]
final class PhpWindowComposerOracleTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const ANSWERS = self::FIXTURES.'php-window-oracle/results.tsv';
    private const SETS = ['all' => 'with_dev', 'no_dev' => 'without_dev'];
    /** The day of the recording: the known minors are the ones that Composer was asked about. */
    private const RECORDED_ON = '2026-10-21T00:00:00+00:00';

    /** @return iterable<string, array{0: string, 1: array<string, list<string>>, 2: list<string>}> */
    public static function answers(): iterable
    {
        $installs = [];
        $asked = [];
        foreach (file(self::ANSWERS, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$lock, $minor, $set, $exit] = explode("\t", $line);
            $asked[$lock][$minor] = true;
            $installs[$lock][$set] = $installs[$lock][$set] ?? [];
            if ($exit === '0') {
                $installs[$lock][$set][] = $minor;
            }
        }
        foreach ($installs as $lock => $sets) {
            yield $lock => [str_replace('__', '/', $lock), $sets, array_map('strval', array_keys($asked[$lock]))];
        }
    }

    /**
     * @dataProvider answers
     *
     * @param array<string, list<string>> $installs set => the minors on which Composer installs the lock
     * @param list<string>                $asked    the minors that Composer was asked about
     */
    #[DataProvider('answers')]
    public function testTheWindowIsTheMinorsOnWhichComposerInstallsTheLock(string $lock, array $installs, array $asked): void
    {
        $minors = PhpReleaseDates::load()->minors(Clock::fixed(self::RECORDED_ON));
        self::assertSame($minors, $asked, $lock.': the recording asked about every known minor');
        $dir = self::FIXTURES.$lock;
        // A target outside the known minors reads every minor at X.Y.99, the PHP that the recording set.
        $window = PhpWindow::of(LockFile::fromFile($dir.'/composer.lock'), ProjectConfig::fromFile($dir.'/composer.json'), '9.9', $minors);
        self::assertNotNull($window, $lock);
        $data = $window->toArray();

        foreach (self::SETS as $set => $key) {
            self::assertArrayHasKey($set, $installs, $lock);
            self::assertSame($installs[$set], $data[$key]['minors'] ?? null, $lock.' '.$key.' against `composer install`'.($set === 'no_dev' ? ' --no-dev' : ''));
        }
    }
}
