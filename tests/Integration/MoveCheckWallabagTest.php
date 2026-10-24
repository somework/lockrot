<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Repository\AdvisoryProviderInterface;
use Lockrot\Analyzer\MoveCheckRun;
use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\UpdateNow;
use Lockrot\Security\Holder;
use Lockrot\Solver\HoldScan;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveCheckers;
use Lockrot\Solver\ProblemHolds;
use Lockrot\Solver\SolverChecker;
use Lockrot\Tests\Support\FindingRelations;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\WallabagSite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * {@see WallabagSite} at the target PHP 7.4. Composer refuses the
 * moves of symfony/monolog-bridge and symfony/yaml, and on 2.9 or later the plain
 * `composer update` of each installs a dev snapshot.
 *
 * The coverage names the move check only: the tests read a report that setUpBeforeClass() solves
 * once, and Infection runs that solve for each mutant of a line that the tests cover.
 *
 * @covers \Lockrot\Analyzer\MoveCheckRun
 * @covers \Lockrot\Analyzer\UpdateNow
 * @covers \Lockrot\Security\Holder
 * @covers \Lockrot\Solver\HoldScan
 * @covers \Lockrot\Solver\MoveCheck
 * @covers \Lockrot\Solver\MoveCheckers
 * @covers \Lockrot\Solver\ProblemHolds
 * @covers \Lockrot\Solver\SolverChecker
 */
#[CoversClass(MoveCheckRun::class)]
#[CoversClass(UpdateNow::class)]
#[CoversClass(Holder::class)]
#[CoversClass(HoldScan::class)]
#[CoversClass(MoveCheck::class)]
#[CoversClass(MoveCheckers::class)]
#[CoversClass(ProblemHolds::class)]
#[CoversClass(SolverChecker::class)]
final class MoveCheckWallabagTest extends TestCase
{
    private static ?Report $report = null;

    public static function setUpBeforeClass(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer 2.2 has no AdvisoryProviderInterface: no move reaches a vulnerable package');
        }
        self::$report = WallabagSite::analyze('7.4');
    }

    public function testComposerRefusesMonologBridgeAndNamesTheRootRequirementOnHttpKernel(): void
    {
        $move = self::move('symfony/monolog-bridge');

        self::assertSame(['result' => 'refused', 'form' => null, 'reason' => null], $move['check']);
        self::assertSame('upgrade', $move['fix_kind'], 'a refused move is held: SPEC §5.3 classes it upgrade');
        self::assertSame([], self::packageSteps($move), 'no update or require step that Composer refuses');
        self::assertContains(['source' => 'root', 'link' => 'root-require', 'target' => 'symfony/http-kernel', 'constraint' => '^4.4.51'], self::holds($move));
        self::assertContains(['source' => 'package', 'link' => 'require', 'target' => 'symfony/http-kernel', 'constraint' => '^5.3|^6.0'], self::holds($move));
        self::assertContains(['source' => 'package', 'link' => 'conflict', 'target' => 'symfony/http-foundation', 'constraint' => '<5.3'], self::holds($move));
    }

    public function testComposerRefusesYamlForItsConflictWithTheConsoleThatTheRootKeeps(): void
    {
        $move = self::move('symfony/yaml');

        self::assertSame('refused', JsonPath::stringAt($move, ['check', 'result']));
        self::assertSame([], self::packageSteps($move));
        self::assertContains(['source' => 'package', 'link' => 'conflict', 'target' => 'symfony/console', 'constraint' => '<5.3'], self::holds($move));
        self::assertContains(['source' => 'root', 'link' => 'root-require', 'target' => 'symfony/console', 'constraint' => '^4.4.49'], self::holds($move));
    }

    public function testAMoveThatComposerResolvesNamesItsConstraintInline(): void
    {
        $move = self::move('phpseclib/phpseclib');

        self::assertSame(['result' => 'resolves', 'form' => 'inline', 'reason' => null], $move['check']);
        self::assertSame([['composer', 'update', 'phpseclib/phpseclib:^3.0.57']], $move['commands']);
    }

    public function testUpdateNowHoldsOnlyTheMovesThatResolveTogether(): void
    {
        $report = self::report();
        $security = JsonPath::arrayAt($report, ['security']);
        $members = JsonPath::arrayAt($security, ['update_now']);

        self::assertContains('phpseclib/phpseclib', $members);
        self::assertNotContains('symfony/monolog-bridge', $members);
        self::assertNotContains('symfony/yaml', $members);
        self::assertContains('phpseclib/phpseclib:^3.0.57', JsonPath::arrayAt($security, ['update_now_command']));
        self::assertSame(['resolves', true], [JsonPath::stringAt($report, ['run', 'move_check', 'baseline']), JsonPath::arrayAt($report, ['run', 'move_check'])['ignores_extensions']]);
    }

    /** The relations of the move check hold on every move of the lock. */
    public function testNoMovePrintsAStepThatItsCheckRefuses(): void
    {
        $broken = array_values(array_filter(FindingRelations::violations(self::report()), static fn (string $violation): bool => strpos($violation, 'next_step:') !== false));

        self::assertSame([], $broken);
    }

    /** @return array<mixed, mixed> */
    private static function report(): array
    {
        self::assertNotNull(self::$report);

        return JsonPath::decoded(self::$report->toArray());
    }

    /** @return array<mixed, mixed> */
    private static function move(string $package): array
    {
        foreach (JsonPath::arrayAt(self::report(), ['findings']) as $finding) {
            if (\is_array($finding) && $finding['package'] === $package) {
                return JsonPath::arrayAt($finding, ['next_step']);
            }
        }
        self::fail($package.' has no finding');
    }

    /**
     * @param array<mixed, mixed> $move
     *
     * @return list<array<mixed, mixed>>
     */
    private static function packageSteps(array $move): array
    {
        return array_values(array_filter(JsonPath::arrayAt($move, ['commands']), static fn ($argv): bool => \is_array($argv) && \in_array($argv[1], ['update', 'require'], true)));
    }

    /**
     * @param array<mixed, mixed> $move
     *
     * @return list<array<string, mixed>>
     */
    private static function holds(array $move): array
    {
        return array_values(array_map(static fn ($hold): array => \is_array($hold) ? array_intersect_key($hold, array_flip(['source', 'link', 'target', 'constraint'])) : [], JsonPath::arrayAt($move, ['held_by'])));
    }
}
