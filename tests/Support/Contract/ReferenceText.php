<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

use Lockrot\Tests\Support\ScoreLineFromJson;
use Lockrot\Verdict\ShellCommand;

/**
 * The strings of SPEC-0.14.md §7.11, rendered again from a decoded document alone, as the reference
 * model renders them. It shares no code with lockrot's grammars. Each renderer reads only the fields
 * that {@see Fields::only()} keeps. The words come from tests/fixtures/contract/en/. FlagSentence,
 * SignalSentence and NoteSentence are held by their goldens, not rendered here.
 */
final class ReferenceText
{
    /** The inputs of each string, as paths of `x-rendered-from`. A string outside the JSON names its own list. */
    public const INPUTS = [
        'score.text' => ['score.terms', 'score.modifiers', 'score.accepted', 'score.exact', 'score.total', 'score.rounded_down'],
        'next_step.text' => ['next_step', 'package', 'direct', 'direct_dependents', 'run.target_php', 'run.project_php_lowest'],
        'also.text' => ['next_step.also[]', 'package', 'direct', 'direct_dependents', 'run.target_php', 'run.project_php_lowest'],
        'gate.text' => ['gate', 'run.fail_on', 'run.fail_on_source', 'run.gates', 'run.mode', 'run.target_php', 'run.target_php_source'],
        'evidence' => ['flags[].id', 'flags[].summary', 'score.terms[].flag'],
        'gate_line' => ['gate.basis', 'score.total'],
        'age_line' => ['age', 'version', 'branch', 'activity.event'],
        'explain_age' => ['age', 'version', 'branch', 'activity.event', 'run.thresholds', 'libyears', 'libyears_at_least', 'libyears_unmeasured'],
        'baseline_suffix' => ['baseline', 'flags[].id', 'flags[].baseline', 'signals[].id', 'signals[].data.advisories[].id', 'signals[].data.advisories[].cve', 'run.target_php', 'run.fix_model'],
    ];

    private const RESOLVED = ['require', 'raise-php', 'tag'];
    private const ROW_KINDS = ['update', 'require', 'raise-php'];
    private const DEPENDENTS_SHOWN = 3;
    private const NO_MOVE = [
        'package_quiet' => '—',
        'higher_undated' => 'no move: lockrot could not date the releases of the higher branches',
        'no_higher_release' => 'no move: no higher branch released within release-warn-years',
        'releases_unknown' => 'no move: lockrot could not read its releases, so it names no fix',
        'affected_range_unknown' => 'no move: no advisory names the versions it affects, so lockrot names no fix',
        'metadata_not_read' => 'no move: lockrot did not read its releases, so it names none',
        'local_package' => 'no move: it is installed from a local path (your own package)',
    ];
    private const ADDS = ['released_before_ga' => "it was released before your target PHP's major", 'branch_not_releasing' => 'that line no longer releases while a higher line does'];
    private const LIMITED_BY = ['score' => 'the score is lower', 'cap' => 'the new facts bound it', 'equal' => 'both agree'];
    private const LOST = ['none', 'blocked'];
    private const NOT_CHECKED = [
        'baseline_refused' => 'Composer refuses the lock itself',
        'unloaded_name' => 'a package it needs could not be loaded',
        'offline' => "a package it needs is not in Composer's cache",
        'install_time_budget' => 'the install-time budget ran out',
        'not_from_composer_repository' => 'no Composer repository serves it',
        'composer_too_old' => 'this Composer has no solver that lockrot can use',
        'no_composer_json' => 'the project has no composer.json',
    ];
    private const GRADES = ['critical', 'high', 'medium', 'low'];

