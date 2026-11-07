<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Composer;

/**
 * Checks no move, for one reason that leaves Composer nothing to solve. The run still names the
 * running Composer and the advisory blocking of its config.
 *
 * @internal
 */
final class UncheckedMoves implements MoveChecker
{
    private string $reason;
    private AdvisoryBlocking $blocking;
    private ?string $configuredPhp;
    /** @var string|false|null */
    private $configuredPhp64bit;

    /**
     * @param AdvisoryBlocking  $blocking           the advisory blocking of the running Composer's config
     * @param ?string           $configuredPhp      `config.platform.php` of Composer's merged config, as {@see MoveCheckers::configuredPhp()} reads it
     * @param string|false|null $configuredPhp64bit as {@see MoveCheckers::configuredPhp64bit()} reads it
     */
    public function __construct(string $reason, AdvisoryBlocking $blocking, ?string $configuredPhp = null, $configuredPhp64bit = null)
    {
        $this->reason = $reason;
        $this->blocking = $blocking;
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

    public function composerVersion(): string
    {
        return Composer::getVersion();
    }

    public function solverError(): ?array
    {
        return null;
    }

    public function blocksAdvisories(): bool
    {
        return $this->blocking->blocks();
    }

    public function blockingDisabledBy(): array
    {
        return $this->blocking->disabledBy();
    }

    public function withAdvisories(array $records, array $covered): MoveChecker
    {
        return $this;
    }
}
