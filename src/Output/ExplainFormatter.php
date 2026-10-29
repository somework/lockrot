<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Forge\ActivityLookup;
use Lockrot\Explain\Explanation;
use Lockrot\Json\JsonWriter;
use Lockrot\Json\Schemas;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Score\IfApplied;
use Lockrot\Score\MaintenanceTerm;
use Lockrot\Security\BranchFixes;
use Lockrot\Security\Holder;
use Lockrot\Signal\Data\AdvisoryData;
use Lockrot\Signal\Data\AdvisoryRow;
use Lockrot\Signal\Signal;
use Lockrot\Solver\MoveCheck;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\ListWords;
use Lockrot\Verdict\MoveText;
use Lockrot\Verdict\ScoreModel;

/**
 * `--explain <package>` as text, or as explain-2 JSON under `--format=json`
 * (docs/configuration.md#explaining-one-package). Each text block is a
 * rendering of explain-2's fields. It is {@see ConsoleMarkup} for {@see ConsoleMarkup::render()},
 * with all text from the lock and the repositories escaped.
 *
 * @internal
 */
final class ExplainFormatter
{
    public const SCHEMA = JsonFormatter::SCHEMA;

    private const INDENT = '  ';
    /** The label column of a block: `age`, `score`, `do`. */
    private const LABEL = 10;
    /** The narrowest width of each branch table column before `fixes`: branch, highest tag, released, php. */
    private const BRANCH_COLUMNS = [8, 13, 12, 10];

    /** The label column of a line in the flags block. */
    private const FLAG = 13;

    private const LOOKUP_WORDS = ['complete' => 'lookup complete', 'partial' => 'lookup partial', 'not_run' => 'not checked'];
    private const FIX_WORDS = ['update' => 'composer update', 'upgrade' => 'upgrade', 'raise-php' => 'php floor raise', 'unknown' => 'fix unknown', 'blocked' => 'blocked', 'none' => 'no fix'];
    private const MARKED_BY = ['repository' => 'registry', 'lock' => 'lock'];
    private const IGNORE_LIST = ['policy.abandoned' => 'config.policy.abandoned.ignore', 'audit.ignore-abandoned' => 'config.audit.ignore-abandoned'];

    public function text(Explanation $explanation): string
    {
        $finding = $explanation->finding();
        $lines = array_merge(
            $this->header($finding, $explanation),
            [''],
            self::block('age', AgeText::block($finding, $explanation->thresholds())),
            [self::escape('repository '.self::repositoryLine($finding))],
            [''],
            $this->score($finding),
            [''],
            $this->move($finding),
            [''],
            $this->flags($finding),
            [''],
            $this->branches($explanation)
        );
        foreach ($explanation->report()->notes() as $note) {
            $lines[] = 'note: '.self::escape($note);
        }

        return implode("\n", $lines)."\n";
    }

    public function json(Explanation $explanation): string
    {
        $data = ['$schema' => Schemas::url(Schemas::EXPLAIN, self::SCHEMA), 'lockrot' => ['version' => JsonFormatter::VERSION, 'schema' => self::SCHEMA]] + $explanation->toArray();
        $json = JsonWriter::encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if ($json === null) {
            throw new \RuntimeException('Cannot encode explanation as JSON: '.json_last_error_msg());
        }

        return TerminalText::breakJsonLogCommands($json)."\n";
    }

    /** @return list<string> the header and the reach line */
    private function header(Finding $finding, Explanation $explanation): array
    {
        $verdict = $finding->verdict();
        $colour = FindingBlock::colour($verdict);
        $label = $colour === null ? self::escape($verdict) : '<fg='.$colour.'>'.self::escape($verdict).'</fg='.$colour.'>';
        $flags = $finding->flagIds();
        return [
            '<options=bold>'.self::escape($finding->package().' '.$finding->version()).'</options=bold> — '.$label.($flags === [] ? '' : ': '.self::escape(implode(' · ', $flags))),
            self::INDENT.self::escape(self::reachLine($finding, $explanation->report()->includesDev())),
        ];
    }