    /** @param array<mixed, mixed> $in a finding's fields with `run`; `next_step` is the move to render */
    public static function move(array $in): string
    {
        $move = Fields::object($in, 'next_step');
        $kind = Fields::string($move, 'kind');
        $text = self::head($move, $kind, $in);
        if (\in_array($kind, array_merge(self::ROW_KINDS, ['tag']), true)) {
            $text .= self::leaves($move).self::unverified($move);
        }
        $holders = array_map([self::class, 'holder'], Fields::objects($move, 'held_by'));
        if ($holders !== []) {
            $text .= '; held by '.implode(' and ', $holders);
        }
        $because = Fields::strings($move, 'composer_moves_because');
        foreach ($because as $reason) {
            $text .= '; '.self::because($reason, $kind, $in);
        }
        $through = self::through(\array_key_exists('through', $move) && $move['through'] !== null ? Fields::objects($move, 'through') : null);
        if ($through !== '' && \in_array('transitive', $because, true)) {
            $text .= '; '.$through;
        }

        return $text.self::checked(Fields::nullableObject($move, 'check'));
    }

    /** @param array<mixed, mixed>|null $check */
    private static function checked(?array $check): string
    {
        if ($check === null) {
            return '';
        }
        switch (Fields::string($check, 'result')) {
            case 'resolves':
                return "; Composer's solver checked it against the lock";
            case 'refused':
                return "; Composer's solver refuses it against the lock";
            default:
                return "; not checked with Composer's solver (".self::word(self::NOT_CHECKED, Fields::string($check, 'reason'))."), so it holds only if Composer resolves it";
        }
    }

    /** @param array<mixed, mixed> $move */
    private static function update(array $move): string
    {
        foreach (Fields::objects($move, 'commands') as $argv) {
            if (($argv[1] ?? null) === 'update') {
                $rendered = ShellCommand::posix(Fields::strings(['argv' => $argv], 'argv'));
                if ($rendered !== null) {
                    return $rendered;
                }
            }
        }

        return 'update it with Composer';
    }

    /**
     * @param array<mixed, mixed> $root  the report's `gate` and `run`
     * @param array<mixed, mixed> $words en/gate-words.json
     */
    public static function runGate(array $root, array $words): string
    {
        $gate = Fields::object($root, 'gate');
        $run = Fields::object($root, 'run');
        $gates = Fields::objects($run, 'gates');
        if (Fields::bool($gate, 'fails')) {
            $head = 'fails';
        } elseif (Fields::string($run, 'mode') === 'generate_baseline') {
            $head = 'not applied (--generate-baseline)';
        } else {
            $head = $gates === [] ? 'off' : 'passes';
        }
        $parts = ['gate: '.$head];
        $source = Fields::string($run, 'fail_on_source');
        $parts[] = '--fail-on='.Fields::string($run, 'fail_on').($source === 'option' ? '' : ' ('.self::word(Fields::table($words, 'fail_on_source'), $source).')');
        if ($gates !== [] && Fields::bool($gate, 'fail_on_applied')) {
            $failing = Fields::int($gate, 'failing');
            $count = $failing === 0 ? 'no finding fails it' : self::plural($failing, 'finding fails it', 'findings fail it');
            if ($failing > 0 && \count($gates) > 1) {
                $by = [];
                foreach (Fields::objects($gate, 'failing_by') as $value) {
                    if (Fields::int($value, 'failing') > 0) {
                        $by[] = Fields::string($value, 'value').' '.Fields::int($value, 'failing');
                    }
                }
                $count .= ' ('.implode(', ', $by).')';
            }
            $exempt = Fields::int(Fields::object($gate, 'exempt'), 'baseline');
            if ($exempt > 0) {
                $count .= '; '.($exempt === 1 ? '1 reaches it and is known to the baseline' : $exempt.' reach it and are known to the baseline');
            }
            $parts[] = $count;
        } elseif ($gates !== []) {
            $parts[] = self::plural(Fields::int($gate, 'reaching'), 'finding reaches it', 'findings reach it');
        }
        if (\in_array('strict_network', Fields::strings($gate, 'tripped_by'), true)) {
            $parts[] = '--strict-network: a lookup failed';
        }
        foreach (Fields::objects($gate, 'not_applied') as $gap) {
            $parts[] = \sprintf('%s not applied to %s: %s', Fields::string($gap, 'check'), self::plural(Fields::int($gap, 'packages'), 'package', 'packages'), self::word(Fields::table($words, 'not_applied'), Fields::string($gap, 'reason')));
        }
        $parts[] = \sprintf('target php %s (%s)', Fields::string($run, 'target_php'), self::word(Fields::table($words, 'target_php_source'), Fields::string($run, 'target_php_source')));

        return implode(' · ', $parts);
    }

