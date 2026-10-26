<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Security\Holder;
use Lockrot\Solver\MoveCheck;
use Lockrot\Solver\MoveChecker;

/**
 * `run.php_window.target_fix` (docs/verdicts.md#the-php-window): the packages that exclude the
 * target, and Composer's answer to one update of them at the target.
 *
 * @internal
 *
 * @phpstan-import-type Req from PhpWindow
 * @phpstan-import-type Fix from PhpWindow
 */
final class TargetFix
{
    /** The values of `reason`: the baseline's reasons, and those of the solve itself. */
    public const REASONS = [MoveCheck::UNLOADED_NAME, MoveCheck::OFFLINE, MoveCheck::INSTALL_TIME_BUDGET, MoveCheck::NOT_FROM_COMPOSER_REPOSITORY, MoveCheck::NO_COMPOSER_JSON, MoveCheck::COMPOSER_JSON_INVALID, MoveCheck::SOLVER_ERROR];

    /**
     * A root link that fails is the root ceiling: no update can admit the target, so nothing solves.
     * A root link that only the lock's platform holds is no ceiling, because any update rewrites it.
     *
     * @param list<Req>    $failing      the links that fail at `target.php`, on the set that `target.inside` reads
     * @param list<Req>    $lockPlatform those of $failing that only the lock's platform holds
     * @param list<string> $skippedNames the lock entries whose name is no Composer package name: none reaches a solve or a command
     * @param ?MoveChecker $checker      null when the run checks no move
     *
     * @return Fix
     */
    public static function of(array $failing, array $lockPlatform, array $skippedNames, bool $platformStep, ?MoveChecker $checker): array
    {
        $packages = array_filter($failing, static fn (array $req): bool => $req['name'] !== null);
        usort($packages, static fn (array $a, array $b): int => [$a['dev'], $a['name'], $a['link'], $a['target']] <=> [$b['dev'], $b['name'], $b['link'], $b['target']]);
        $fix = ['packages' => $packages, 'lock_platform' => $lockPlatform, 'command' => null, 'check' => MoveCheck::NOT_CHECKED, 'reason' => null, 'held_by' => [], 'platform_step' => $platformStep];
        $ceiling = [];
        foreach ($failing as $req) {
            if ($req['name'] === null && !\in_array($req, $lockPlatform, true)) {
                $ceiling[] = new Holder(Holder::ROOT, null, null, self::section($req), $req['constraint'], $req['target']);
            }
        }
        if ($ceiling !== []) {
            return array_replace($fix, ['check' => MoveCheck::REFUSED, 'held_by' => Holder::distinct($ceiling)]);
        }
        $names = array_keys(array_flip(array_column($packages, 'name')));
        if ($checker === null || ($names === [] && $lockPlatform === [])) {
            return $fix;
        }
        // Ruling T5: no solve runs while the baseline is not checked, so its reason wins over the names'.
        $baseline = $checker->baseline()->check();
        if (!$baseline->isResolved() && !$baseline->isRefused()) {
            return array_replace($fix, ['reason' => $baseline->reason()]);
        }
        // Composer and the move check name a package in lower case. The command keeps the lock's spelling.
        $lower = array_map('strtolower', $names);
        if (array_intersect($lower, $skippedNames) !== []) {
            return array_replace($fix, ['reason' => MoveCheck::UNLOADED_NAME]);
        }
        $outcome = $checker->solveAt($lower);
        $check = $outcome->check();
        if ($check->isResolved()) {
            $option = MoveCheck::OPTIONS[$check->form() ?? MoveCheck::INLINE] ?? null;

            $command = $names === [] ? ['composer', 'update', '--lock'] : array_merge(['composer', 'update'], $names, $option === null ? [] : [$option]);

            return array_replace($fix, ['command' => $command, 'check' => MoveCheck::RESOLVES]);
        }
        if ($check->isRefused()) {
            // A listed package's own php or php-64bit requirement restates `packages`. Composer names packages in lower case.
            $holds = array_values(array_filter($outcome->holds(), static fn (Holder $hold): bool => !(\in_array($hold->target(), PhpWindow::VERSIONED, true) && \in_array($hold->package(), $lower, true))));

            return array_replace($fix, ['check' => MoveCheck::REFUSED, 'held_by' => $holds]);
        }

        return array_replace($fix, ['reason' => $check->reason()]);
    }

    /**
     * The root ceiling (rulings F2 and F4): a refusal whose every hold is a root link on php.
     *
     * @param Fix $fix
     */
    public static function isRootCeiling(array $fix): bool
    {
        if ($fix['check'] !== MoveCheck::REFUSED || $fix['held_by'] === []) {
            return false;
        }
        foreach ($fix['held_by'] as $hold) {
            if ($hold->source() !== Holder::ROOT || !\in_array($hold->link(), [Holder::REQUIRE, Holder::REQUIRE_DEV, Holder::CONFLICT], true) || !\in_array($hold->target(), PhpWindow::VERSIONED, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Req $req
     *
     * @return Holder::REQUIRE|Holder::REQUIRE_DEV|Holder::CONFLICT
     */
    private static function section(array $req): string
    {
        if ($req['link'] === PhpWindow::LINK_CONFLICT) {
            return Holder::CONFLICT;
        }

        return $req['dev'] ? Holder::REQUIRE_DEV : Holder::REQUIRE;
    }
}
