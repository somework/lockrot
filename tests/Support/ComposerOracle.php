<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The Composer versions of train 7d's answers, and the rule that stops the oracle test on another
 * version. `.github/actions/move-check-oracle` loads this file before `composer update`: keep it
 * free of other classes outside {@see guard()}.
 */
final class ComposerOracle
{
    /** Set to `1`, a run fails the oracle test on another Composer version instead of skipping it. */
    public const REQUIRED = 'LOCKROT_COMPOSER_ORACLE';
    /**
     * composer/composer version => the recorded version whose every answer its CLI also gave. The
     * oracle job on the version keeps the claim true. Evidence: https://github.com/somework/lockrot/pull/151
     */
    public const SAME_ANSWERS = ['2.11.0' => '2.10.3'];

    /**
     * @param array<mixed, mixed> $document the decoded `tests/fixtures/flags/cases.json`
     *
     * @return non-empty-list<string> in version order
     *
     * @throws \UnexpectedValueException when the cases with a `solver_world` record no version, or a case records none
     */
    public static function recordedVersions(array $document): array
    {
        $versions = [];
        foreach (\is_array($document['cases'] ?? null) ? $document['cases'] : [] as $case) {
            if (!\is_array($case) || !\is_array($case['solver_world'] ?? null)) {
                continue;
            }
            $composer = \is_array($case['run'] ?? null) && \is_array($case['run']['move_check'] ?? null) ? $case['run']['move_check']['composer'] ?? null : null;
            if (!\is_string($composer)) {
                throw new \UnexpectedValueException('a train 7d case with a solver_world records no Composer version in run.move_check.composer');
            }
            $versions[$composer] = $composer;
        }
        if ($versions === []) {
            throw new \UnexpectedValueException('no train 7d case records a solver_world');
        }
        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));

        return $versions;
    }

    /**
     * The versions that the oracle job runs: each recorded version, and each version of
     * {@see SAME_ANSWERS} that gave the answers of a recorded one.
     *
     * @param list<string> $recordedVersions
     *
     * @return list<string> in version order
     */
    public static function versionsToRun(array $recordedVersions): array
    {
        $versions = array_merge($recordedVersions, array_keys(array_intersect(self::SAME_ANSWERS, $recordedVersions)));
        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));

        return $versions;
    }

    /**
     * The installs that a recorded joint solve changes, as lockrot reads a solve: the CLI also lists
     * a name that it installs again at its locked tag on another reference, which is no move. A
     * branch moves with its commit, so a branch version stays.
     *
     * @param array<mixed, mixed> $installs name => the version that the CLI installs
     * @param array<mixed, mixed> $lock     the world's lock, with `packages` and `packages-dev`
     *
     * @return array<mixed, mixed>
     */
    public static function changes(array $installs, array $lock): array
    {
        $locked = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (\is_array($lock[$section] ?? null) ? $lock[$section] : [] as $entry) {
                if (\is_array($entry) && \is_string($entry['name'] ?? null) && \is_string($entry['version'] ?? null)) {
                    $locked[$entry['name']] = $entry['version'];
                }
            }
        }

        return array_filter($installs, static fn ($version, $name): bool => !\is_string($version) || ($locked[$name] ?? null) !== $version
            || strpos($version, 'dev-') === 0 || substr($version, -4) === '-dev', \ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Passes on the Composer that recorded the answers, or on one that gave the same answers
     * ({@see SAME_ANSWERS}). Else it skips the test, and it fails a required run on a Composer
     * outside {@see versionsToRun()}.
     *
     * @param list<string> $recordedVersions the versions of every world ({@see recordedVersions()})
     */
    public static function guard(string $recorded, string $running, bool $required, array $recordedVersions): void
    {
        if ($running === $recorded || (self::SAME_ANSWERS[$running] ?? null) === $recorded) {
            return;
        }
        $reason = 'the answers are those of composer/composer '.$recorded.', and this run has '.$running;
        if ($required && !\in_array($running, self::versionsToRun($recordedVersions), true)) {
            Assert::fail($reason.', which no world records');
        }
        Assert::markTestSkipped($reason);
    }
}
