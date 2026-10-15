<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Exception\ConfigException;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/**
 * A lock in a pull request is untrusted, and so are the repositories it names. A version, a branch or
 * a name outside the command argument set (SPEC-0.14.md §5.6) leaves its step out of `commands[]`.
 * The move keeps its constraint, its text and its other steps.
 */
final class HostileCommandArgumentsTest extends TestCase
{
    public function testAHostileReleaseVersionLeavesOutTheRequireStepOnly(): void
    {
        $move = self::move('A', 'twig/twig', static function (array $inputs): array {
            return self::with($inputs, ['releases'], self::renamed(JsonPath::arrayAt($inputs, ['releases']), 'v3.27.0', 'v3.27.0+%PATH%'));
        });

        self::assertSame([['composer', 'require', 'php:>=8.1', '--no-update']], $move['commands']);
        self::assertSame('^3.27.0+%PATH%', $move['constraint']);
        self::assertNotSame('', $move['text']);
    }

    public function testAHostileTagLeavesOutTheTagStep(): void
    {
        $move = self::move('G-tag‡', 'friendsofsymfony/oauth-server-bundle', static function (array $inputs): array {
            return self::with($inputs, ['releases'], self::renamed(JsonPath::arrayAt($inputs, ['releases']), '1.6.2', '1.6.2+!PATH!'));
        });

        self::assertSame(['tag', []], [$move['kind'], $move['commands']]);
    }

    public function testAHostileDependentVersionLeavesOutTheWhyNotStep(): void
    {
        $move = self::move('psr7', 'guzzlehttp/psr7', static function (array $inputs): array {
            return self::with($inputs, ['dependents', 'guzzlehttp/guzzle'], [
                ['version' => '6.5.8', 'time' => '2022-06-20T22:16:07+00:00', 'require' => ['guzzlehttp/psr7' => '^1.9']],
                ['version' => '8.2.0+%PATH%', 'time' => '2026-09-06T13:55:09+00:00', 'require' => []],
            ]);
        });

        self::assertSame([['composer', 'require', 'php:>=7.2.5', '--no-update']], $move['commands']);
        self::assertSame([false], array_column(JsonPath::arrayAt($move, ['through']), 'newest_requires'));
    }

    /** A name that starts with `-` would pass an option to Composer, `--no-update` included. */
    public function testAPackageNamedAsAnOptionWritesNoUpdateCommand(): void
    {
        foreach (['--working-dir=x', '--no-update'] as $name) {
            $report = self::report('E', static function (array $inputs) use ($name): array {
                return self::with($inputs, ['lock', 0, 'name'], $name);
            });

            self::assertSame([], JsonPath::arrayAt($report, ['findings', 0, 'next_step', 'commands']), $name);
            self::assertSame([[], null], [JsonPath::arrayAt($report, ['security', 'update_now']), JsonPath::arrayAt($report, ['security'])['update_now_command']], $name);
        }
    }

    public function testADependentNamedAsAnOptionWritesNoWhyNotStep(): void
    {
        $move = self::move('psr7', 'guzzlehttp/psr7', static function (array $inputs): array {
            $inputs = self::with($inputs, ['lock', 1, 'name'], '--no-update');
            $inputs = self::with($inputs, ['root', 'require'], ['php' => '^7.2 || ^8.0.0', '--no-update' => '*']);

            return self::with($inputs, ['dependents'], ['--no-update' => [
                ['version' => '6.5.8', 'time' => '2022-06-20T22:16:07+00:00', 'require' => ['guzzlehttp/psr7' => '^1.9']],
                ['version' => '8.2.0', 'time' => '2026-09-06T13:55:09+00:00', 'require' => []],
            ]]);
        });

        self::assertSame([['composer', 'require', 'php:>=7.2.5', '--no-update']], $move['commands']);
        self::assertSame([false], array_column(JsonPath::arrayAt($move, ['through']), 'newest_requires'));
    }

    /** The installed branch names no step: the move's steps come from the releases. */
    public function testAHostileBranchInTheLockKeepsTheStepsOfTheReleases(): void
    {
        $expected = [
            'E' => ['symfony/yaml', [['composer', 'require', 'symfony/yaml:^8.1.8']]],
            'G-tag‡' => ['friendsofsymfony/oauth-server-bundle', [['composer', 'require', 'friendsofsymfony/oauth-server-bundle:^1.6.2']]],
        ];
        foreach ($expected as $id => [$package, $commands]) {
            $move = self::move($id, $package, static function (array $inputs): array {
                return self::with($inputs, ['lock', 0, 'version'], 'dev-x%PATH%');
            });

            self::assertSame($commands, $move['commands'], $id);
        }
    }

    /** Composer cannot read such a version, so lockrot refuses the lock before it writes any command. */
    public function testAnOptionAsTheLockedVersionIsRefusedAsAnInvalidLock(): void
    {
        $this->expectException(ConfigException::class);

        self::report('E', static function (array $inputs): array {
            return self::with($inputs, ['lock', 0, 'version'], '--working-dir=x');
        });
    }

    /**
     * @param array<mixed, mixed> $releases
     *
     * @return list<mixed>
     */
    private static function renamed(array $releases, string $from, string $to): array
    {
        return array_map(static fn ($release): array => \is_array($release) && ($release['version'] ?? null) === $from ? ['version' => $to] + $release : (array) $release, array_values($releases));
    }

    /**
     * @param array<mixed, mixed> $data
     * @param non-empty-list<int|string> $path
     * @param mixed                      $value
     *
     * @return array<mixed, mixed> $data with $value at $path
     */
    private static function with(array $data, array $path, $value): array
    {
        $key = $path[0];
        $rest = \array_slice($path, 1);
        $data[$key] = $rest === [] ? $value : self::with(\is_array($data[$key] ?? null) ? $data[$key] : [], $rest, $value);

        return $data;
    }

    /**
     * @param callable(array<mixed, mixed>): array<mixed, mixed> $edit
     *
     * @return array<mixed, mixed> the `next_step` of $package
     */
    private static function move(string $id, string $package, callable $edit): array
    {
        foreach (JsonPath::arrayAt(self::report($id, $edit), ['findings']) as $finding) {
            self::assertIsArray($finding);
            if ($finding['package'] === $package) {
                return JsonPath::arrayAt($finding, ['next_step']);
            }
        }
        self::fail('no finding for '.$package);
    }

    /**
     * @param callable(array<mixed, mixed>): array<mixed, mixed> $edit
     *
     * @return array<mixed, mixed> the report-2 document
     */
    private static function report(string $id, callable $edit): array
    {
        $case = CaseHydrator::case($id);
        $case['inputs'] = $edit(JsonPath::arrayAt($case, ['inputs']));

        return JsonPath::decoded(CaseHydrator::report($case)->toArray());
    }
}
