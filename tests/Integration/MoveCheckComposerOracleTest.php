<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Repository\RepositoryFactory;
use Composer\Semver\VersionParser;
use Lockrot\Analyzer\RunNote;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\Holder;
use Lockrot\Solver\CheckOutcome;
use Lockrot\Solver\MoveChecker;
use Lockrot\Solver\MoveCheckers;
use Lockrot\Solver\MoveQuery;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Each case of train 7d records the answers of the Composer 2.10.3 CLI to the requests of its
 * designed world (`inputs.solver_world`, SPEC-0.14.md §5.6). lockrot's own move checker solves the
 * same requests on the same world, served over HTTP, at the world's `config.platform.php` and with
 * `audit.block-insecure` false. Its result, form and holds equal the CLI's. Its problem text has
 * the CLI's `{cause}` (§6.2). The answers hold for the Composer that recorded them, so the test
 * runs only on the composer/composer version that composer.lock pins. It covers nothing: each solve
 * outlasts a mutant's time limit, and the tests that state the rules kill the mutants.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class MoveCheckComposerOracleTest extends TestCase
{
    private const LOCK = __DIR__.'/../../composer.lock';

    private ?string $project = null;

    protected function tearDown(): void
    {
        if ($this->project !== null) {
            array_map('unlink', glob($this->project.'/*') ?: []);
            rmdir($this->project);
        }
    }

    /** @return iterable<string, array{array<mixed, mixed>}> */
    public static function worlds(): iterable
    {
        foreach (JsonPath::arrayAt(JsonPath::decodeFile(CaseHydrator::CASES), ['cases']) as $case) {
            if (\is_array($case) && \is_array($case['inputs'] ?? null) && \is_array($case['inputs']['solver_world'] ?? null)) {
                yield JsonPath::stringAt($case, ['id']) => [$case['inputs']['solver_world']];
            }
        }
    }

    public function testTrain7dHasAWorldForEachCase(): void
    {
        self::assertCount(12, iterator_to_array(self::worlds()));
    }

    /**
     * @dataProvider worlds
     *
     * @param array<mixed, mixed> $solverWorld the case's `inputs.solver_world`
     */
    #[DataProvider('worlds')]
    public function testLockrotsSolverGivesEachAnswerOfTheComposerCli(array $solverWorld): void
    {
        $locked = self::lockedComposer();
        if (Composer::getVersion() !== $locked) {
            self::markTestSkipped('the answers are those of composer/composer '.$locked.', the version that composer.lock pins, and this run has '.Composer::getVersion());
        }
        $world = JsonPath::arrayAt($solverWorld, ['world']);
        $answers = JsonPath::arrayAt($solverWorld, ['answers']);
        $server = FixtureRepositoryServer::fromReleases(self::releases(JsonPath::arrayAt($world, ['repo'])));
        $server->start();
        try {
            $checker = $this->checker($server, $world, JsonPath::stringAt($world, ['platform_php']));
            $expected = [];
            $actual = [];
            foreach ($answers as $key => $answer) {
                self::assertIsArray($answer);
                $outcome = self::ask($checker, (string) $key, $answers, JsonPath::arrayAt($world, ['repo']));
                $withProblem = $key === 'baseline' || strpos((string) $key, 'combined ') === 0;
                // lockrot reads no hold of a refused baseline: every move is then not checked.
                $expected[$key] = self::answerOf(JsonPath::stringAt($answer, ['result']), \is_string($answer['form'] ?? null) ? $answer['form'] : null, null, $key === 'baseline' ? [] : JsonPath::arrayAt($answer, ['holds']), $answer['problem'], $withProblem);
                $actual[$key] = self::answerOf($outcome->check()->result(), $outcome->check()->form(), $outcome->check()->reason(), array_map(static fn (Holder $hold): array => $hold->jsonSerialize(), $outcome->holds()), $outcome->problem(), $withProblem);
            }
        } finally {
            $server->stop();
        }

        self::assertNotSame([], $expected);
        self::assertSame($expected, $actual, JsonPath::stringAt($solverWorld, ['world_id']).': lockrot\'s answers against the Composer CLI\'s');
    }

    /** @param array<mixed, mixed> $world */
    private function checker(FixtureRepositoryServer $server, array $world, string $platformPhp): MoveChecker
    {
        $this->project = sys_get_temp_dir().'/lockrot-move-check-oracle-'.bin2hex(random_bytes(8));
        mkdir($this->project);
        // As the oracle's project: Composer's own repository serves every name, and packagist.org none.
        $root = JsonPath::arrayAt($world, ['root']) + ['repositories' => [['type' => 'composer', 'url' => $server->url()], ['packagist.org' => false]]];
        $lock = ['packages' => self::lockEntries($world, 'packages', $server->url()), 'packages-dev' => self::lockEntries($world, 'packages-dev', $server->url()), 'aliases' => [], 'minimum-stability' => 'stable', 'stability-flags' => [], 'prefer-stable' => false, 'prefer-lowest' => false, 'platform' => ['php' => JsonPath::has($root, ['require', 'php']) ? JsonPath::stringAt($root, ['require', 'php']) : '*'], 'platform-dev' => []];
        file_put_contents($this->project.'/composer.json', (string) json_encode($root));
        file_put_contents($this->project.'/composer.lock', (string) json_encode($lock));

        $config = $server->config();
        $config->merge(['config' => ['platform' => ['php' => $platformPhp], 'audit' => ['block-insecure' => false]]]);
        $repositories = $server->repositories();
        $io = new NullIO();
        /** @var array<string, mixed> $root */
        $package = (new RootPackageLoader(RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config)), $config, null, null, $io))->load($root + ['version' => '1.0.0']);
        $minor = implode('.', \array_slice(explode('.', $platformPhp), 0, 2));

        return MoveCheckers::forProject($package, LockFile::fromFile($this->project.'/composer.lock'), ProjectConfig::fromFile($this->project.'/composer.json'), $config, $repositories, $minor, false, Deadline::never());
    }

    /**
     * @param array<mixed, mixed> $world
     *
     * @return list<array<mixed, mixed>>
     */
    private static function lockEntries(array $world, string $section, string $repository): array
    {
        $entries = [];
        foreach (JsonPath::has($world, ['lock', $section]) ? JsonPath::arrayAt($world, ['lock', $section]) : [] as $entry) {
            self::assertIsArray($entry);
            // The notification-url that Composer locks from a Composer repository: lockrot reads the origin from it.
            $entries[] = ['type' => 'library', 'notification-url' => $repository.'/downloads/'] + $entry;
        }

        return $entries;
    }

    /**
     * Asks the request of one answer key: `baseline`, `combined <names>`, or `<verb> <package>
     * <constraint> <php>` ({@see MoveQuery::key()}). A combined update writes each name's lead
     * constraint, the one of its recorded `update` request.
     *
     * @param array<mixed, mixed> $answers
     * @param array<mixed, mixed> $repo
     */
    private static function ask(MoveChecker $checker, string $key, array $answers, array $repo): CheckOutcome
    {
        if ($key === 'baseline') {
            return $checker->baseline();
        }
        $words = explode(' ', $key);
        if ($words[0] === 'combined') {
            $inline = [];
            foreach (\array_slice($words, 1) as $name) {
                $update = array_values(array_filter(array_keys($answers), static fn ($other): bool => strpos((string) $other, 'update '.$name.' ') === 0));
                self::assertCount(1, $update, $key.': one update request of '.$name);
                $inline[$name] = explode(' ', (string) $update[0])[2];
            }

            return $checker->combined($inline);
        }
        [$verb, $package, $constraint, $php] = $words;
        $version = self::release($repo, $package, $constraint);

        return $verb === 'require' ? $checker->check(MoveQuery::require($package, $constraint, $version, $php === '' ? null : $php)) : $checker->check(MoveQuery::update($package, $constraint, $version, $php === '' ? null : $php));
    }

    /**
     * The release that a caret constraint starts at, as the repository spells it.
     *
     * @param array<mixed, mixed> $repo
     */
    private static function release(array $repo, string $package, string $constraint): string
    {
        $parser = new VersionParser();
        foreach (JsonPath::arrayAt($repo, [$package]) as $release) {
            self::assertIsArray($release);
            if ($parser->normalize(JsonPath::stringAt($release, ['version'])) === $parser->normalize(ltrim($constraint, '^'))) {
                return JsonPath::stringAt($release, ['version']);
            }
        }
        self::fail($package.' has no release at '.$constraint);
    }

    /**
     * One answer, comparable: the holds as a set, and the problem by the `{cause}` that the notes
     * quote, compared where lockrot writes a problem (the baseline and the combined update).
     *
     * @param array<mixed, mixed> $holds
     * @param mixed               $problem
     *
     * @return array<string, mixed>
     */
    private static function answerOf(string $result, ?string $form, ?string $reason, array $holds, $problem, bool $compareProblem): array
    {
        $set = [];
        foreach ($holds as $hold) {
            self::assertIsArray($hold);
            $set[] = implode(' | ', array_map(static fn ($value): string => var_export($value, true), [$hold['source'], $hold['package'], $hold['version'], $hold['link'], $hold['target'], $hold['constraint']]));
        }
        sort($set);

        return ['result' => $result, 'form' => $form, 'reason' => $reason, 'holds' => $set, 'cause' => $compareProblem && \is_string($problem) ? RunNote::updateNowRefused($problem)->text() : null];
    }

    /**
     * @param array<mixed, mixed> $repo the world's `repo`: name => releases
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function releases(array $repo): array
    {
        $out = [];
        foreach ($repo as $name => $releases) {
            self::assertIsString($name);
            self::assertIsArray($releases);
            foreach ($releases as $release) {
                self::assertIsArray($release);
                $entry = [];
                foreach ($release as $field => $value) {
                    self::assertIsString($field);
                    $entry[$field] = $value;
                }
                $out[$name][] = $entry;
            }
        }

        return $out;
    }

    private static function lockedComposer(): string
    {
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (JsonPath::arrayAt(JsonPath::decodeFile(self::LOCK), [$section]) as $package) {
                if (\is_array($package) && ($package['name'] ?? null) === 'composer/composer') {
                    return JsonPath::stringAt($package, ['version']);
                }
            }
        }
        self::fail('composer.lock locks no composer/composer');
    }
}
