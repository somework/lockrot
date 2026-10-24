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
