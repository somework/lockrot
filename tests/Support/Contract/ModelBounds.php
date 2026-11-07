<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\ScoreModel;
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
     * Where the model marks a branch row's score as an upper bound (`at_most`), lockrot's score of that
     * row is at most the bound, and its verdict is the band of its total. At least one row of
     * the model is a bound.
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

            $lockrotIfApplied = JsonPath::arrayAt(JsonPath::decoded($lockrotRow[0]), ['fixes', 'if_applied']);
            Assert::assertLessThanOrEqual(JsonPath::intAt($row, ['fixes', 'if_applied', 'total']), JsonPath::intAt($lockrotIfApplied, ['total']), $message.': branch '.$branch);
            $band = ScoreModel::band(JsonPath::intAt($lockrotIfApplied, ['total']));
            if ($band !== null) {
                Assert::assertSame($band, $lockrotIfApplied['verdict'], $message.': branch '.$branch);
            }
        }
        Assert::assertGreaterThan(0, $bounds, $message.': no row of the model is an upper bound');
    }

    /**
     * With no project lowest PHP, the step of a holder names the holder's newest line: the line of its
     * highest release, and a constraint on that release's minor.
     *
     * @param array<mixed, mixed>       $moves    the lead move and its also[] moves
     * @param array<mixed, mixed>       $packages a case's `inputs.packages`
     */
    public static function assertTheHoldersStepNamesItsNewestLine(array $moves, array $packages, string $message): void
    {
        $checked = 0;
        foreach ($moves as $move) {
            foreach (JsonPath::arrayAt(JsonPath::decoded($move), ['held_by']) as $held) {
                $held = JsonPath::decoded($held);
                $step = \is_array($held['holder'] ?? null) ? ($held['holder']['next_step'] ?? null) : null;
                if (!\is_array($step)) {
                    continue;
                }
                $versions = array_map(
                    static fn ($release): string => JsonPath::stringAt(JsonPath::decoded($release), ['version']),
                    JsonPath::arrayAt($packages, [JsonPath::stringAt($held, ['package']), 'releases'])
                );
                Assert::assertNotSame([], $versions, $message.': no releases of the holder');
                Assert::assertSame(2, sscanf(ltrim(Semver::rsort($versions)[0], 'v'), '%d.%d', $major, $minor), $message);
                Assert::assertSame($major.'.x', $step['to_branch'], $message.': the holder\'s step is not on its newest line');
                Assert::assertSame('^'.$major.'.'.$minor, $step['constraint'], $message.': the holder\'s constraint is not on its newest minor');
                ++$checked;
            }
        }
        Assert::assertGreaterThan(0, $checked, $message.': no held_by names a holder step');
    }

    /**
     * Lockrot raises no S3 for a repository on Bitbucket, whose API has no archived field. Each flag
     * of lockrot then has the model's degree reasons minus `archived`, and a headline that is the
     * model's, or a remaining reason where the model's was `archived`.
     *
     * @param array<mixed, mixed> $model   a document, its flags keyed by id
     * @param array<mixed, mixed> $lockrot a document, its flags keyed by id
     */
    public static function assertNoArchivedReasonOnBitbucket(array $model, array $lockrot, string $message): void
    {
        Assert::assertArrayHasKey('S3', JsonPath::arrayAt($model, ['signals']), $message.': the model has S3');
        Assert::assertArrayNotHasKey('S3', JsonPath::arrayAt($lockrot, ['signals']), $message.': lockrot raises no S3');
        Assert::assertTrue(JsonPath::boolAt($model, ['activity', 'archived']), $message);
        Assert::assertFalse(JsonPath::boolAt($lockrot, ['activity', 'archived']), $message);
        Assert::assertSame('bitbucket', JsonPath::stringAt($lockrot, ['activity', 'forge']), $message);

        $dropped = 0;
        $modelFlags = JsonPath::arrayAt($model, ['flags']);
        foreach (JsonPath::arrayAt($lockrot, ['flags']) as $id => $flag) {
            $flag = JsonPath::decoded($flag);
            $modelFlag = JsonPath::decoded($modelFlags[$id] ?? null);
            if (!\is_array($modelFlag['degree'] ?? null)) {
                Assert::assertNull($flag['degree'], $message.': '.$id);
                continue;
            }
            $reasons = JsonPath::arrayAt($modelFlag, ['degree', 'reasons']);
            Assert::assertSame(array_values(array_filter($reasons, static fn ($reason): bool => $reason !== 'archived')), JsonPath::arrayAt($flag, ['degree', 'reasons']), $message.': '.$id);
            $headline = JsonPath::arrayAt($flag, ['headline'])['value'] ?? null;
            $modelHeadline = JsonPath::arrayAt($modelFlag, ['headline'])['value'] ?? null;
            if ($modelHeadline === 'archived') {
                ++$dropped;
                Assert::assertContains($headline, JsonPath::arrayAt($flag, ['degree', 'reasons']), $message.': '.$id.' headline');
            } else {
                Assert::assertSame($modelHeadline, $headline, $message.': '.$id.' headline');
            }
        }
        Assert::assertGreaterThan(0, $dropped, $message.': no flag of the model has `archived` as its headline');
    }

    /**
     * The model removes S5 to make the tie, and lockrot keeps it: its score has the model's terms and
     * the old-promise terms on top, and its without[] has one more removal, of old-promise.
     *
     * @param array<mixed, mixed> $model
     * @param array<mixed, mixed> $lockrot
     */
    public static function assertTheScoreAddsTheOldPromiseTerm(array $model, array $lockrot, string $message): void
    {
        $isAdded = static fn ($term): bool => JsonPath::decoded($term)['flag'] === 'old-promise';
        $terms = JsonPath::arrayAt($lockrot, ['score', 'terms']);
        $added = array_values(array_filter($terms, $isAdded));
        Assert::assertNotSame([], $added, $message.': lockrot has no old-promise term');
        Assert::assertSame(JsonPath::arrayAt($model, ['score', 'terms']), array_values(array_filter($terms, static fn ($term): bool => !$isAdded($term))), $message.': the other terms');

        $points = array_sum(array_map(static fn ($term): int => JsonPath::intAt(JsonPath::decoded($term), ['contribution']), $added));
        Assert::assertSame(JsonPath::intAt($model, ['score', 'total']) + $points, JsonPath::intAt($lockrot, ['score', 'total']), $message.': total');
        Assert::assertSame(JsonPath::intAt($model, ['score', 'exact']) + $points, JsonPath::intAt($lockrot, ['score', 'exact']), $message.': exact');

        $removed = static function (array $score): array {
            $ids = array_map(static fn ($row): string => JsonPath::stringAt(JsonPath::decoded($row), ['remove', 'id']), JsonPath::arrayAt($score, ['without']));
            sort($ids);

            return $ids;
        };
        $expected = array_merge($removed(JsonPath::arrayAt($model, ['score'])), ['old-promise']);
        sort($expected);
        Assert::assertSame($expected, $removed(JsonPath::arrayAt($lockrot, ['score'])), $message.': without[]');
    }

    /**
     * The model's move leaves old-promise out, and lockrot's move counts it: unverified holds it where
     * the release is undated, its score is an upper bound that is at least the model's, and each
     * also[] move's leaves is the model's with old-promise added.
     *
     * @param array<mixed, mixed> $model   a document or finding with `next_step`
     * @param array<mixed, mixed> $lockrot a document or finding with `next_step`
     */
    public static function assertTheMoveCountsOldPromise(array $model, array $lockrot, string $message): void
    {
        $step = JsonPath::arrayAt($lockrot, ['next_step']);
        $modelStep = JsonPath::arrayAt($model, ['next_step']);
        $withoutOldPromise = static fn (array $facts): array => array_values(array_filter($facts, static fn ($fact): bool => JsonPath::decoded($fact)['id'] !== 'old-promise'));

        $unverified = JsonPath::arrayAt($step, ['unverified']);
        Assert::assertCount(\count($unverified) - 1, $withoutOldPromise($unverified), $message.': unverified holds old-promise');
        Assert::assertSame(JsonPath::arrayAt($modelStep, ['unverified']), $withoutOldPromise($unverified), $message.': the other unverified facts');

        $ifApplied = JsonPath::arrayAt($step, ['if_applied']);
        Assert::assertTrue(JsonPath::boolAt($ifApplied, ['at_most']), $message.': the score is an upper bound');
        Assert::assertGreaterThanOrEqual(JsonPath::intAt($modelStep, ['if_applied', 'total']), JsonPath::intAt($ifApplied, ['total']), $message);
        Assert::assertSame(ScoreModel::band(JsonPath::intAt($ifApplied, ['total'])), $ifApplied['verdict'], $message);

        $alsoLeaves = 0;
        foreach (JsonPath::arrayAt($step, ['also']) as $i => $also) {
            $leaves = JsonPath::arrayAt(JsonPath::decoded($also), ['leaves']);
            Assert::assertSame(JsonPath::arrayAt(JsonPath::decoded(JsonPath::arrayAt($modelStep, ['also'])[$i]), ['leaves']), $withoutOldPromise($leaves), $message.': also['.$i.'].leaves');
            $alsoLeaves += \count($leaves) - \count($withoutOldPromise($leaves));
        }
        Assert::assertGreaterThan(0, $alsoLeaves, $message.': no also[] move leaves old-promise');
    }
}