    /** @param array<mixed, mixed> $in */
    public static function evidence(array $in): string
    {
        $counted = [];
        $score = Fields::object($in, 'score');
        // A score of 0 has no `terms` key: nothing counts.
        foreach (\array_key_exists('terms', $score) ? Fields::objects($score, 'terms') : [] as $term) {
            $counted[] = Fields::string($term, 'flag');
        }
        $lines = [];
        foreach (Fields::objects($in, 'flags') as $flag) {
            if (\in_array(Fields::string($flag, 'id'), $counted, true)) {
                $lines[] = Fields::string($flag, 'id').': '.Fields::string($flag, 'summary');
            }
        }

        return implode('; ', $lines);
    }

    /** @param array<mixed, mixed> $in */
    public static function gateLine(array $in): ?string
    {
        $gate = Fields::nullableObject($in, 'gate');
        $basis = $gate === null ? null : Fields::nullableObject($gate, 'basis');
        if ($basis === null) {
            return null;
        }
        $new = Fields::object($basis, 'new');
        $line = Fields::objects($new, 'terms') === [] ? '0' : ScoreLineFromJson::render(ContractBundle::stringKeys($new + ['accepted' => []]));
        $limitedBy = Fields::string($basis, 'limited_by');

        return \sprintf('gate %d = min(%d, 2 × %d) (%s); new: %s', Fields::int($basis, 'score'), Fields::int(Fields::object($in, 'score'), 'total'), Fields::int($new, 'total'), self::LIMITED_BY[$limitedBy] ?? $limitedBy, $line);
    }

    /**
     * @param array<mixed, mixed> $in
     * @param array<mixed, mixed> $reasons en/reason-words.json
     * @param array<mixed, mixed> $years   en/years-phrases.json
     */
    public static function ageLine(array $in, array $reasons, array $years): ?string
    {
        $age = Fields::object($in, 'age');
        $version = Fields::string($in, 'version');
        $branch = self::branchOf($in, $version);
        $installed = Fields::nullableObject($age, 'installed');
        $branchRelease = Fields::nullableObject($age, 'branch_release');
        $release = Fields::nullableObject($age, 'release');
        $push = Fields::nullableObject($age, 'push');
        $parts = [];
        if ($installed !== null) {
            $text = 'installed '.$version.' released '.self::when($installed, $years);
            if ($release !== null && Fields::string($release, 'version') === $version) {
                $text .= ', its newest release';
            } elseif ($branchRelease !== null && Fields::string($branchRelease, 'version') === $version) {
                $text .= ', the newest on '.$branch;
            }
            $parts[] = $text;
        } elseif (!\in_array($age['installed_unmeasured'] ?? null, ['branch_snapshot', 'details_missing'], true)) {
            $parts[] = 'installed '.$version.', release date not known ('.self::word(Fields::table($reasons, 'words'), Fields::string($age, 'installed_unmeasured')).')';
        }
        if ($branchRelease !== null && Fields::string($branchRelease, 'version') !== $version && !($release !== null && Fields::string($release, 'version') === Fields::string($branchRelease, 'version'))) {
            $parts[] = $branch.' last released '.Fields::string($branchRelease, 'version').' '.self::when($branchRelease, $years);
        }
        if ($release !== null && Fields::string($release, 'version') !== $version) {
            $parts[] = $branchRelease !== null && Fields::string($branchRelease, 'version') === Fields::string($release, 'version')
                ? $branch.' last released '.Fields::string($release, 'version').' '.self::when($release, $years).', its newest release'
                : 'newest release '.Fields::string($release, 'version').' '.self::when($release, $years);
        }
        if ($push !== null) {
            $parts[] = 'last '.self::event($in).' '.self::when($push, $years);
        }

        return $parts === [] ? null : 'age: '.implode('; ', $parts);
    }

