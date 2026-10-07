<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Analyzer\LibyearsMeasurement;
use Lockrot\Lock\PackageOrigin;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Verdict;

/**
 * Builds a {@see Finding} for a test. The defaults: `vendor/pkg` 1.0.0, ok, no
 * signals, a chain of the package alone, no data date, no flags. A finding without flags throws
 * on grade(), lead(), isGraded() and score(). Every optional argument keeps the constructor's own
 * default. Each with*() returns a new builder.
 */
final class FindingBuilder
{
    private string $package = 'vendor/pkg';
    private string $version = '1.0.0';
    private string $verdict = Verdict::OK;
    /** @var list<Signal> */
    private array $signals = [];
    /** @var list<string> */
    private array $chain = ['vendor/pkg'];
    private ?string $allowlistReason = null;
    private ?\DateTimeImmutable $dataDate = null;
    private ?string $note = null;
    private bool $dev = false;
    /** @var list<string> */
    private array $directDependents = [];
    private ?LibyearsMeasurement $libyears = null;
    private ?PackageOrigin $origin = null;
    private ?string $replacementNamedBy = null;
    private ?FlagSet $flags = null;
    private bool $maintenanceJudged = true;

    public function withPackage(string $package): self
    {
        $clone = clone $this;
        $clone->package = $package;

        return $clone;
    }

    public function withVersion(string $version): self
    {
        $clone = clone $this;
        $clone->version = $version;

        return $clone;
    }

    public function withVerdict(string $verdict): self
    {
        $clone = clone $this;
        $clone->verdict = $verdict;

        return $clone;
    }

    /** @param list<Signal> $signals */
    public function withSignals(array $signals): self
    {
        $clone = clone $this;
        $clone->signals = $signals;

        return $clone;
    }

    /** @param list<string> $chain */
    public function withChain(array $chain): self
    {
        $clone = clone $this;
        $clone->chain = $chain;

        return $clone;
    }

    public function withAllowlistReason(?string $allowlistReason): self
    {
        $clone = clone $this;
        $clone->allowlistReason = $allowlistReason;

        return $clone;
    }

    public function withDataDate(?\DateTimeImmutable $dataDate): self
    {
        $clone = clone $this;
        $clone->dataDate = $dataDate;

        return $clone;
    }

    public function withNote(?string $note): self
    {
        $clone = clone $this;
        $clone->note = $note;

        return $clone;
    }

    public function withDev(bool $dev): self
    {
        $clone = clone $this;
        $clone->dev = $dev;

        return $clone;
    }

    /** @param list<string> $directDependents */
    public function withDirectDependents(array $directDependents): self
    {
        $clone = clone $this;
        $clone->directDependents = $directDependents;

        return $clone;
    }

    public function withLibyears(?LibyearsMeasurement $libyears): self
    {
        $clone = clone $this;
        $clone->libyears = $libyears;

        return $clone;
    }

    public function withOrigin(?PackageOrigin $origin): self
    {
        $clone = clone $this;
        $clone->origin = $origin;

        return $clone;
    }

    public function withReplacementNamedBy(?string $replacementNamedBy): self
    {
        $clone = clone $this;
        $clone->replacementNamedBy = $replacementNamedBy;

        return $clone;
    }

    public function withFlags(?FlagSet $flags, bool $maintenanceJudged = true): self
    {
        $clone = clone $this;
        $clone->flags = $flags;
        $clone->maintenanceJudged = $maintenanceJudged;

        return $clone;
    }

    public function build(): Finding
    {
        $finding = new Finding($this->package, $this->version, $this->verdict, $this->signals, $this->chain, $this->allowlistReason, $this->dataDate, $this->note, $this->dev, $this->directDependents, $this->libyears, $this->origin, $this->replacementNamedBy);

        return $this->flags === null ? $finding : $finding->withFlags($this->flags, $this->maintenanceJudged);
    }
}
