<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Explain\Explanation;
use Lockrot\Json\Schemas;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * `--explain <package>`: one package, every signal with its raw data, and the facts the signals
 * read — the lock entry, the repository's branches with their dates, the repository activity —
 * so "why is this flagged?" (or "why is it not?") is one command rather than `--format=json`
 * piped through jq. Text for a terminal, or the same as JSON under `--format=json`.
 *
 * The text is meant to be read top to bottom: the verdict and how the package is reached, then
 * what fired, then the data — a reader who disagrees with a signal finds the number it was
 * computed from two blocks down, in the same units the signal used.
 */
final class ExplainFormatter
{
    public const SCHEMA = JsonFormatter::SCHEMA;

    private const INDENT = '  ';

    /** Under a signal's `S8 warn ` line: the data hangs beneath the summary's first character. */
    private const DATA_INDENT = '           ';

    /** The signal data keys printed as a list of advisories rather than as scalars. */
    private const ADVISORY_LIST = 'advisories';

    public function text(Explanation $explanation): string
    {
        $finding = $explanation->finding();
        $lines = array_merge(
            $this->header($finding),
            [''],
            $this->signals($finding),
            [''],
            $this->lock($explanation),
            [''],
            $this->metadata($explanation),
            [''],
            $this->activity($explanation),
            [''],
            $this->run($explanation)
        );

        return implode("\n", $lines)."\n";
    }

    public function json(Explanation $explanation): string
    {
        $data = ['$schema' => Schemas::url(Schemas::EXPLAIN, self::SCHEMA), 'lockrot' => ['version' => JsonFormatter::VERSION, 'schema' => self::SCHEMA]] + $explanation->toArray();
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Cannot encode explanation as JSON: '.json_last_error_msg());
        }

