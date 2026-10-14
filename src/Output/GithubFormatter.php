<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Config\Gate;
use Lockrot\Data\Advisory\AdvisoryRepository;
use Lockrot\Lock\LockLineIndex;
use Lockrot\Score\Standing;
use Lockrot\Verdict\Finding;

/**
 * GitHub Actions workflow commands, one annotation per package: docs/ci.md#-formatgithub. The syntax is documented at
 * https://docs.github.com/en/actions/writing-workflows/choosing-what-your-workflow-does/workflow-commands-for-github-actions
 * but not the escaping, which the runner's toolkit defines:
 * https://github.com/actions/toolkit/blob/main/packages/core/src/command.ts
 *
 * @internal
 */
final class GithubFormatter implements FormatterInterface
{
    /** The marks in the order the annotations sort by: GitHub caps each level per step. */
    private const MARKS = ['error', 'warning', 'notice'];

    private FormatContext $context;

    public function __construct(FormatContext $context)
    {
        $this->context = $context;
    }

    public function format(Report $report, bool $showAll = false): string
    {
        $lockPath = $this->context->lockPath();
        $index = $lockPath === null ? LockLineIndex::empty() : LockLineIndex::fromFile($lockPath);

        $gate = $this->context->gateOf($report);
        $byMark = array_fill_keys(self::MARKS, []);
        foreach ($this->context->recordFindings($report, $showAll) as $finding) {
            $mark = self::mark($finding, $report, $gate);
            $byMark[$mark][] = $this->annotation($finding, $mark, $index->lineOf($finding->package()), $report);
        }
        $lines = array_merge(...array_values($byMark));
        $gap = $this->context->advisoriesNotCheckedLevel($report);
        if ($gap !== null) {
            $lines[] = '::'.$gap.' title=lockrot::'.self::escapeData(self::gapText($report));
        }
        foreach ($this->notes($report) as $note) {
            $lines[] = '::notice title=lockrot::'.self::escapeData($note);
        }
        $name = static fn (string $package): string => $package;
        $updateNow = RunFooter::updateNow($report);
        foreach ([RunFooter::flags($report), RunFooter::depth($report, $name), RunFooter::security($report, false), $updateNow === null ? null : 'update now: '.$updateNow, RunGateText::render($gate, $this->context->runOf($report))] as $line) {
            // A plain log line, not an annotation. A package name comes unvalidated from the lock, so
            // fold line breaks into spaces: a `::` after one runs as a workflow command.
            if ($line !== null) {
                $lines[] = str_replace(["\r\n", "\r", "\n"], ' ', $line);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * `error` when the finding fails the gate, `notice` when it is not graded or the baseline knows
     * it, else `warning` (docs/ci.md#how-each-format-marks-a-finding).
     */
    private static function mark(Finding $finding, Report $report, Gate $gate): string
    {
        $standing = $gate->standingOf($finding->package());
        if ($standing !== null && $standing->fails()) {
            return 'error';
        }
        if (!$finding->isGraded()) {
            return 'notice';
        }
        $baseline = $report->baseline();
        $known = $baseline === null ? null : $baseline->standingOf($finding->package());

        return $known !== null && $known->status() === Standing::KNOWN ? 'notice' : 'warning';
    }

    /** @return list<string> */
    private function notes(Report $report): array
    {
        $notes = $report->notes();
        $baseline = $report->baseline();
        $stale = $baseline === null ? null : $baseline->staleNote();
        if ($stale !== null) {
            $notes[] = $stale;
        }

        return $notes;
    }

    /** The run-level record's message: each repository that failed or was not asked, with its words. */
    private static function gapText(Report $report): string
    {
        $parts = [];
        foreach ($report->advisoryLookup()->repositories() as $repository) {
            if ($repository->outcome() === AdvisoryRepository::FAILED || $repository->outcome() === AdvisoryRepository::NOT_ASKED) {
                $parts[] = RunFooter::repository($repository);
            }
        }

        return 'advisories not checked: '.($parts === [] ? 'the gate could not read the advisories of every package' : implode('; ', $parts));
    }

    private function annotation(Finding $finding, string $mark, ?int $line, Report $report): string
    {
        $properties = ['file='.self::escapeProperty($this->context->lockName())];
        if ($line !== null) {
            $properties[] = 'line='.$line;
        }
        $unchecked = $this->context->isUncheckedRecord($finding);
        $flags = $unchecked ? 'unchecked' : self::titleFlags($finding);
        $properties[] = 'title='.self::escapeProperty('lockrot: '.$finding->verdict().($flags === '' ? '' : ' — '.$flags));

        return \sprintf('::%s %s::%s', $mark, implode(',', $properties), self::escapeData($unchecked ? FindingBlock::uncheckedMessage($finding) : self::message($finding, $report)));
    }

    /** The counted flags, with the deciding advisory's severity after `vulnerable`, empty for a score of 0. */
    private static function titleFlags(Finding $finding): string
    {
        $flags = $finding->flagIds();
        $security = $finding->scoreBasis()->securityTerm();

        return implode(' · ', $flags).($security === null ? '' : ' ('.$security->severity().' advisory)');
    }

    private static function message(Finding $finding, Report $report): string
    {
        $baseline = $report->baseline();
        $suffix = FindingBlock::baselineSuffix($finding, $baseline === null ? null : $baseline->standingOf($finding->package()));
        $parts = [FindingBlock::scoreLine($finding).($suffix === null ? '' : ' '.$suffix).'.'];
        $move = FindingBlock::move($finding);
        if ($move !== null) {
            $parts[] = 'Do: '.$move.'.';
        }
        foreach (FindingBlock::evidence($finding) as $flag => $summary) {
            $parts[] = $flag.': '.$summary.'.';
        }
        foreach ([FindingBlock::markingLine($finding), FindingBlock::checksMissing($finding), FindingBlock::checksAccepted($finding)] as $text) {
            if ($text !== null) {
                $parts[] = $text.'.';
            }
        }
        $allowlist = FindingBlock::allowlist($finding);
        if ($allowlist !== null) {
            $parts[] = $allowlist[0].': '.$allowlist[1].'.';
        }
        if (!$finding->isDirect() && $finding->directDependents() !== []) {
            $parts[] = '(via '.FindingBlock::dependents($finding).')';
        }

        return $finding->package().' '.$finding->version().': '.implode(' ', $parts);
    }


    private static function escapeData(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private static function escapeProperty(string $value): string
    {
        return str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $value);
    }
}
