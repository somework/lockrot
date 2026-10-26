<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The Composer version of train 7d's answers, and the rule that stops the oracle test on another
 * version. `.github/actions/move-check-oracle` loads this file before `composer update`: keep it
 * free of other classes outside {@see guard()}.
 */
final class ComposerOracle
{
    /** Set to `1`, a run fails the oracle test on another Composer version instead of skipping it. */
    public const REQUIRED = 'LOCKROT_COMPOSER_ORACLE';

    /**
     * @param array<mixed, mixed> $document the decoded `tests/fixtures/flags/cases.json`
     *
     * @throws \UnexpectedValueException when the cases with a `solver_world` record no version, or more than one
     */
    public static function recordedVersion(array $document): string
    {
        $versions = [];
        foreach (\is_array($document['cases'] ?? null) ? $document['cases'] : [] as $case) {
            $inputs = \is_array($case) && \is_array($case['inputs'] ?? null) ? $case['inputs'] : [];
            if (\is_array($inputs['solver_world'] ?? null)) {
                $versions[] = \is_array($inputs['move_check'] ?? null) && \is_string($inputs['move_check']['composer'] ?? null) ? $inputs['move_check']['composer'] : '(none)';
            }
        }
        $versions = array_values(array_unique($versions));
        sort($versions);
        if (\count($versions) !== 1 || $versions[0] === '(none)') {
            throw new \UnexpectedValueException('the train 7d cases must record one Composer version in inputs.move_check.composer, and they record: '.($versions === [] ? 'none' : implode(', ', $versions)));
        }

        return $versions[0];
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

    public static function guard(string $recorded, string $running, bool $required): void
    {
        if ($running === $recorded) {
            return;
        }
        $reason = 'the answers are those of composer/composer '.$recorded.', and this run has '.$running;
        if ($required) {
            Assert::fail($reason);
        }
        Assert::markTestSkipped($reason);
    }
}
