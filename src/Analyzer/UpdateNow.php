<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Solver\MoveCheck;
use Lockrot\Verdict\CommandArgument;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveChecks;

/**
 * `security.update_now` and its command (D9). The members are the vulnerable findings whose lead
 * move is an `update` that Composer's solver resolves inline. The command updates them in one
 * request. When Composer refuses them together, the command leaves out the members that the
 * refusal names and asks once more. When that fails too, the report writes no command.
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

    /**
     * @param list<string>      $members
     * @param list<string>|null $command
     */
    private function __construct(array $members, ?array $command, ?RunNote $note)
    {
        $this->members = $members;
        $this->command = $command;
        $this->note = $note;
    }

    /** @param list<Finding> $findings in report order */
    public static function of(array $findings, MoveChecks $checks): self
    {
        $inline = [];
        foreach ($findings as $finding) {
            $move = $finding->nextStep();
            $check = $move === null ? null : $move->check();
            if ($finding->securityStanding()->isVulnerable() && $move !== null && $move->kind() === Move::UPDATE && $check !== null && $check->isResolved() && $check->form() === MoveCheck::INLINE) {
                $inline[$finding->package()] = '^'.ltrim((string) $move->version(), 'vV');
            }
        }
        $members = array_keys($inline);
        if ($members === []) {
            return new self([], null, null);
        }
        $outcome = $checks->checker()->combined($inline);
        if (!$outcome->check()->isRefused()) {
            return new self($members, self::argv($inline, $checks, $findings), null);
        }
        $named = [];
        foreach ($outcome->holds() as $hold) {
            $named[] = $hold->package();
            $named[] = $hold->target();
        }
        $kept = array_diff_key($inline, array_flip(array_filter($named, 'is_string')));
        $retry = $kept === [] || $kept === $inline ? null : $checks->checker()->combined($kept);
        if ($retry !== null && !$retry->check()->isRefused()) {
            return new self($members, self::argv($kept, $checks, $findings), RunNote::updateNowReduced(array_keys(array_diff_key($inline, $kept)), (string) $outcome->problem()));
        }

        return new self($members, null, RunNote::updateNowRefused((string) ($retry === null ? $outcome->problem() : $retry->problem())));
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
    private static function argv(array $inline, MoveChecks $checks, array $findings): ?array
    {
        $direct = [];
        foreach ($findings as $finding) {
            $direct[$finding->package()] = $finding->isDirect();
        }
        $argv = ['composer', 'update'];
        foreach ($inline as $package => $constraint) {
            $argv[] = $checks->writesInline($direct[$package] ?? false) ? $package.':'.$constraint : $package;
        }

        return CommandArgument::allPass($argv) ? $argv : null;
    }
}
