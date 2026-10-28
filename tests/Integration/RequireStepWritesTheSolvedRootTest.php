<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Json\JsonManipulator;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Verdict\MoveSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A printed `composer require` writes the root that the move check solves, under any terminal
 * answer: Composer's own JsonManipulator applies the step as `RequireCommand` does without
 * interaction (R6-3).
 */
final class RequireStepWritesTheSolvedRootTest extends TestCase
{
    /**
     * @dataProvider listings
     *
     * @param array<string, array<string, string>> $root     composer.json's `require` and `require-dev`
     * @param string                               $section  the section that holds the new constraint
     */
    #[DataProvider('listings')]
    public function testThePrintedRequireStepWritesTheNewConstraintAndLeavesNoOtherLine(array $root, string $name, string $section): void
    {
        $project = ProjectConfig::fromArray($root);
        $subject = (new MoveSubject('vendor/pkg', '1.0.0', true, [], '8.4', null))->withKeys($project->listedIn('vendor/pkg'), $project->listedIn('php'));
        $argv = $name === 'php'
            ? array_merge(['composer', 'require', $subject->phpRaiseArgument().':>=8.1'], $subject->phpRaiseOptions(), ['--no-update'])
            : array_merge(['composer', 'require', $subject->requireArgument().':^2.0'], $subject->requireOptions());

        $written = self::applied((string) json_encode($root), $argv);

        $other = $section === ProjectConfig::REQUIRE ? ProjectConfig::REQUIRE_DEV : ProjectConfig::REQUIRE;
        $links = (new ArrayLoader())->parseLinks('root/app', '1.0.0', 'requires', $written[$section] ?? []);
        self::assertSame($name === 'php' ? '>=8.1' : '^2.0', isset($links[$name]) ? $links[$name]->getPrettyConstraint() : null, implode(' ', $argv));
        self::assertNotContains($name, array_map('strtolower', array_keys($written[$other] ?? [])), implode(' ', $argv).' leaves a line in '.$other);
    }

    /** @return iterable<string, array{array<string, array<string, string>>, string, string}> */
    public static function listings(): iterable
    {
        yield 'require only' => [['require' => ['vendor/pkg' => '^1.0']], 'vendor/pkg', ProjectConfig::REQUIRE];
        yield 'require-dev only' => [['require-dev' => ['vendor/pkg' => '^1.0']], 'vendor/pkg', ProjectConfig::REQUIRE_DEV];
        yield 'require only, mixed case' => [['require' => ['Vendor/Pkg' => '^1.0']], 'vendor/pkg', ProjectConfig::REQUIRE];
        yield 'require-dev only, mixed case' => [['require-dev' => ['Vendor/Pkg' => '^1.0']], 'vendor/pkg', ProjectConfig::REQUIRE_DEV];
        yield 'both sections' => [['require' => ['vendor/pkg' => '^1.0'], 'require-dev' => ['vendor/pkg' => '^1.1']], 'vendor/pkg', ProjectConfig::REQUIRE];
        yield 'both sections, mixed case' => [['require' => ['vendor/pkg' => '^1.0'], 'require-dev' => ['Vendor/Pkg' => '^1.1']], 'vendor/pkg', ProjectConfig::REQUIRE];
        yield 'php in both sections' => [['require' => ['php' => '>=7.4'], 'require-dev' => ['PHP' => '>=8.0']], 'php', ProjectConfig::REQUIRE];
    }

    /**
     * composer.json after the step, as `RequireCommand::updateFileCleanly` writes it without
     * interaction: `--dev` picks require-dev, else require, and the other section loses the key.
     *
     * @param list<string> $argv
     *
     * @return array<string, array<string, string>>
     */
    private static function applied(string $json, array $argv): array
    {
        [$requireKey, $removeKey] = \in_array('--dev', $argv, true) ? [ProjectConfig::REQUIRE_DEV, ProjectConfig::REQUIRE] : [ProjectConfig::REQUIRE, ProjectConfig::REQUIRE_DEV];
        [$package, $constraint] = explode(':', $argv[2], 2);
        $manipulator = new JsonManipulator($json);
        self::assertTrue($manipulator->addLink($requireKey, $package, $constraint));
        self::assertTrue($manipulator->removeSubNode($removeKey, $package));
        $manipulator->removeMainKeyIfEmpty($removeKey);
        $written = json_decode($manipulator->getContents(), true);
        self::assertIsArray($written);

        /** @var array<string, array<string, string>> $written */
        return $written;
    }
}
