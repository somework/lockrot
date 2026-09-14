<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;

interface FormatterInterface
{
    public function format(Report $report, bool $showAll = false): string;
}
