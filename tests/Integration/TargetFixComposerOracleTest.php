<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Repository\AdvisoryProviderInterface;
use Composer\Repository\RepositoryFactory;
use Lockrot\Analyzer\PhpWindow;
use Lockrot\Analyzer\TargetFix;
use Lockrot\Clock;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveCheckers;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Gate G-D for `target_fix` (tests/fixtures/target-fix-oracle/README.md). Its packages are the ones
 * that Composer was asked to update. lockrot's own solve, on the p2 answers recorded with Composer's
 * answers, gives the check and the command of the first form that Composer resolved. A root
 * ceiling is a lock that Composer refuses in every form. The test runs no Composer. It covers
 * nothing, so that only the tests that state the rules kill a mutant.
 *
 * @coversNothing
 *
 * @group covers-nothing
 *
 * @phpstan-import-type Fix from PhpWindow
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class TargetFixComposerOracleTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const ANSWERS = self::FIXTURES.'target-fix-oracle/answers.tsv';
    private const P2 = self::FIXTURES.'target-fix-oracle/p2';
    /** The recorded advisory answer of each lock, `[directory, file name]`. A lock without one blocks no advisory. */
    private const ADVISORIES = [
        'sites/wallabag-2.6.14' => [self::FIXTURES.'sites/wallabag-2.6.14/http/advisories', 'wallabag-2.6.14'],
        'apps/monicahq_monica' => [self::FIXTURES.'corpus/cache/advisories', 'monicahq_monica'],
        'sites/drupal-site' => [self::FIXTURES.'sites/drupal-site/http/advisories', 'drupal-site'],
    ];
    private const FORMS = ['listed' => MoveCheck::INLINE, 'with-dependencies' => MoveCheck::WITH_DEPENDENCIES, 'with-all-dependencies' => MoveCheck::WITH_ALL_DEPENDENCIES];
    /** The day of the recording: the known minors are the ones that Composer knew. */
    private const RECORDED_ON = '2026-10-22T00:00:00+00:00';

    /**
     * Each lock with the packages that Composer was asked to update, and Composer's answer. The
     * answer is the root ceiling when the first problem is the root's own php requirement. Else it
     * is the first form that resolved, or a refusal.
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
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer 2.2 has no AdvisoryProviderInterface: no solve blocks a vulnerable release, and the drupal-site refusal comes from advisory blocking');
        }
        $dir = self::FIXTURES.$lock;
        $server = FixtureRepositoryServer::fromLockFiles([$dir.'/composer.lock'], self::P2, array_values(array_filter(JsonPath::decodeFile(self::P2.'/names.json'), 'is_string')));
        if (isset(self::ADVISORIES[$lock])) {
            $server->withRecordedAdvisoryApi(self::ADVISORIES[$lock][1], self::ADVISORIES[$lock][0]);
        } else {
            $server->withAdvisoryApi([]);
        }
        $server->start();
        try {
            $fix = self::targetFix($server, $dir, $target);
        } finally {
            $server->stop();
        }

        self::assertSame($asked, array_values(array_unique(array_column($fix['packages'], 'name'))), $lock.': the packages that exclude the target');
        self::assertSame(
            ['ceiling' => $composer['ceiling'], 'check' => $composer['check'], 'command' => $composer['command']],
            ['ceiling' => TargetFix::isRootCeiling($fix), 'check' => $fix['check'], 'command' => $fix['command']],
            $lock.': the root ceiling, the check and the command against Composer'
        );
    }

    /**
     * The run's `target_fix` from the real move check, at the solve point that the recording set.
     *
     * @return Fix
     */
    private static function targetFix(FixtureRepositoryServer $server, string $dir, string $target): array
    {
        $json = JsonPath::decodeFile($dir.'/composer.json');
        $config = $server->config();
        $config->merge(['config' => ['platform' => ['php' => $target.'.'.MoveCheckers::NEWEST_PATCH]]]);
        $io = new NullIO();
        /** @var array<string, mixed> $json */
        $root = (new RootPackageLoader(RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config)), $config, null, null, $io))->load(['repositories' => []] + $json + ['version' => '1.0.0']);
        $lock = LockFile::fromFile($dir.'/composer.lock');
        $project = ProjectConfig::fromFile($dir.'/composer.json');
        $versions = [];
        foreach ($lock->packages(false) as $package) {
            $versions[$package->name()] = $package->normalizedVersion() ?? $package->version();
        }
        $advisories = (new RepositoryAdvisoryLoader($server->repositories()))->load($versions);
        $checker = MoveCheckers::forProject($root, $lock, $project, $config, $server->repositories(), $target, false, Deadline::never())
            ->withAdvisories($advisories->records(), $advisories->asked());
        $window = PhpWindow::of($lock, $project, $target, PhpReleaseDates::load()->minors(Clock::fixed(self::RECORDED_ON)));
        self::assertNotNull($window, $dir);
        $fix = $window->withTargetFix($checker)->toArray()['target_fix'];
        self::assertNotNull($fix, $dir.': the target is outside the window');

        return $fix;
    }
}
