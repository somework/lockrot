<?php

declare(strict_types=1);

namespace Lockrot\Solver;

/**
 * Checks no move, for one reason: a run with no composer.json, or a Composer that lacks the
 * solver's classes.
 *
 * @internal
 */
final class UncheckedMoves implements MoveChecker
{
    private string $reason;
    private ?string $configuredPhp;
    /** @var string|false|null */
    private $configuredPhp64bit;

    /**
     * @param ?string           $configuredPhp      `config.platform.php` of Composer's merged config, as {@see MoveCheckers::configuredPhp()} reads it
     * @param string|false|null $configuredPhp64bit as {@see MoveCheckers::configuredPhp64bit()} reads it
     */
    public function __construct(string $reason, ?string $configuredPhp = null, $configuredPhp64bit = null)
    {
        $this->reason = $reason;
        $this->configuredPhp = $configuredPhp;
        $this->configuredPhp64bit = $configuredPhp64bit;
    }

    public function baseline(): CheckOutcome
    {
        return new CheckOutcome(MoveCheck::notChecked($this->reason));
    }

    public function check(MoveQuery $query): CheckOutcome
    {
        return new CheckOutcome(MoveCheck::notChecked($this->reason));
    }

    public function joint(MoveQuery $query, array $holders): CheckOutcome
    {
        return new CheckOutcome(MoveCheck::notChecked($this->reason));
    }

    public function combined(array $inline): CheckOutcome
    {
        return new CheckOutcome(MoveCheck::notChecked($this->reason));
    }

    public function solveAt(array $names): CheckOutcome
    {
        return new CheckOutcome(MoveCheck::notChecked($this->reason));
    }

    public function platformPhp(): ?string
    {
        return null;
    }

    public function configuredPhp(): ?string
    {
        return $this->configuredPhp;
    }

    public function configuredPhp64bit()
    {
        return $this->configuredPhp64bit;
    }

    public function composerVersion(): ?string
    {
        return null;
    }

    public function solverError(): ?array
    {
        return null;
    }

    public function blocksAdvisories(): bool
    {
        return false;
    }

    public function blockingDisabledBy(): array
    {
        return [];
    }

    public function withAdvisories(array $records, array $covered): MoveChecker
    {
        return $this;
    }
}