    /**
     * The `age` block of the explain text, from the bundle's age words.
     *
     * @param array<mixed, mixed> $in
     * @param array<mixed, mixed> $words   en/age-words.json
     * @param array<mixed, mixed> $reasons en/reason-words.json
     * @param array<mixed, mixed> $years   en/years-phrases.json
     *
     * @return list<string>
     */
    public static function explainAge(array $in, array $words, array $reasons, array $years): array
    {
        $age = Fields::object($in, 'age');
        $version = Fields::string($in, 'version');
        $thresholds = Fields::object(Fields::object($in, 'run'), 'thresholds');
        $names = Fields::object($words, 'reading');
        $rows = [
            ['installed', Fields::string($names, 'installed'), $version, null],
            ['branch_release', str_replace('{branch}', self::branchOf($in, $version), Fields::string($names, 'branch_release')), null, 'release'],
            ['release', Fields::string($names, 'release'), null, 'release'],
            ['push', str_replace('{event}', self::event($in), Fields::string($names, 'push')), null, 'push'],
        ];
        $lines = [];
        foreach ($rows as [$key, $name, $shown, $kind]) {
            $reading = Fields::nullableObject($age, $key);
            $released = $shown ?? ($reading === null ? null : ($reading['version'] ?? null));
            $head = str_pad($name, 11).str_pad(\is_string($released) ? $released : '', 10);
            if ($reading === null) {
                $lines[] = $head.strtr(Fields::string($words, 'not_read'), ['{reading} ' => '', '{reason words}' => self::word(Fields::table($reasons, 'words'), Fields::string($age, $key.'_unmeasured'))]);
                continue;
            }
            $notes = [];
            if ($kind !== null) {
                $level = \is_string($reading['level'] ?? null) ? $reading['level'] : null;
                $warn = Fields::int($thresholds, $kind.'-warn-years');
                $notes[] = $level === null
                    ? strtr(Fields::string($words, 'below'), ['{kind}' => $kind, '{warn}' => (string) $warn])
                    : strtr(Fields::string($words, 'past'), ['{kind}' => $kind, '{level}' => $level, '{years}' => (string) ($level === 'high' ? Fields::int($thresholds, $kind.'-high-years') : $warn)]);
            }
            if (\is_string($reading['dated_by'] ?? null)) {
                $notes[] = str_replace('{package}', $reading['dated_by'], Fields::string($words, 'dated_by'));
            }
            $lines[] = rtrim($head.substr(Fields::string($reading, 'at'), 0, 10).'   '.str_pad(self::ago(Fields::number($reading, 'years'), $years), 30).implode('; ', $notes));
        }
        $libyears = $in['libyears'] ?? null;
        $lines[] = \is_int($libyears) || \is_float($libyears)
            ? 'libyears '.(Fields::bool($in, 'libyears_at_least') ? 'at least ' : '').number_format((float) $libyears, 1, '.', '').' behind the newest stable release'
            : 'libyears not measured: '.self::word(Fields::table($reasons, 'words'), Fields::string($in, 'libyears_unmeasured'));

        return $lines;
    }

