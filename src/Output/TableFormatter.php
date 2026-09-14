<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Verdict\Finding;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;

final class TableFormatter implements FormatterInterface
{
    public function format(Report $report, bool $showAll = false): string
    {
        $rows = $showAll ? $report->findings() : $report->flagged();
        $output = new BufferedOutput();
        if ($rows === []) {
            $output->writeln(\sprintf('No dependency rot found in %d packages.', $report->packagesChecked()));
        } else {
            $table = new Table($output);
            $table->setHeaders(['Package', 'Version', 'Verdict', 'Evidence', 'Via']);
            foreach ($rows as $finding) {
                $table->addRow([$finding->package(), $finding->version(), $finding->verdict(), $this->evidence($finding), $this->via($finding)]);
            }
            $table->render();
        }
        $output->writeln($this->summary($report));
        $output->writeln(\sprintf('Data as of %s (Packagist, GitHub). Run composer lockrot --format=json for details.', $report->generatedAt()->format('Y-m-d')));
        foreach ($report->notes() as $note) {
            $output->writeln('note: '.$note);
        }

        return $output->fetch();
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

    private function summary(Report $report): string
    {
        $parts = [\sprintf('%d packages checked', $report->packagesChecked())];
        foreach ($report->byVerdict() as $verdict => $count) {
            $parts[] = $verdict.' '.$count;
        }

        return implode(' · ', $parts);
    }
}
