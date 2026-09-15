<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Verdict\Finding;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;

final class TableFormatter implements FormatterInterface
{
    public function format(Report $report, bool $showAll = false): string
    {
        $baseline = $report->baseline();
        $rows = $showAll ? $report->findings() : $report->flagged();
        $output = new BufferedOutput();
        if ($rows === []) {
            $output->writeln(\sprintf('No dependency rot found in %d packages.', $report->packagesChecked()));
        } else {
            $table = new Table($output);
            $table->setHeaders(['Package', 'Version', 'Verdict', 'Evidence', 'Via']);
            foreach ($rows as $finding) {
                $table->addRow([$finding->package(), $finding->version(), $this->verdict($finding, $baseline), $this->evidence($finding), $this->via($finding)]);
            }
            $table->render();
        }
        $output->writeln($report->summaryLine());
        if ($baseline !== null) {
            $output->writeln($baseline->summaryLine());
        }
        $output->writeln(\sprintf('Data as of %s (package repositories, GitHub). Run composer lockrot --format=json for details.', $report->generatedAt()->format('Y-m-d')));
        foreach ($report->notes() as $note) {
            $output->writeln('note: '.$note);
        }
        $stale = $baseline === null ? null : $baseline->staleNote();
        if ($stale !== null) {
            $output->writeln('note: '.$stale);
        }

        return $output->fetch();
    }

    /**
     * The verdict cell, annotated with how the baseline sees this package: `(baseline)` for a
     * finding the project already accepted, `(was stale)` for one that has got worse since. A new
     * finding, and every row of a run with no baseline, shows the bare verdict.
     */
    private function verdict(Finding $finding, ?BaselineComparison $baseline): string
    {
        if ($baseline === null) {
            return $finding->verdict();
        }
        $status = $baseline->statusOf($finding->package());
        if ($status === BaselineComparison::KNOWN) {
            return $finding->verdict().' (baseline)';
        }
        if ($status === BaselineComparison::WORSENED) {
            return $finding->verdict().' (was '.(string) $baseline->previousVerdictOf($finding->package()).')';
        }

        return $finding->verdict();
    }

    private function evidence(Finding $finding): string
    {
        $evidence = $finding->evidence();
        if ($finding->allowlistReason() !== null) {
            $evidence = ($evidence === '' ? '' : $evidence.'; ').'allowlisted: '.$finding->allowlistReason();
        }

        return wordwrap($evidence, 60, "\n", true);
    }

    private function via(Finding $finding): string
    {
        $chain = $finding->chain();
        if ($chain === []) {
            return '?';
        }
        array_pop($chain);

        return $chain === [] ? 'direct' : implode(' > ', $chain);
    }
}