    /** @param array<mixed, mixed> $in */
    public static function baselineSuffix(array $in): ?string
    {
        $baseline = Fields::nullableObject($in, 'baseline');
        if ($baseline === null) {
            return null;
        }
        $recorded = Fields::nullableObject($baseline, 'recorded');
        if ($recorded === null) {
            return '(baseline: new package)';
        }
        $now = Fields::object($baseline, 'advisories_now');
        $worsened = Fields::objects($baseline, 'worsened_by');
        $items = [];
        if (Fields::int($now, 'known') > 0) {
            $items[] = Fields::int($now, 'known').' '.(Fields::int($now, 'known') === 1 ? 'advisory' : 'advisories').' known';
        }
        foreach ($worsened as $fact) {
            if (Fields::string($fact, 'kind') === 'flag') {
                $items[] = Fields::string($fact, 'id').' new';
            }
        }
        if (Fields::int($now, 'new') > 0) {
            $items[] = Fields::int($now, 'new').' new '.(Fields::int($now, 'new') === 1 ? 'advisory' : 'advisories');
        }
        $run = Fields::object($in, 'run');
        $compared = ($recorded['target_php'] ?? null) === Fields::string($run, 'target_php') && ($recorded['fix_model'] ?? null) === Fields::int($run, 'fix_model');
        foreach ($worsened as $fact) {
            $why = Fields::string($fact, 'why');
            if ($why === 'new' || Fields::string($fact, 'kind') !== 'advisory') {
                continue;
            }
            $was = Fields::object($fact, 'recorded');
            $is = Fields::object($fact, 'now');
            $lost = ' fix lost: '.Fields::string($was, 'fix_kind').' → '.Fields::string($is, 'fix_kind');
            $name = self::advisoryName($in, Fields::string($fact, 'id'));
            if ($why === 'fix_lost') {
                $items[] = $name.$lost;
                continue;
            }
            $crossed = $compared && !\in_array(Fields::string($was, 'fix_kind'), self::LOST, true) && \in_array(Fields::string($is, 'fix_kind'), self::LOST, true);
            $items[] = $name.' re-rated '.Fields::string($was, 'severity').' → '.Fields::string($is, 'severity').($crossed ? ','.$lost : '');
        }
        foreach (Fields::objects($in, 'flags') as $flag) {
            $state = Fields::nullableObject($flag, 'baseline');
            if ($state !== null && ($state['state'] ?? null) === 'covered') {
                $items[] = Fields::string($flag, 'id').' covered by the recorded '.Fields::string($state, 'covered_by');
            }
        }

        return '(baseline: '.($items === [] ? 'known' : implode('; ', $items)).')';
    }

    /** @param array<mixed, mixed> $years en/years-phrases.json */
    public static function ago(float $value, array $years): string
    {
        foreach (Fields::objects($years, 'pairs') as $pair) {
            if (Fields::number($pair, 'value') === $value) {
                return Fields::string($pair, 'phrase');
            }
        }

        throw new MissingField('years-phrases.json pair '.$value);
    }

    /**
     * @param array<mixed, mixed> $move
     * @param array<mixed, mixed> $in
     */
    private static function head(array $move, string $kind, array $in): string
    {
        $package = Fields::string($in, 'package');
        $run = Fields::object($in, 'run');
        $target = Fields::string($run, 'target_php');
        $check = Fields::nullableObject($move, 'php_check');
        switch ($kind) {
            case 'replace':
                $replacement = Fields::object($move, 'replacement');
                $byRegistry = Fields::string($replacement, 'named_by') === 'repository';
                if (\is_string($replacement['package'] ?? null)) {
                    $finding = Fields::nullableObject($replacement, 'finding');

                    return 'replace with '.$replacement['package'].' ('.($byRegistry ? 'named by its registry' : 'named in composer.lock').($finding === null ? '' : '; itself '.Fields::string($finding, 'verdict').': '.implode(' · ', Fields::strings($finding, 'flag_ids'))).')';
                }

                return 'replace it: '.($byRegistry ? 'its registry' : 'composer.lock').' suggests "'.Fields::string($replacement, 'suggestion').'"';
            case 'find-alternative':
                return Fields::string($move, 'source') === 'abandoned' ? 'find a replacement (none named)' : \sprintf('find a maintained alternative: nothing released for %.1F years', Fields::number($move, 'quiet_years'));
            case 'tag':
                return self::tag($move, $in, $check);
            case 'test':
                $latest = Fields::nullableObject($move, 'latest');

                return 'test it on PHP '.$target.($latest !== null && Fields::bool($latest, 'is_installed') ? ' — '.Fields::string($latest, 'version').' is its newest release' : '');
            case 'update':
                return self::update($move).' (gets '.Fields::string($move, 'newest').')'.self::clears($move);
            case 'require':
            case 'raise-php':
                $raise = $check !== null && ($check['project_allows'] ?? null) === false && \is_string($check['raise_to'] ?? null) ? 'raise require.php to '.$check['raise_to'].', then ' : '';
                $constraint = Fields::string($move, 'constraint');
                $text = Fields::bool($in, 'direct')
                    ? $raise.'require '.$package.' '.$constraint
                    : $raise.'move '.$package.' to '.$constraint.(self::dependents($in) === '' ? '' : ' through '.self::dependents($in));
                $text .= self::clears($move);
                if ($check !== null && \is_string($check['requires'] ?? null)) {
                    $text .= self::php($move, $check, $run).'; the target php '.$target.' runs it';
                }

                return $text;
            case 'blocked':
                $latest = Fields::nullableObject($move, 'latest');
                $pinned = Fields::string($move, 'source') === 'pinned';
                $where = $pinned && $latest !== null ? 'the newest tag, '.Fields::string($latest, 'version').',' : (Fields::nullableString($move, 'to_branch') ?? 'the fix');
                $solve = Fields::nullableObject($move, 'check');
                if ($solve !== null && Fields::string($solve, 'result') === 'refused' && ($check === null || ($check['target_runs'] ?? null) !== false)) {
                    return 'no move installs '.$where.': Composer names no hold';
                }
                $text = $check !== null && \is_string($check['requires'] ?? null)
                    ? 'no move runs on the target: '.$where.' needs php '.Fields::string($check, 'requires').' and the target php '.$target.' does not run it'
                    : 'no move runs on the target: the target php '.$target.' does not run '.$where;

                return $text.($pinned ? '; replace it' : '');
            case 'no-fix':
                return 'no release fixes it';
            case 'no-single-fix':
                return 'no single release fixes every advisory; see each advisory';
            case 'no-tag':
                return 'no tagged release to move to; review the snapshot, or replace it';
            case 'no-move':
                $reason = Fields::string($move, 'reason');

                return (self::NO_MOVE[$reason] ?? $kind).($reason === 'metadata_not_read' && Fields::string($move, 'source') === 'pinned' ? '; review the snapshot' : '');
            default:
                return $kind;
        }
    }