        return $json."\n";
    }

    /** @return list<string> */
    private function header(Finding $finding): array
    {
        $verdict = $finding->verdict();
        $label = Verdict::flagged($verdict) ? '<fg=yellow>'.self::escape($verdict).'</fg=yellow>' : self::escape($verdict);
        $lines = [\sprintf('<options=bold>%s %s</> — %s, priority %s', self::escape($finding->package()), self::escape($finding->version()), $label, $finding->priority())];
        $how = $finding->isDirect() ? 'direct requirement' : 'via '.implode(' > ', $finding->chain());
        $others = $finding->otherDirectDependents();
        if ($others !== []) {
            $how .= '; also reached from '.implode(', ', $others);
        }
        $lines[] = self::INDENT.self::escape($how.($finding->isDev() ? ' · packages-dev' : ''));
        if ($finding->allowlistReason() !== null) {
            $lines[] = self::INDENT.self::escape('allowlisted: '.$finding->allowlistReason());
        }
        if ($finding->note() !== null) {
            $lines[] = self::INDENT.self::escape('note: '.$finding->note());
        }

        return $lines;
    }

    /**
     * Every signal on the finding, its summary first and its raw data under it — dates as the
     * repository gave them, so a reader can check the arithmetic in the summary.
     *
     * @return list<string>
     */
    private function signals(Finding $finding): array
    {
        if ($finding->signals() === []) {
            return ['signals: none'];
        }
        $lines = ['signals'];
        foreach ($finding->signals() as $signal) {
            $lines[] = \sprintf('%s%s %-4s %s', self::INDENT, $signal->id(), $signal->level(), self::escape($signal->summary()));
            foreach ($this->signalData($signal) as $line) {
                $lines[] = self::DATA_INDENT.self::escape($line);
            }
        }

        return $lines;
    }

    /**
     * A signal's data as lines: scalars joined `key value` on one line, the advisory list (S9) one
     * advisory per line, anything else as JSON.
     *
     * @return list<string>
     */
    private function signalData(Signal $signal): array
    {
        $scalars = [];
        $lines = [];
        foreach ($signal->data() as $key => $value) {
            if ($key === self::ADVISORY_LIST && \is_array($value)) {
                foreach ($value as $row) {
                    $lines[] = \is_array($row) ? self::advisory($row) : self::encode($row);
                }
                continue;
            }
            if (\is_scalar($value) || $value === null) {
                $scalars[] = $key.' '.self::scalar($value);
                continue;
            }
            $lines[] = $key.' '.self::encode($value);
        }
        if ($scalars !== []) {
            array_unshift($lines, implode(' · ', $scalars));
        }

        return $lines;
    }

    /**
     * `CVE-2026-69246 (PKSA-xxxx) high · fixed by 8.2.0, not on the installed branch · https://…`:
     * the row as {@see \Lockrot\Signal\Rule\AdvisoryRule} shapes it; a key it does not set is left out.
     *
     * @param array<mixed, mixed> $row
     */
    private static function advisory(array $row): string
    {
        $parts = [];
        $id = self::string($row['id'] ?? null);
        $cve = self::string($row['cve'] ?? null);
        $name = $cve ?? $id ?? '?';
        if ($cve !== null && $id !== null && $cve !== $id) {
            $name .= ' ('.$id.')';
        }
        $severity = self::string($row['severity'] ?? null);
        $parts[] = $severity === null ? $name : $name.' '.$severity;
        $fixedBy = self::string($row['fixed_by'] ?? null);
        if ($fixedBy === null) {
            $parts[] = 'no listed release fixes it';
        } else {
            $parts[] = 'fixed by '.$fixedBy.(($row['fixed_on_branch'] ?? null) === false ? ', not on the installed branch' : '');
        }
        $reported = self::string($row['reported_at'] ?? null);
        if ($reported !== null) {
            $parts[] = 'reported '.substr($reported, 0, 10);
        }
        $link = self::string($row['link'] ?? null);
        if ($link !== null) {
            $parts[] = $link;
        }

        return implode(' · ', $parts);
    }

    /** @return list<string> */
    private function lock(Explanation $explanation): array
    {
        $package = $explanation->facts()->package();
        $parts = ['version '.$package->version()];
        $parts[] = $package->requirePhp() === null ? 'no php constraint' : 'php '.$package->requirePhp();
        $parts[] = $package->time() === null ? 'undated' : 'released '.$package->time()->format('Y-m-d');
        $parts[] = $package->isFromComposerRepository() ? 'from a Composer repository' : 'not from a Composer repository';
        if ($package->isBranchSnapshot()) {
            $parts[] = 'branch snapshot';
        }
        $lines = ['composer.lock', self::INDENT.self::escape(implode(' · ', $parts))];
        if ($package->repositoryUrl() !== null) {
            $lines[] = self::INDENT.self::escape('source '.$package->repositoryUrl());
        }

        return $lines;
    }

    /**
     * What the Composer repository said about the package, and the branch table S8 reads: every
     * release branch with its highest tag and that tag's date, the installed branch marked.
     *
     * @return list<string>
     */
    private function metadata(Explanation $explanation): array
    {
        $metadata = $explanation->facts()->metadata();
        if ($metadata === null) {
            return ['repository metadata', self::INDENT.'none — '.self::escape($explanation->finding()->note() ?? 'not available')];
        }
        $lines = ['repository metadata (as of '.$metadata->dataDate()->format('Y-m-d').')'];
        $parts = [$metadata->releaseCount().' versions listed', $metadata->type()];
        if ($metadata->isAbandoned()) {
            $parts[] = $metadata->replacement() === null ? 'abandoned, no replacement named' : 'abandoned, replacement '.$metadata->replacement();
        } else {
            $parts[] = 'not abandoned';
        }
        $lines[] = self::INDENT.self::escape(implode(' · ', $parts));
        if ($metadata->repositoryUrl() !== null) {
            $lines[] = self::INDENT.self::escape('source '.$metadata->repositoryUrl());
        }
        $lines[] = self::INDENT.self::escape($this->lastRelease($metadata, $explanation));
        foreach ($this->branchTable($explanation) as $line) {
            $lines[] = self::INDENT.self::escape($line);
        }

        return $lines;
    }

    private function lastRelease(PackageMetadata $metadata, Explanation $explanation): string
    {
        if (!$metadata->hasStableRelease()) {
            return 'no stable release';
        }
        $at = $metadata->lastStableReleaseAt();
        if ($at !== null) {
            $datedBy = $metadata->lastStableDatedBy();

            return \sprintf('last stable release %s (%s%s)', (string) $metadata->lastStableVersion(), $at->format('Y-m-d'), $datedBy === null ? '' : ', dated by '.$datedBy);
        }
        $highest = '';
        foreach ($explanation->branches() as $row) {
            $highest = $row['highest'];
            break;
        }

        return \sprintf('last stable release unknown: the highest tag%s has no release date, so S2 does not measure the package', $highest === '' ? '' : ' '.$highest);
    }

    /** @return list<string> */
    private function branchTable(Explanation $explanation): array
    {
        $rows = $explanation->branches();
        if ($rows === []) {
            return [];
        }
        $lines = ['  branch     highest tag        released           newest dated release'];
        foreach (\array_slice($rows, 0, Explanation::BRANCH_ROWS) as $row) {
            if ($row['highest_released'] !== null) {
                $released = $row['highest_released']->format('Y-m-d');
            } elseif ($row['highest_commit_date'] !== null) {
                $released = 'commit '.$row['highest_commit_date']->format('Y-m-d');
            } else {
                $released = 'undated';
            }
            $newest = $row['newest_dated_released'] === null ? '—' : $row['newest_dated'].' ('.$row['newest_dated_released']->format('Y-m-d').')';
            $lines[] = \sprintf('%s%-10s %-18s %-18s %s', $row['installed'] ? '* ' : '  ', $row['branch'], $row['highest'], $released, $newest);
        }
        if (\count($rows) > Explanation::BRANCH_ROWS) {
            $lines[] = \sprintf('  … and %d more', \count($rows) - Explanation::BRANCH_ROWS);
        }
        if ($explanation->installedBranchIsUndated()) {
            $lines[] = '* the installed branch\'s highest tag has no release date — the repository leaves it undated, or dates it only by a commit other tags share (`commit …`: a subtree split, the day the directory last changed) — so S8 does not measure the branch';
        }
        $datedBy = $explanation->branchesDatedBy();
        if ($datedBy !== null) {
            [$parent, $branches] = $datedBy;
            $lines[] = \sprintf('%s %s dated by %s, the monorepo this package is split out of: its own tags there are dated by a commit other tags share, the monorepo\'s by their release', \count($branches) === 1 ? 'branch' : 'branches', implode(', ', $branches), $parent);
        }

        return $lines;
    }

    /** @return list<string> */
    private function activity(Explanation $explanation): array
    {
        $activity = $explanation->facts()->activity();
        if ($activity === null) {
            return ['repository activity', self::INDENT.'not fetched — S3 and S4 have nothing to read; the run\'s notes below say why when a cap or a failure is the cause'];
        }
        $parts = [$activity->ref()->forgeLabel().' '.$activity->repo()];
        $parts[] = $activity->isArchived() ? 'archived' : 'not archived';
        $parts[] = $activity->pushedAt() === null ? 'last push unknown' : 'last push '.$activity->pushedAt()->format('Y-m-d');
        $parts[] = 'fetched '.$activity->fetchedAt()->format('Y-m-d').($activity->fromCache() ? " (from lockrot's cache)" : '');

        return ['repository activity', self::INDENT.self::escape(implode(' · ', $parts))];
    }

    /** @return list<string> */
    private function run(Explanation $explanation): array
    {
        $thresholds = $explanation->thresholds();
        $lines = [\sprintf(
            'thresholds: release-warn-years %d · release-high-years %d · push-warn-years %d · push-high-years %d · target PHP %s',
            $thresholds->releaseWarnYears(),
            $thresholds->releaseHighYears(),
            $thresholds->pushWarnYears(),
            $thresholds->pushHighYears(),
            self::escape($explanation->targetPhp())
        )];
        foreach ($explanation->report()->notes() as $note) {
            $lines[] = 'note: '.self::escape($note);
        }

        return $lines;
    }

    /** @param null|scalar $value */
    private static function scalar($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /** @param mixed $value */
    private static function encode($value): string
    {
        $json = json_encode($value, \JSON_UNESCAPED_SLASHES);

        return $json === false ? '?' : $json;
    }

    /** @param mixed $value */
    private static function string($value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    private static function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}