    /** The reach line, from `depth`, `depth_unmeasured`, `named_in` and `chain`. */
    public static function reachLine(Finding $finding, bool $includeDev): string
    {
        $depth = $finding->depth();
        $namedIn = $finding->namedIn();
        if ($depth === 0) {
            return 'direct requirement (composer.json '.($namedIn === [ProjectConfig::REQUIRE_DEV] ? 'require-dev' : 'require').')';
        }
        if ($depth === null) {
            return $finding->depthUnmeasured() === Finding::NO_COMPOSER_JSON
                ? 'depth not measured: the run read no composer.json'
                : 'unreached: lockrot found no path from your '.($includeDev ? 'require or require-dev' : 'require (require-dev counts only with --dev)').' to it';
        }
        $via = 'via '.implode(' > ', $finding->chain()).': '.$depth.' '.($depth === 1 ? 'hop' : 'hops');
        if ($namedIn === [ProjectConfig::REQUIRE_DEV]) {
            return $via.'; composer.json names it in require-dev, which this run did not walk (pass --dev)';
        }

        return $via.' from the nearest composer.json line'.($namedIn === [] ? '; no line of composer.json names it' : '');
    }

    /** The repository line, from `activity` and `age.push`. */
    public static function repositoryLine(Finding $finding): string
    {
        $lookup = $finding->details()->activity();
        $where = $lookup->where();
        $ref = $where->ref();
        $reason = CauseWords::of((string) $lookup->reason());
        if ($lookup->status() === ActivityLookup::NOT_READ) {
            if ($lookup->reason() === ActivityLookup::HOST_NOT_SUPPORTED) {
                return 'lockrot cannot ask '.$where->host().' about repository activity';
            }

            return $ref === null ? 'repository activity not asked ('.$reason.')' : $ref->host().'/'.$ref->path().': not asked ('.$reason.')';
        }
        $name = $ref === null ? $where->host() : $ref->forgeLabel().' '.$ref->host().'/'.$ref->path();
        if ($lookup->status() === ActivityLookup::NOT_FOUND) {
            return $name.': the host answered 404 (private, renamed or removed)';
        }
        $event = $lookup->event() ?? 'push';
        $push = $finding->details()->age()->push();
        $line = $name.': '.($push->isMeasured() ? 'last '.$event.' '.$push->measuredAt()->format('Y-m-d') : 'no '.$event.' reported');
        $activity = $lookup->activity();
        if ($activity !== null && $activity->archived() === true) {
            $line .= ', archived';
        } elseif ($lookup->archivedUnread() !== null) {
            $line .= ', archived flag not read ('.CauseWords::of($lookup->archivedUnread()).')';
        }
        if ($activity !== null) {
            $cached = $activity->cachedAt();
            $line .= ' · fetched '.$activity->fetchedAt()->format('Y-m-d').($cached === null ? '' : ' (cached '.$cached->format('Y-m-d').')');
        }

        return $line;
    }

    /** @return list<string> the score block: its line with the band floor, the terms, the reach and the bands */
    private function score(Finding $finding): array
    {
        $basis = $finding->scoreBasis();
        $verdict = $finding->verdict();
        $lines = [FindingBlock::scoreLine($finding).($finding->isGraded() ? '     '.$verdict.' from '.ScoreModel::floorOf($verdict) : '')];
        $maintenance = array_map(static fn (MaintenanceTerm $term): string => $term->flag().' '.$term->points().' ('.($term->isLead() ? 'lead' : '¼ of '.$term->weight()).')', $basis->maintenanceTerms());
        if ($maintenance !== []) {
            $lines[] = self::INDENT.'maintenance  '.implode(' · ', $maintenance);
        }
        $security = $basis->securityTerm();
        if ($security !== null) {
            $lines[] = self::INDENT.'security     vulnerable '.$security->points().' ('.$security->severity().' advisory '.FindingBlock::advisoryName($finding, $security->advisory()).($security->multiplier() === 1 ? '' : '; '.$security->weight().' × '.$security->multiplier().': no reachable fix').')';
        }
        $lines[] = self::INDENT.'reach        '.self::reachWords($finding, $maintenance !== []);
        foreach ($basis->modifiers() as $modifier) {
            if ($modifier->isDev() && $modifier->changes()) {
                $lines[] = self::INDENT.'dev          packages-dev: the score ÷ '.$modifier->divideBy();
            }
        }
        $bands = [];
        foreach (array_reverse(ScoreModel::BANDS, true) as $band => $floor) {
            $bands[] = $band.' '.$floor;
        }
        $lines[] = self::INDENT.'bands        '.implode(' · ', $bands).' (score model '.ScoreModel::ID.', verdicts.md#score)';

        return array_merge(self::block('score', [$lines[0]]), array_map([self::class, 'escape'], \array_slice($lines, 1)));
    }

    private static function reachWords(Finding $finding, bool $maintenance): string
    {
        if ($finding->isDirect()) {
            return 'direct requirement: maintenance not halved for reach';
        }
        $where = $finding->reach() === 'unreached' ? 'unreached' : 'via '.ListWords::of($finding->directDependents());

        return $where.': maintenance '.($maintenance ? 'halved' : 'would be halved (none counts)').'; security is never halved for reach';
    }

