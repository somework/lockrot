<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Composer\Semver\Comparator;
use Lockrot\Solver\CheckOutcome;
use Lockrot\Solver\MoveCheck;
use Lockrot\Verdict\CommandArgument;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveChecks;

/**
 * `security.update_now` and its command (D9). The candidates are the vulnerable findings whose lead
 * move is an `update` that Composer's solver resolves inline without a joint request of its holders.
 * The command updates them in one request. When Composer refuses them together, the command leaves
 * out the candidates that the refusal names and asks once more. The members are the names of a
 * command that Composer resolves: with none, `update now` is empty and a run note says why.
 *
 * @internal
 */
final class UpdateNow
{
    /** @var list<string> */
    private array $members;
    /** @var list<string>|null */
    private ?array $command;
    private ?RunNote $note;
    private ?string $raiseTo;

    /**
     * @param list<string>      $members
     * @param list<string>|null $command
     */
    private function __construct(array $members, ?array $command, ?RunNote $note, ?string $raiseTo = null)
    {
        $this->members = $members;
        $this->command = $command;
        $this->note = $note;
        $this->raiseTo = $raiseTo;
    }

    /** @param list<Finding> $findings in report order */
    public static function of(array $findings, MoveChecks $checks): self
    {
        $inline = [];
        foreach ($findings as $finding) {
            $move = $finding->nextStep();
            $check = $move === null ? null : $move->check();
            if ($finding->securityStanding()->isVulnerable() && $move !== null && $move->kind() === Move::UPDATE && $move->movesWith() === [] && $check !== null && $check->isResolved() && $check->form() === MoveCheck::INLINE) {
                // On Composer 2.2 and 2.3, lockrot lists a package that composer.json does not require plain, and the check solves the line as printed.
                $constraint = $checks->writesInline($finding->isDirect() || $finding->namedIn() !== []) ? '^'.ltrim((string) $move->version(), 'vV') : '';
                // A plain name has no constraint that holds it at its fix.
                if ($constraint !== '' || $check->installsBelow((string) $move->version()) === null) {
                    $inline[$finding->package()] = $constraint;
                }
            }
        }
        $members = array_keys($inline);
        if ($members === []) {
            return new self([], null, null);
        }
        $outcome = $checks->checker()->combined($inline);
        if ($outcome->check()->isResolved()) {
            return self::reachingTheFix($inline, $outcome, $findings, $checks, null);
        }
        if (!$outcome->check()->isRefused()) {
            return new self([], null, RunNote::updateNowNotChecked((string) $outcome->check()->reason(), $members));
        }
        $named = [];
        foreach ($outcome->holds() as $hold) {
            $named[] = $hold->package();
            $named[] = $hold->target();
        }
        $kept = array_diff_key($inline, array_flip(array_filter($named, 'is_string')));
        $retry = $kept === [] || $kept === $inline ? null : $checks->checker()->combined($kept);
        if ($retry !== null && $retry->check()->isResolved()) {
            return self::reachingTheFix($kept, $retry, $findings, $checks, [array_keys(array_diff_key($inline, $kept)), (string) $outcome->problem()]);
        }
        if ($retry !== null && !$retry->check()->isRefused()) {
            return new self([], null, RunNote::updateNowNotChecked((string) $retry->check()->reason(), $members));
        }

        return new self([], null, RunNote::updateNowRefused((string) ($retry === null ? $outcome->problem() : $retry->problem())));
    }

    /**
     * A plain name has no constraint that holds it at its fix, so a resolving solve can keep it
     * below its move version. Such a member is left out, and the rest is asked once more, until the
     * solve installs each member at its fix.
     *
     * @param array<string, string>           $inline
     * @param list<Finding>                   $findings
     * @param array{list<string>, string}|null $refused the members that a refusal left out, and its problem
     */
    private static function reachingTheFix(array $inline, CheckOutcome $outcome, array $findings, MoveChecks $checks, ?array $refused): self
    {
        $below = [];
        $asked = $inline;
        // Each pass leaves out at least one member, so the loop ends.
        while (($short = self::belowTheFix($asked, $outcome->changes(), $findings)) !== []) {
            $below += $short;
            $asked = array_diff_key($asked, $short);
            if ($asked === []) {
                return new self([], null, self::belowNote($below, $findings, $refused));
            }
            $outcome = $checks->checker()->combined($asked);
            if ($outcome->check()->isRefused()) {
                return new self([], null, RunNote::updateNowRefused((string) $outcome->problem()));
            }
            if (!$outcome->check()->isResolved()) {
                return new self([], null, RunNote::updateNowNotChecked((string) $outcome->check()->reason(), array_keys($inline)));
            }
        }
        if ($below === []) {
            return self::resolved($asked, $findings, $refused === null ? null : RunNote::updateNowReduced($refused[0], $refused[1]));
        }

        return self::resolved($asked, $findings, self::belowNote($below, $findings, $refused));
    }

