<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Analyzer\PhpWindow;
use Lockrot\Analyzer\TargetFix;
use Lockrot\Clock;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Solver\MoveCheck;
use Lockrot\Tests\Support\RecordedMoveChecker;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Gate G-D for `target_fix`: the packages that it lists are the ones that Composer was asked to
 * update, its check and command follow the first form that Composer resolved, and a root ceiling
 * is a lock that Composer refuses in every form (tests/fixtures/target-fix-oracle/README.md). It
 * runs no Composer. It covers nothing, so that a mutant is killed by the tests that state the rules.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class TargetFixComposerOracleTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const ANSWERS = self::FIXTURES.'target-fix-oracle/answers.tsv';
    private const FORMS = ['listed' => MoveCheck::INLINE, 'with-dependencies' => MoveCheck::WITH_DEPENDENCIES, 'with-all-dependencies' => MoveCheck::WITH_ALL_DEPENDENCIES];
    /** The day of the recording: the known minors are the ones that Composer knew. */
    private const RECORDED_ON = '2026-10-22T00:00:00+00:00';

    /**
     * Each lock with the packages that Composer was asked to update, and Composer's answer: the
     * root ceiling when the first problem is the root's own php requirement, else the first form
     * that resolved, or a refusal.
     *
     * @return iterable<string, array{0: string, 1: string, 2: list<string>, 3: array{ceiling: bool, check: string, command: ?list<string>, resolved: ?string}}>
     */
    public static function answers(): iterable
    {
        $rows = [];
        foreach (file(self::ANSWERS, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$lock, $target, $names, $form, $exit, $problem] = explode("\t", $line);
            $rows[$lock.' at '.$target][] = ['lock' => $lock, 'target' => $target, 'names' => explode(' ', $names), 'form' => $form, 'exit' => $exit, 'problem' => $problem];
        }
        foreach ($rows as $case => $forms) {
            self::assertSame(array_keys(self::FORMS), array_column($forms, 'form'), $case.': the recording asked every form in order');
            $resolved = array_values(array_filter($forms, static fn (array $row): bool => $row['exit'] === '0'))[0]['form'] ?? null;
            $option = $resolved === null ? null : (MoveCheck::OPTIONS[self::FORMS[$resolved]] ?? null);
            yield $case => [$forms[0]['lock'], $forms[0]['target'], $forms[0]['names'], [
                'ceiling' => strpos($forms[0]['problem'], 'Root composer.json requires php ') === 0,
                'check' => $resolved === null ? MoveCheck::REFUSED : MoveCheck::RESOLVES,
                'command' => $resolved === null ? null : array_merge(['composer', 'update'], $forms[0]['names'], $option === null ? [] : [$option]),
                'resolved' => $resolved === null ? null : self::FORMS[$resolved],
            ]];
        }
    }

    /**
     * @dataProvider answers
     *
     * @param list<string>                                                                    $asked    the packages that Composer was asked to update
     * @param array{ceiling: bool, check: string, command: ?list<string>, resolved: ?string} $composer
     */
    #[DataProvider('answers')]
    public function testTheFixListsThePackagesThatComposerUpdatesAndFollowsItsAnswer(string $lock, string $target, array $asked, array $composer): void
    {
        $checker = new RecordedMoveChecker(['target_fix' => $composer['resolved'] === null ? ['result' => 'refused'] : ['result' => 'resolves', 'form' => $composer['resolved']]], []);
        $dir = self::FIXTURES.$lock;

        $window = PhpWindow::of(LockFile::fromFile($dir.'/composer.lock'), ProjectConfig::fromFile($dir.'/composer.json'), $target, PhpReleaseDates::load()->minors(Clock::fixed(self::RECORDED_ON)));
        self::assertNotNull($window, $lock);
        $fix = $window->withTargetFix($checker)->toArray()['target_fix'];

        self::assertNotNull($fix, $lock.': the target is outside the window');
        self::assertSame($asked, array_values(array_unique(array_column($fix['packages'], 'name'))), $lock.': the packages that exclude the target');
        self::assertSame(
            ['ceiling' => $composer['ceiling'], 'check' => $composer['check'], 'command' => $composer['command']],
            ['ceiling' => TargetFix::isRootCeiling($fix), 'check' => $fix['check'], 'command' => $fix['command']],
            $lock.': the root ceiling, the check and the command against Composer'
        );
    }
}