    /** @return list<string> the do block: the move, each other move and what it clears */
    private function move(Finding $finding): array
    {
        $move = $finding->isGraded() ? $finding->nextStep() : null;
        if ($move === null) {
            return self::block('do', ['nothing to do: nothing counts']);
        }
        $lines = [$move->text()];
        $clears = [];
        foreach ($move->clears() as $fact) {
            $clears[] = $fact->id();
        }
        if ($clears !== []) {
            $lines[] = 'clears '.ListWords::of($clears);
        }
        foreach ($move->also() as $also) {
            $lines[] = 'also: '.$also->text();
        }

        return self::block('do', $lines);
    }

    /** @return list<string> the flags block, the feeds line, the ignored marking, the accepted flags and the checks not run */
    private function flags(Finding $finding): array
    {
        $lines = ['flags'];
        $summaries = $finding->flagSummaries();
        $standing = $finding->securityStanding();
        foreach ($summaries as $flag => $summary) {
            $signals = [];
            foreach ($finding->signals() as $signal) {
                if (\in_array($signal->id(), ScoreModel::RAISED_BY[$flag] ?? [], true)) {
                    $signals[] = $signal->id().($signal->level() === Signal::LEVEL_HIGH ? ' high' : '');
                }
            }
            $tail = $flag === 'vulnerable' ? ' · '.(self::LOOKUP_WORDS[$standing->check()] ?? $standing->check()) : '';
            $lines[] = self::flagLine($flag, implode(', ', $signals).' · '.$summary.$tail);
            if ($flag === 'vulnerable') {
                foreach (self::advisoryLines($finding) as $line) {
                    $lines[] = $line;
                }
            }
        }
        $feeds = self::feedsLine($finding);
        if ($feeds !== null) {
            $lines[] = self::flagLine('feeds', $feeds);
        }
        $ignored = self::ignoredLine($finding);
        if ($ignored !== null) {
            $lines[] = self::flagLine('S1 ignored', $ignored);
        }
        $pulls = FindingBlock::pullsIn($finding);
        if ($pulls !== null) {
            $lines[] = self::flagLine('pulls in', $pulls);
        }
        $accepted = array_map(static fn ($row): string => $row->flag(), $finding->scoreBasis()->accepted());
        $lines[] = self::flagLine('accepted', $accepted === [] ? 'none' : implode(', ', $accepted));
        $lines[] = self::flagLine('not checked', self::notChecked($finding));

        return $lines;
    }

    private static function flagLine(string $label, string $text): string
    {
        return self::INDENT.self::escape(str_pad($label, self::FLAG - 1).' '.$text);
    }

    /** @return list<string> one entry per counted advisory: severity, CVE and title, then the id, the date, the points and the fix */
    private static function advisoryLines(Finding $finding): array
    {
        $s9 = $finding->signalData(AdvisoryData::class);
        $lines = [];
        foreach ($s9 === null ? [] : $s9->rows() as $row) {
            $lines[] = self::INDENT.self::INDENT.self::escape(str_pad($row->severity(), self::FLAG - 3).' '.($row->cve() ?? $row->id()).'  '.$row->title());
            $lines[] = str_repeat(' ', \strlen(self::INDENT) + self::FLAG).self::escape(self::advisoryFacts($row));
        }

        return $lines;
    }

    private static function advisoryFacts(AdvisoryRow $row): string
    {
        $fix = $row->fix();
        $parts = [$row->id()];
        $reported = $row->reportedAt();
        if ($reported !== null) {
            $parts[] = 'reported '.substr($reported, 0, 10);
        }
        $parts[] = $row->points().' points';
        $parts[] = 'fix: '.(self::FIX_WORDS[$fix->kind()] ?? $fix->kind()).($fix->toBranch() === null ? '' : ' to '.$fix->toBranch()).($fix->version() === null ? '' : ' from '.$fix->version());

        return implode(' · ', $parts);
    }

