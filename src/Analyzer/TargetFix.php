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
    /**
     * A root link that fails is the root ceiling: no update can admit the target, so nothing solves.
     *
     * @param list<Req>    $failing      the links that fail at `target.php`, on the set that `target.inside` reads
     * @param list<string> $skippedNames the lock entries whose name is no Composer package name: none reaches a solve or a command
     * @param ?MoveChecker $checker      null when the run checks no move
     *
     * @return Fix
     */
    public static function of(array $failing, array $skippedNames, bool $platformStep, ?MoveChecker $checker): array
    {
        $packages = array_values(array_filter($failing, static fn (array $req): bool => $req['name'] !== null));
        usort($packages, static fn (array $a, array $b): int => [$a['dev'], $a['name'], $a['link'], $a['target']] <=> [$b['dev'], $b['name'], $b['link'], $b['target']]);
        $fix = ['packages' => $packages, 'command' => null, 'check' => MoveCheck::NOT_CHECKED, 'reason' => null, 'held_by' => [], 'platform_step' => $platformStep];
        $ceiling = [];
        foreach ($failing as $req) {
            if ($req['name'] === null) {
                $ceiling[] = new Holder(Holder::ROOT, null, null, self::section($req), $req['constraint'], $req['target']);
            }
        }
        if ($ceiling !== []) {
            return array_replace($fix, ['check' => MoveCheck::REFUSED, 'held_by' => Holder::distinct($ceiling)]);
        }
        $names = array_values(array_unique(array_column($packages, 'name')));
        if ($checker === null || $names === []) {
            return $fix;
        }
        $lower = array_map('strtolower', $names);
        if (array_intersect($lower, $skippedNames) !== []) {
            return array_replace($fix, ['reason' => MoveCheck::UNLOADED_NAME]);
        }
        $outcome = $checker->solveAt($names);
        $check = $outcome->check();
        if ($check->isResolved()) {
            $option = MoveCheck::OPTIONS[(string) $check->form()] ?? null;

            return array_replace($fix, ['command' => array_merge(['composer', 'update'], $names, $option === null ? [] : [$option]), 'check' => MoveCheck::RESOLVES]);
        }
        if ($check->isRefused()) {
            // A listed package's own php requirement restates `packages`.
            $holds = array_values(array_filter($outcome->holds(), static fn (Holder $hold): bool => !($hold->source() === Holder::PACKAGE && $hold->link() === Holder::PLATFORM && $hold->target() === 'php' && \in_array(strtolower((string) $hold->package()), $lower, true))));

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
