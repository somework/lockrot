<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Assert;

/**
 * The relations that the reasons of the contract tests' allow-lists state between lockrot's field
 * and the model's, so a listed field still has to keep its reason.
 */
final class ModelBounds
{
    /**
     * A move whose `version` the model leaves null names the lowest of the package's releases that
     * its constraint admits.
     *
     * @param array<mixed, mixed> $move
     * @param array<mixed, mixed> $releases a case's `inputs.releases`
     */
    public static function assertTheLowestAdmittedRelease(array $move, array $releases, string $message): void
    {
        $version = JsonPath::stringAt($move, ['version']);
        $constraint = JsonPath::stringAt($move, ['constraint']);
        $admitted = array_values(array_filter(
            array_map(static fn ($release): string => JsonPath::stringAt(JsonPath::decoded($release), ['version']), $releases),
            static fn (string $release): bool => Semver::satisfies($release, $constraint)
        ));
        Assert::assertNotSame([], $admitted, $message.': no release admits '.$constraint);

        Assert::assertTrue(Semver::satisfies($version, $constraint), $message.': '.$version.' outside '.$constraint);
        Assert::assertTrue(Comparator::equalTo(Semver::sort($admitted)[0], $version), $message.': '.$version.' is not the lowest of '.implode(', ', $admitted));
    }

    /**
     * Where the model marks a branch row's score as an upper bound (`at_most`), lockrot's exact score
     * of that row is at most the bound. At least one row of the model is a bound.
     *
     * @param array<mixed, mixed> $modelRows
     * @param array<mixed, mixed> $lockrotRows
     */
    public static function assertWithinTheModelsBound(array $modelRows, array $lockrotRows, string $message): void
    {
        $bounds = 0;
        foreach ($modelRows as $row) {
            $row = JsonPath::decoded($row);
            $branch = JsonPath::stringAt($row, ['branch']);
            if (!\is_array($row['fixes'] ?? null) || !\is_array($row['fixes']['if_applied'] ?? null) || $row['fixes']['if_applied']['at_most'] !== true) {
                continue;
            }
            $lockrotRow = array_values(array_filter($lockrotRows, static fn ($each): bool => \is_array($each) && ($each['branch'] ?? null) === $branch));
            Assert::assertCount(1, $lockrotRow, $message.': branch '.$branch);
            ++$bounds;

            Assert::assertLessThanOrEqual(
                JsonPath::intAt($row, ['fixes', 'if_applied', 'total']),
                JsonPath::intAt(JsonPath::decoded($lockrotRow[0]), ['fixes', 'if_applied', 'total']),
                $message.': branch '.$branch
            );
        }
        Assert::assertGreaterThan(0, $bounds, $message.': no row of the model is an upper bound');
    }
}