    /**
     * The feeds line: each feed's answer for this name. The feed with the most records
     * adds how many affect the version, when no other feed answered or it holds every record.
     */
    private static function feedsLine(Finding $finding): ?string
    {
        $lookup = $finding->details()->advisoryLookup();
        if ($lookup === null || $lookup->feeds() === []) {
            return null;
        }
        $answered = 0;
        $most = null;
        foreach ($lookup->feeds() as $feed) {
            if ($feed->answer() === AdvisoryCoverage::ANSWERED) {
                ++$answered;
                $most = $most === null || $feed->records() > $most->records() ? $feed : $most;
            }
        }
        $s9 = $finding->signalData(AdvisoryData::class);
        $affect = ($s9 === null ? 0 : \count($s9->rows())) + $finding->securityStanding()->ignoredCount();
        $parts = [];
        foreach ($lookup->feeds() as $feed) {
            switch ($feed->answer()) {
                case AdvisoryCoverage::ANSWERED:
                    $records = $feed->records();
                    $words = $records === 0 ? 'none on record' : $records.' on record';
                    if ($feed === $most && $records > 0 && ($answered === 1 || $lookup->records() === $records)) {
                        $words .= ', '.$affect.' '.($affect === 1 ? 'affects' : 'affect').' '.$finding->version();
                    }
                    break;
                case AdvisoryCoverage::NOT_ASKED:
                    $words = 'not asked ('.FindingBlock::checkWords($finding, (string) $feed->reason()).')';
                    break;
                default:
                    $words = 'lookup failed';
            }
            $parts[] = ($feed->registry() ?? $feed->composerRepository()).': '.$words;
        }

        return implode(' · ', $parts);
    }

    /** The abandoned marking that Composer's ignore list removed, as the project's list words it. */
    private static function ignoredLine(Finding $finding): ?string
    {
        $ignored = $finding->details()->abandonedIgnored();
        if ($ignored === null) {
            return null;
        }
        $patterns = [];
        $reasons = [];
        $constraints = [];
        foreach ($ignored->rules() as $rule) {
            $patterns[] = $rule->pattern();
            if ($rule->reason() !== null) {
                $reasons[] = $rule->reason();
            }
            $constraints = array_merge($constraints, $rule->constraints());
        }
        $replacement = $ignored->replacement();
        $line = 'marked abandoned by its '.(self::MARKED_BY[$ignored->markedBy()] ?? $ignored->markedBy()).($replacement === null ? '' : ' (replacement: '.$replacement.')')
            .': ignored, '.(self::IGNORE_LIST[$ignored->by()] ?? $ignored->by()).' lists '.implode(', ', $patterns).($reasons === [] ? '' : ' ("'.implode('; ', $reasons).'")').'; not counted.';
        if ($constraints !== []) {
            $line .= ' The constraint '.implode(', ', array_unique($constraints)).' on that entry is not applied, as composer audit does not apply it.';
        }

        return $line;
    }

    /** `checks_missing[]` and `checks_skipped[]` with their words; an accepted check names its entry. */
    private static function notChecked(Finding $finding): string
    {
        $parts = [];
        foreach (array_merge($finding->checksMissing(), $finding->details()->skipped()) as $check) {
            if ($check->reason() !== 'accepted_by_config') {
                $parts[] = implode(', ', $check->blocks()).' ('.FindingBlock::checkWords($finding, $check->reason()).')';
            }
        }
        foreach ($finding->details()->uncheckedAccepted() as $accepted) {
            $entry = $accepted->entry();
            $check = $accepted->unchecked();
            $until = $entry->expiresOn() === null ? '' : ', until '.$entry->expiresOn();
            $parts[] = implode(', ', $check->blocks()).' ('.FindingBlock::checkWords($finding, $check->reason()).'; '.CauseWords::of('accepted_by_config').' ('.$entry->scope().' '.$entry->value().$until.'): '.$entry->reason().')';
        }

        return $parts === [] ? 'nothing' : implode(' · ', $parts);
    }