    /**
     * @param array<mixed, mixed>      $move
     * @param array<mixed, mixed>      $in
     * @param array<mixed, mixed>|null $check
     */
    private static function tag(array $move, array $in, ?array $check): string
    {
        $latest = Fields::object($move, 'latest');
        // A tag move's crosses_major is false only on a branch-alias snapshot that stays on its line.
        $word = ($move['to_branch'] ?? null) !== null && ($move['crosses_major'] ?? null) === false ? 'the newest on its own line' : 'the newest';
        if (Fields::bool($in, 'direct')) {
            $text = 'require a tag — '.$word.' is '.Fields::string($latest, 'version');
        } else {
            $through = self::dependents($in);
            $text = 'move it to a tag — '.$word.' is '.Fields::string($latest, 'version').($through === '' ? '' : ', through '.$through);
        }
        if (\is_string($latest['released'] ?? null)) {
            $text .= ' ('.substr($latest['released'], 0, 10).')';
        }
        if (($latest['relation'] ?? null) === 'older') {
            $text .= ', older than your snapshot ('.substr(Fields::string($latest, 'snapshot_time'), 0, 10).'); review, or replace';
        }
        if ($check !== null && \is_string($check['requires'] ?? null)) {
            $text .= self::php($move, $check, Fields::object($in, 'run'));
        }
        $byBasis = [];
        foreach (Fields::objects($move, 'adds') as $fact) {
            $byBasis[Fields::string($fact, 'basis')][] = Fields::string($fact, 'id');
        }
        foreach ($byBasis as $basis => $ids) {
            $text .= '; '.(self::ADDS[$basis] ?? str_replace('_', ' ', (string) $basis)).', so '.implode(' and ', $ids).' would count';
        }

        return $text;
    }

    /**
     * @param array<mixed, mixed> $move
     * @param array<mixed, mixed> $check
     * @param array<mixed, mixed> $run
     */
    private static function php(array $move, array $check, array $run): string
    {
        $text = '; '.Fields::string($move, 'to_branch').' needs php '.Fields::string($check, 'requires');
        if (($check['project_allows'] ?? null) === true) {
            return $text.', which require.php allows';
        }
        if (($check['project_allows'] ?? null) === false && \is_string($check['raise_size'] ?? null)) {
            $lowest = Fields::nullableString($run, 'project_php_lowest') ?? '';

            return $text.', require.php allows '.(substr($lowest, -2) === '.0' && substr_count($lowest, '.') === 2 ? substr($lowest, 0, -2) : $lowest).' (a '.$check['raise_size'].' raise)';
        }

        return $text;
    }

