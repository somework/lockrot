<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

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
                // Composer 2.2 and 2.3 list a package that composer.json does not require plain, and the check solves the line as printed.
                $inline[$finding->package()] = $checks->writesInline($finding->isDirect() || $finding->namedIn() !== []) ? '^'.ltrim((string) $move->version(), 'vV') : '';
            }
        }
        $members = array_keys($inline);
        if ($members === []) {
            return new self([], null, null);
        }
        $outcome = $checks->checker()->combined($inline);
        if ($outcome->check()->isResolved()) {
            return self::resolved($inline, $findings, null);
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
            return self::resolved($kept, $findings, RunNote::updateNowReduced(array_keys(array_diff_key($inline, $kept)), (string) $outcome->problem()));
        }
        if ($retry !== null && !$retry->check()->isRefused()) {
            return new self([], null, RunNote::updateNowNotChecked((string) $retry->check()->reason(), $members));
        }

        return new self([], null, RunNote::updateNowRefused((string) ($retry === null ? $outcome->problem() : $retry->problem())));
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