    /**
     * The repository metadata table: one row per release branch, the installed one marked `*`, and
     * what each branch fixes of the counted advisories, with its holders and the score after it.
     *
     * @return list<string>
     */
    private function branches(Explanation $explanation): array
    {
        $finding = $explanation->finding();
        $rows = $explanation->branches();
        if ($rows === []) {
            return ['repository metadata', self::INDENT.self::escape('none — '.($finding->note() ?? 'not available'))];
        }
        $fixes = [];
        $packageFixes = $finding->details()->fixes();
        foreach ($packageFixes === null ? [] : $packageFixes->branches() as $branch) {
            $fixes[$branch->branch()] = $branch;
        }
        $moveBranch = self::securityMoveBranch($finding);
        $moves = $finding->details()->moves();
        $cells = [];
        foreach (\array_slice($rows, 0, Explanation::BRANCH_ROWS) as $row) {
            $released = $row['highest_released'] === null ? ($row['highest_commit_date'] === null ? 'undated' : 'commit '.$row['highest_commit_date']->format('Y-m-d')) : $row['highest_released']->format('Y-m-d');
            $fix = isset($fixes[$row['branch']]) ? self::fixesCell($fixes[$row['branch']], $moves->rowIfApplied($row['branch']), $moves->rowCheck($row['branch']), $row['branch'] === $moveBranch, $finding->package(), $moves->rowIsJoint($row['branch'])) : '';
            $cells[] = [$row['installed'] ? '*' : ' ', [$row['branch'], $row['highest'], $released, $row['php'] ?? '—'], $fix];
        }
        $widths = self::BRANCH_COLUMNS;
        foreach ($cells as [, $columns]) {
            foreach ($columns as $i => $column) {
                $widths[$i] = max($widths[$i], TerminalText::characters($column));
            }
        }
        $lines = ['repository metadata', self::escape(self::branchRow(' ', ['branch', 'highest tag', 'released', 'php'], 'fixes', $widths))];
        foreach ($cells as [$mark, $columns, $fix]) {
            $lines[] = self::escape(self::branchRow($mark, $columns, $fix, $widths));
        }
        if (\count($rows) > Explanation::BRANCH_ROWS) {
            $lines[] = \sprintf('     … and %d more', \count($rows) - Explanation::BRANCH_ROWS);
        }
        if ($explanation->installedBranchIsUndated()) {
            $lines[] = '* the installed branch\'s highest tag has no release date — the repository leaves it undated, or dates it only by a commit other tags share (`commit …`: a subtree split, the day the directory last changed) — so S8 does not measure the branch';
        }
        $datedBy = $explanation->branchesDatedBy();
        if ($datedBy !== null) {
            [$parent, $branches] = $datedBy;
            $lines[] = self::escape(\sprintf('%s %s dated by %s, the monorepo this package is split out of: its own tags there are dated by a commit other tags share, the monorepo\'s by their release', \count($branches) === 1 ? 'branch' : 'branches', ListWords::of($branches), $parent));
        }

        return $lines;
    }

    /** The branch of the security move, where `security.move_in` names it: the next step or its first other move. */
    private static function securityMoveBranch(Finding $finding): ?string
    {
        $move = $finding->details()->moves()->securityMove();

        return $move === null ? null : $move->toBranch();
    }

    /** `{fixed} of {of}`, the fix word, `(lowest fix)` on the move's row, the holders unless the joint request resolves the move, and the score after it. */
    private static function fixesCell(BranchFixes $row, ?IfApplied $after, ?MoveCheck $check, bool $moveRow, string $package, bool $joint): string
    {
        $cell = $row->fixed().' of '.$row->of();
        $candidate = $row->candidate();
        if ($candidate === null) {
            return $cell;
        }
        $cell .= ' · '.self::FIX_WORDS[$candidate->kind()].($moveRow ? ' (lowest fix)' : '');
        $holders = array_map(static fn (Holder $holder): string => $holder->source() === Holder::ROOT ? 'composer.json'
            : ($holder->package() === $package ? $package.' '.$holder->version().', its own release' : (string) $holder->package()), MoveText::inTextOrder($joint ? [] : $candidate->heldBy(), $package));
        if ($holders !== []) {
            $cell .= '; held by '.ListWords::of(array_unique($holders));
        }
        if ($after !== null) {
            // D10: the caveat stays only where Composer's solver did not check the move.
            $checked = $check !== null && $check->result() !== MoveCheck::NOT_CHECKED;
            $cell .= '; after it: '.($after->atMost() ? 'at most ' : '').$after->total().', '.$after->verdict().($checked ? '' : ', if Composer resolves it');
            if ($check !== null && $checked) {
                $cell .= $check->isResolved() ? "; Composer's solver resolves it" : "; Composer's solver refuses it";
            }
        }

        return $cell;
    }

    /**
     * @param list<string> $columns
     * @param list<int>    $widths  in characters, which `sprintf()` padding would count as bytes
     */
    private static function branchRow(string $mark, array $columns, string $fix, array $widths): string
    {
        $row = '   '.$mark;
        foreach ($columns as $i => $column) {
            $row .= ' '.$column.str_repeat(' ', $widths[$i] - TerminalText::characters($column));
        }

        return rtrim($row.' '.$fix);
    }

    /**
     * Lines under a label: the label in the first {@see LABEL} columns of the first line, the others
     * aligned beneath. $escaped lines are markup already.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function block(string $label, array $lines, bool $escaped = false): array
    {
        $out = [];
        foreach ($lines as $i => $line) {
            $out[] = ($i === 0 ? str_pad($label, self::LABEL) : str_repeat(' ', self::LABEL)).($escaped ? $line : self::escape($line));
        }

        return $out;
    }

    private static function escape(string $text): string
    {
        return ConsoleMarkup::escape(TerminalText::neutralise($text));
    }
}
