<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Exception\ConfigException;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\Contract\CaseRecording;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\RootRecount;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use Lockrot\Verdict\FailOn;
use PHPUnit\Framework\TestCase;

/**
 * A lock in a pull request is untrusted, and so are the repositories it names. A version, a branch or
 * a name outside the command argument set (SPEC-0.14.md §5.6) leaves its step out of `commands[]`.
 * The move keeps its constraint, its text and its other steps, and the report stays valid against
 * its schema.
 */
final class HostileCommandArgumentsTest extends TestCase
{
    use ValidatesJsonSchemas;

    /** A package name that `packageName` admits and the command argument rule refuses, by its exploit class. */
    private const HOSTILE_NAMES = [
        'shell metacharacters' => 'a/b;curl x|sh;#',
        'cmd delayed expansion' => 'a/b!x',
        'carriage return' => "a/b\r",
        'line feed' => "a/b\nx",
        'typographic quote' => "a/b\u{2019}x",
        'double quote' => 'a/b"x',
        'leading dash' => '--working-dir/x',
    ];

    public function testAHostileReleaseVersionLeavesOutTheRequireStepOnly(): void
    {
        $move = $this->move('A', 'twig/twig', static function (array $inputs): array {
            return self::with($inputs, ['releases'], self::renamed(JsonPath::arrayAt($inputs, ['releases']), 'v3.27.0', 'v3.27.0+%PATH%'));
        });

        self::assertSame([['composer', 'require', 'php:>=8.1', '--no-update']], $move['commands']);
        self::assertSame('^3.27.0+%PATH%', $move['constraint']);
        self::assertNotSame('', $move['text']);
    }

    public function testAHostileTagLeavesOutTheTagStep(): void
    {
        $move = $this->move('G-tag‡', 'friendsofsymfony/oauth-server-bundle', static function (array $inputs): array {
            return self::with($inputs, ['releases'], self::renamed(JsonPath::arrayAt($inputs, ['releases']), '1.6.2', '1.6.2+!PATH!'));
        });

        self::assertSame(['tag', '1.6.2+!PATH!', []], [$move['kind'], JsonPath::stringAt($move, ['latest', 'version']), $move['commands']]);
    }

    public function testAHostileDependentVersionStaysOutOfTheSteps(): void
    {
        $move = $this->move('psr7', 'guzzlehttp/psr7', static function (array $inputs): array {
            $releases = JsonPath::arrayAt($inputs, ['packages', 'guzzlehttp/guzzle', 'releases']);
            $inputs = self::with($inputs, ['packages', 'guzzlehttp/guzzle', 'releases'], self::renamed($releases, '8.2.0', '8.2.0+%PATH%'));

            return self::with($inputs, ['dependents', 'guzzlehttp/guzzle'], [
                ['version' => '6.5.8', 'time' => '2022-06-20T22:16:07+00:00', 'require' => ['guzzlehttp/psr7' => '^1.9']],
                ['version' => '8.2.0+%PATH%', 'time' => '2026-09-06T13:55:09+00:00', 'require' => []],
            ]);
        });

        self::assertSame([['composer', 'require', 'php:>=7.2.5', '--no-update'], ['composer', 'why-not', 'guzzlehttp/psr7', '2.13.0', '--locked']], $move['commands']);
        self::assertSame([false], array_column(JsonPath::arrayAt($move, ['through']), 'newest_requires'));
        self::assertSame('8.2.0+%PATH%', JsonPath::stringAt($move, ['through', 0, 'newest_stable', 'version']));
    }

    /** A hostile name is no Composer package name: the lock entry gets no finding, and no command or text names it. */
    public function testAHostilePackageNameGetsNoFindingAndNoCommand(): void
    {
        foreach (self::HOSTILE_NAMES as $class => $name) {
            $report = $this->report('E', static function (array $inputs) use ($name): array {
                $lock = JsonPath::arrayAt($inputs, ['lock']);
                $lock[] = ['name' => $name, 'version' => '1.0.0', 'dev' => false, 'origin' => 'packagist'];

                return self::with($inputs, ['lock'], $lock);
            });
            $notes = array_values(array_filter(JsonPath::arrayAt($report, ['note_details']), static fn ($note): bool => \is_array($note) && $note['code'] === 'package_name_invalid'));

            self::assertNotContains($name, array_column(JsonPath::arrayAt($report, ['findings']), 'package'), $class);
            self::assertCount(1, $notes, $class);
            self::assertSame([$name], JsonPath::arrayAt($notes[0], ['data', 'packages']), $class);
            // Both sides go through json_encode(), which escapes `/` and quotes alike.
            self::assertStringNotContainsString(substr((string) json_encode($name), 1, -1), (string) json_encode([$report['notes'], JsonPath::arrayAt($report, ['findings'])]), $class.': no text or command names it');
            self::assertSame([], RootRecount::mismatches($report), $class.': the independent recount of the root blocks');
        }
    }

    public function testAnUpdateMoveShowsThePosixRenderingOfItsStep(): void
    {
        $move = $this->move('E', 'symfony/yaml', static function (array $inputs): array {
            return $inputs;
        });

        self::assertSame([['composer', 'update', 'symfony/yaml'], ['composer', 'why-not', 'symfony/yaml', 'v5.4.52', '--locked']], $move['commands']);
        self::assertSame('composer update symfony/yaml (gets v5.4.53) — clears 3 advisories', $move['text']);
    }

    /** The installed branch names no step: the move's steps come from the releases. */
    public function testAHostileBranchInTheLockKeepsTheStepsOfTheReleases(): void
    {
        $expected = [
            'E' => ['symfony/yaml', [['composer', 'require', 'php:>=8.4.1', '--no-update'], ['composer', 'require', 'symfony/yaml:^8.1.8'], ['composer', 'why-not', 'symfony/yaml', 'v8.1.8', '--locked']]],
            'G-tag‡' => ['friendsofsymfony/oauth-server-bundle', [['composer', 'require', 'friendsofsymfony/oauth-server-bundle:^1.6.2'], ['composer', 'why-not', 'friendsofsymfony/oauth-server-bundle', '1.6.2', '--locked']]],
        ];
        foreach ($expected as $id => [$package, $commands]) {
            $move = $this->move($id, $package, static function (array $inputs): array {
                return self::with($inputs, ['lock', 0, 'version'], 'dev-x%PATH%');
            });

            self::assertSame($commands, $move['commands'], $id);
        }
    }

    /** Composer cannot read such a version, so lockrot refuses the lock before it writes any command. */
    public function testAnOptionAsTheLockedVersionIsRefusedAsAnInvalidLock(): void
    {
        $this->expectException(ConfigException::class);

        $this->report('E', static function (array $inputs): array {
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
    private function move(string $id, string $package, callable $edit): array
    {
        foreach (JsonPath::arrayAt($this->report($id, $edit), ['findings']) as $finding) {
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
     * @return array<mixed, mixed> the report-2 document, held to its schema and its strict twin
     */
    private function report(string $id, callable $edit): array
    {
        $case = CaseHydrator::case($id);
        $case['inputs'] = $edit(JsonPath::arrayAt($case, ['inputs']));
        $json = CaseRecording::formatted(CaseHydrator::report($case), FailOn::none(), CaseHydrator::lockJson([$id]))['json'];
        $this->assertValid('report', $json, $id, true, 2);

        return JsonPath::decoded(json_decode($json, true));
    }
}
