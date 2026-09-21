<?php

declare(strict_types=1);

namespace Lockrot\Html;

use Lockrot\Analyzer\Analysis;
use Lockrot\Signal\Thresholds;

/**
 * What the page can show beyond the report itself, and what a caller can leave out.
 *
 * `--format=html` is the only format that wants more than a {@see \Lockrot\Analyzer\Report}: the
 * release branches come from the facts a run keeps only when asked
 * ({@see \Lockrot\Analyzer\Analyzer::analyzeWithFacts()}), the new/known/worsened column from the
 * baseline comparison, and both the thresholds and the target PHP are needed to explain a package
 * at all. Bundling them keeps {@see \Lockrot\Output\Formatters::for()} to one extra argument, and
 * every one of them is optional: the install-time path holds none of it and still renders a page.
 */
final class PageData
{
    private ?Analysis $analysis;
    private ?Thresholds $thresholds;
    private ?string $targetPhp;

    public function __construct(
        ?Analysis $analysis = null,
        ?Thresholds $thresholds = null,
        ?string $targetPhp = null
    ) {
        $this->analysis = $analysis;
        $this->thresholds = $thresholds;
        $this->targetPhp = $targetPhp;
    }

    /** Nothing but the report: no release branches, no baseline column, no thresholds. */
    public static function none(): self
    {
        return new self();
    }

    public function analysis(): ?Analysis
    {
        return $this->analysis;
    }


    public function thresholds(): ?Thresholds
    {
        return $this->thresholds;
    }

    public function targetPhp(): ?string
    {
        return $this->targetPhp;
    }
}