    /** @param array<mixed, mixed> $move */
    private static function clears(array $move): string
    {
        $parts = [];
        $advisories = 0;
        foreach (Fields::objects($move, 'clears') as $fact) {
            if (Fields::string($fact, 'kind') === 'advisory') {
                ++$advisories;
            } elseif (Fields::string($fact, 'id') !== 'pinned') {
                $parts[] = Fields::string($fact, 'id');
            }
        }
        if ($advisories > 0) {
            $parts[] = $advisories === 1 ? 'the advisory' : $advisories.' advisories';
        }

        return $parts === [] ? '' : ' — clears '.self::joined($parts);
    }

    /** @param array<mixed, mixed> $move */
    private static function leaves(array $move): string
    {
        $flags = [];
        $advisories = 0;
        foreach (Fields::objects($move, 'leaves') as $fact) {
            if (Fields::string($fact, 'kind') === 'flag') {
                $flags[] = Fields::string($fact, 'id');
            } else {
                ++$advisories;
            }
        }
        $items = $advisories > 0 ? array_merge($flags, [$advisories.' '.($advisories === 1 ? 'advisory' : 'advisories')]) : $flags;
        if ($items === []) {
            return '';
        }

        return '; '.implode(', ', $items).(\count($items) === 1 && substr($items[0], -10) !== 'advisories' ? ' stays' : ' stay');
    }

    /** @param array<mixed, mixed> $move */
    private static function unverified(array $move): string
    {
        $ids = array_map(static fn ($fact): string => Fields::string($fact, 'id'), Fields::objects($move, 'unverified'));

        return $ids === [] ? '' : '; not checked: '.implode(', ', $ids);
    }

    /** @param array<mixed, mixed> $holder */
    private static function holder(array $holder): string
    {
        $constraint = \is_string($holder['constraint'] ?? null) ? $holder['constraint'] : null;
        $target = \is_string($holder['target'] ?? null) ? $holder['target'].' ' : '';
        $kind = Fields::string($holder, 'link');
        $link = (['conflict' => 'conflicts with ', 'replace' => 'replaces '][$kind] ?? 'requires ').$target.$constraint;
        if ($target === '' && \in_array($kind, ['root-require', 'replace'], true)) {
            $link = $kind === 'replace' ? 'replaces it' : 'no release of '.$constraint.' can install';
        }
        if (Fields::string($holder, 'source') === 'root') {
            return 'composer.json'.($constraint === null ? '' : ' ('.$link.')');
        }
        $bits = $constraint === null ? [] : [$link];
        $finding = Fields::nullableObject($holder, 'holder');
        if ($finding !== null && \in_array(Fields::string($finding, 'verdict'), self::GRADES, true)) {
            $bits[] = \is_string($finding['lead'] ?? null) ? 'itself '.$finding['lead'] : 'itself '.Fields::string($finding, 'verdict').': '.implode(' · ', Fields::strings($finding, 'flag_ids'));
        }

        return Fields::string($holder, 'package').' '.Fields::string($holder, 'version').($bits === [] ? '' : ' ('.implode('; ', $bits).')');
    }

    /** @param array<mixed, mixed> $in */
    private static function because(string $reason, string $kind, array $in): string
    {
        $resolved = \in_array($kind, self::RESOLVED, true);
        switch ($reason) {
            case 'holders':
                return 'the packages that hold it may need to move too';
            case 'crosses_major':
                $toBranch = Fields::object($in, 'next_step')['to_branch'] ?? '';

                $check = Fields::nullableObject(Fields::object($in, 'next_step'), 'check');
                $solved = $check !== null && Fields::string($check, 'result') !== 'not_checked';

                return (\is_string($toBranch) && strpos($toBranch, '0.') === 0 ? 'a new 0.x line (Composer treats 0.x minors as breaking)' : 'a new major').' brings its own requirements'.($solved ? '' : ', which lockrot does not solve');
            case 'transitive':
                return $resolved ? 'what requires it must allow it' : 'it comes in through '.self::dependents($in).': replace or update '.(\count(Fields::strings($in, 'direct_dependents')) === 1 ? 'that package' : 'them');
            case 'unreached':
                return $resolved ? 'lockrot found no path from your requirements to it, so what requires it must allow it' : 'lockrot found no path from your requirements to it: `composer why` names what requires it';
            default:
                return $reason;
        }
    }

