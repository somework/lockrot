<?php

declare(strict_types=1);

namespace Lockrot\Html;

use Lockrot\Analyzer\Report;

/**
 * The page's title and description, from report-2's `packages_checked` and the non-zero grades of
 * `counts`. lockrot-report pins both against the page goldens of tests/fixtures/contract/page-goldens.json.
 *
 * @internal
 */
final class PageHeading
{
    private int $checked;
    private int $critical;
    private int $high;
    private int $medium;
    private int $low;

    public function __construct(int $checked, int $critical, int $high, int $medium, int $low)
    {
        $this->checked = $checked;
        $this->critical = $critical;
        $this->high = $high;
        $this->medium = $medium;
        $this->low = $low;
    }

    public static function of(Report $report): self
    {
        $counts = $report->byGrade();

        return new self($report->packagesChecked(), $counts['critical'], $counts['high'], $counts['medium'], $counts['low']);
    }

    public function title(): string
    {
        if ($this->checked === 0) {
            return 'lockrot: composer.lock holds no package';
        }
        if ($this->flagged() === 0) {
            return 'lockrot: nothing flagged in '.$this->packages();
        }

        return 'lockrot: '.$this->flagged().' of '.$this->packages().' flagged';
    }

    public function description(): string
    {
        if ($this->checked === 0) {
            return 'lockrot found no package in composer.lock.';
        }
        if ($this->flagged() === 0) {
            return 'lockrot checked '.$this->packages().' in composer.lock and flagged none.';
        }
        $named = [];
        foreach (['critical' => $this->critical, 'high' => $this->high, 'medium' => $this->medium, 'low' => $this->low] as $grade => $count) {
            if ($count > 0) {
                $named[] = $count.' '.$grade;
            }
        }

        return 'lockrot flagged '.$this->flagged().' of '.$this->packages().' in composer.lock: '.implode(', ', $named)
            .'. Each finding lists every flag that fired, the sum behind its grade, and its next step.';
    }

    private function flagged(): int
    {
        return $this->critical + $this->high + $this->medium + $this->low;
    }

    private function packages(): string
    {
        return $this->checked.' '.($this->checked === 1 ? 'package' : 'packages');
    }
}
