<?php

declare(strict_types=1);

namespace Lockrot\Html;

use Lockrot\Analyzer\Analysis;
use Lockrot\Signal\Thresholds;

/**
 * Every field is optional: the install-time path holds none of it and still renders a page. Without
 * `projectPhp`, the project column of the branch rows has no answer ({@see \Lockrot\Signal\PhpFloor}).
 *
 * @internal
 */
final class PageData
{
    private ?Analysis $analysis;
    private ?Thresholds $thresholds;
    private ?string $targetPhp;
    private ?string $projectPhp;

    public function __construct(
        ?Analysis $analysis = null,
        ?Thresholds $thresholds = null,
        ?string $targetPhp = null,
        ?string $projectPhp = null
    ) {
        $this->analysis = $analysis;
        $this->thresholds = $thresholds;
        $this->targetPhp = $targetPhp;
        $this->projectPhp = $projectPhp;
    }

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

    /** `require.php` as composer.json writes it. */
    public function projectPhp(): ?string
    {
        return $this->projectPhp;
    }
}