    /** @param list<array<mixed, mixed>>|null $through */
    private static function through(?array $through): string
    {
        $gone = [];
        $kept = [];
        foreach ($through ?? [] as $entry) {
            if (($entry['newest_requires'] ?? null) === false) {
                $gone[] = $entry;
            } elseif (($entry['newest_requires'] ?? null) === true) {
                $kept[] = $entry;
            }
        }
        [$entries, $verb] = $gone !== [] ? [$gone, ['no longer requires', 'no longer require']] : [$kept, ['still requires', 'still require']];
        if ($entries === []) {
            return '';
        }
        $names = array_map(static fn ($entry): string => Fields::string($entry, 'package').' ('.Fields::string(Fields::object($entry, 'newest_stable'), 'version').')', $entries);
        $shown = \count($names) > self::DEPENDENTS_SHOWN ? implode(', ', \array_slice($names, 0, self::DEPENDENTS_SHOWN)).' and '.(\count($names) - self::DEPENDENTS_SHOWN).' more' : self::joined($names);

        return \count($entries) === 1 ? 'the newest release of '.$shown.' '.$verb[0].' it' : 'the newest releases of '.$shown.' '.$verb[1].' it';
    }

    /** @param array<mixed, mixed> $in */
    private static function dependents(array $in): string
    {
        $names = Fields::strings($in, 'direct_dependents');

        return \count($names) > self::DEPENDENTS_SHOWN ? implode(', ', \array_slice($names, 0, self::DEPENDENTS_SHOWN)).' and '.(\count($names) - self::DEPENDENTS_SHOWN).' more' : implode(', ', $names);
    }

    /** @param list<string> $items */
    private static function joined(array $items): string
    {
        return \count($items) > 1 ? implode(', ', \array_slice($items, 0, -1)).' and '.$items[\count($items) - 1] : $items[0];
    }

    /** @param array<mixed, mixed> $in */
    private static function branchOf(array $in, string $version): string
    {
        if (\is_string($in['branch'] ?? null)) {
            return $in['branch'];
        }
        if (preg_match('{^v?(\d+)(?:\.(\d+))?\.x-dev$}', trim($version), $match) === 1) {
            return $match[1] === '0' ? '0.'.($match[2] ?? '0').'.x' : $match[1].'.x';
        }

        return 'its branch';
    }

    /** @param array<mixed, mixed> $in */
    private static function event(array $in): string
    {
        $activity = Fields::nullableObject($in, 'activity');

        return $activity !== null && \is_string($activity['event'] ?? null) ? $activity['event'] : 'push';
    }

    /**
     * @param array<mixed, mixed> $reading
     * @param array<mixed, mixed> $years
     */
    private static function when(array $reading, array $years): string
    {
        $datedBy = \is_string($reading['dated_by'] ?? null) ? ', dated by '.$reading['dated_by'] : '';

        return substr(Fields::string($reading, 'at'), 0, 10).' ('.self::ago(Fields::number($reading, 'years'), $years).$datedBy.')';
    }

    /** @param array<mixed, mixed> $words */
    private static function word(array $words, string $id): string
    {
        return \is_string($words[$id] ?? null) ? $words[$id] : str_replace('_', ' ', $id);
    }

    private static function plural(int $count, string $one, string $many): string
    {
        return $count.' '.($count === 1 ? $one : $many);
    }

    /** @param array<mixed, mixed> $in */
    private static function advisoryName(array $in, string $id): string
    {
        foreach (Fields::objects($in, 'signals') as $signal) {
            if (($signal['id'] ?? null) !== 'S9') {
                continue;
            }
            foreach (Fields::objects(Fields::object($signal, 'data'), 'advisories') as $row) {
                if (($row['id'] ?? null) === $id) {
                    return \is_string($row['cve'] ?? null) ? $row['cve'] : $id;
                }
            }
        }

        return $id;
    }
}