    /**
     * @param array<string, string> $inline
     * @param array<string, string> $changes the release of each name that the solve changes
     * @param list<Finding>         $findings
     *
     * @return array<string, array{string, string}> each plain member that the solve keeps below its move version => [the release that it gets, its move version]
     */
    private static function belowTheFix(array $inline, array $changes, array $findings): array
    {
        $below = [];
        foreach ($findings as $finding) {
            $move = $finding->nextStep();
            $fix = $move === null ? null : $move->version();
            $package = $finding->package();
            if (($inline[$package] ?? null) !== '' || $fix === null) {
                continue;
            }
            $gets = $changes[$package] ?? $finding->version();
            if (Comparator::lessThan($gets, $fix)) {
                $below[$package] = [$gets, $fix];
            }
        }

        return $below;
    }

    /**
     * @param non-empty-array<string, array{string, string}> $below
     * @param list<Finding>                                  $findings
     * @param array{list<string>, string}|null               $refused
     */
    private static function belowNote(array $below, array $findings, ?array $refused): RunNote
    {
        return RunNote::updateNowBelowFix(self::inReportOrder(array_merge($refused[0] ?? [], array_keys($below)), $findings), $below, $refused[1] ?? null);
    }

    /**
     * @param list<string>  $names
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private static function inReportOrder(array $names, array $findings): array
    {
        $ordered = [];
        foreach ($findings as $finding) {
            if (\in_array($finding->package(), $names, true)) {
                $ordered[] = $finding->package();
            }
        }

        return $ordered;
    }

    /**
     * The members are exactly the names of the command that Composer resolved.
     *
     * @param array<string, string> $inline
     * @param list<Finding>         $findings
     */
    private static function resolved(array $inline, array $findings, ?RunNote $note): self
    {
        $argv = self::argv($inline, $findings);

        return new self($argv === null ? [] : array_keys($inline), $argv, $note, $argv === null ? null : self::raiseOf($inline, $findings));
    }

    /**
     * The highest `raise_to` of the members' moves: each move raises require.php before its own
     * update, so the combined update needs the highest one.
     *
     * @param array<string, string> $inline
     * @param list<Finding>         $findings
     */
    private static function raiseOf(array $inline, array $findings): ?string
    {
        $raises = [];
        foreach ($findings as $finding) {
            $move = isset($inline[$finding->package()]) ? $finding->nextStep() : null;
            $check = $move === null ? null : $move->phpCheck();
            $to = $check === null ? null : $check->raiseTo();
            if ($to !== null) {
                $raises[] = $to;
            }
        }

        return self::highestRaise($raises);
    }

    /** @param list<string> $raises `>=` constraints */
    public static function highestRaise(array $raises): ?string
    {
        $highest = null;
        foreach ($raises as $raise) {
            if ($highest === null || version_compare($raise, $highest, '>')) {
                $highest = $raise;
            }
        }

        return $highest;
    }

    /** The `>=` constraint that require.php needs before the command, null when it needs none. */
    public function raiseTo(): ?string
    {
        return $this->raiseTo;
    }

    /** @return list<string> */
    public function members(): array
    {
        return $this->members;
    }

    /** @return list<string>|null `composer update` with each package's constraint inline, null with none or when Composer refuses them together */
    public function command(): ?array
    {
        return $this->command;
    }

    public function note(): ?RunNote
    {
        return $this->note;
    }

    /**
     * @param array<string, string> $inline
     * @param list<Finding>         $findings
     *
     * @return list<string>|null
     */
    private static function argv(array $inline, array $findings): ?array
    {
        $argv = ['composer', 'update'];
        foreach ($findings as $finding) {
            $package = $finding->package();
            if (isset($inline[$package])) {
                $argv[] = $inline[$package] === '' ? $package : $package.':'.$inline[$package];
            }
        }

        return CommandArgument::allPass($argv) ? $argv : null;
    }
}
