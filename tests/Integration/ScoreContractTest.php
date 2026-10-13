<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\Contract\ContractBundle;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The order rules that draft-04 cannot state (SPEC-0.14.md §8.5.5), and the jq recipes and migration
 * rows of the report-1 migration (§8.7.4), on the recorded bundle. Each recipe runs as a reader
 * runs it, behind the `$schema` guard, and must print something.
 *
 * @coversNothing
 */
#[CoversNothing]
final class ScoreContractTest extends TestCase
{
    private const GUARD = '."$schema" == "https://lockrot.dev/schema/report-2.json"';

    /** Each check of the order of `checks_skipped[]`: an `accepted_by_config` entry keeps its check's place. */
    private const SKIP_ORDER = ['repository_activity', 'repository_archived', 'release_metadata', 'release_branch', 'advisories'];
    private const SIGNAL_ORDER = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10'];

    /**
     * The recipes for the new fields, as SPEC-0.14.md §8.7.4 prints them, each with the recorded
     * document it runs on: the composite, or a case that carries its field.
     */
    private const RECIPES = [
        'why each graded finding scores what it does' => ['-r', '.findings[] | select(.score.total > 0) | "\(.package) \(.score.total) " + ([.score.terms[] | "\(.flag) \(.contribution)"] | join(" + "))', 'composite'],
        'check the arithmetic against the model' => ['-e', '.run.score_model.rounding == "floor_once" and all(.findings[] | select(.score.total > 0); ([.score.terms[].contribution] | add) == .score.exact and (.score.exact | floor) == .score.total)', 'composite'],
        'findings whose band comes from the sum of both parts' => ['-r', '.findings[] | select(.score.decided_by == "combination") | .package', 'tie-dagger'],
        'what each move would leave' => ['-r', '.findings[] | .package as $p | .score.total as $t | (.next_step | ., .also[]?) | select(.if_applied) | "\($p) \(.kind): \($t) -> \(if .if_applied.at_most then "at most " else "" end)\(.if_applied.total) \(.if_applied.verdict)"', 'composite'],
        'the commands of each move' => ['-r', '.findings[] | .package as $p | (.next_step | ., .also[]?) | select(.commands | length > 0) | "\($p): " + (.commands | map(@sh) | join(" && "))', 'composite'],
        'which facts fail the gate' => ['-r', '.findings[] | select(.gate.fails) | "\(.package): " + ([.gate.by[].facts[] | .id] | unique | join(", "))', 'composite'],
        'the gate: line' => ['-r', '.gate.text', 'composite'],
        'what the repository activity lookup did for each package' => ['-r', '.findings[] | "\(.package) \(.activity.status) \(.activity.reason // "")"', 'composite'],
        "how old each package's newest release is" => ['-r', '.findings[] | select(.age.release != null) | "\(.package) \(.age.release.version) \(.age.release.years)"', 'composite'],
        'graded findings by depth below composer.json' => ['-c', '.rot_depth.by_depth', 'composite'],
        'what each advisory repository did in this run' => ['-r', '.run.advisory_lookup.repositories[] | "\(.composer_repository) \(.outcome)"', 'composite'],
        'libyears values that are lower bounds' => ['-r', '.findings[] | select(.libyears_at_least) | .package', 'require-dev-name-dagger'],
        "packages whose abandoned marking Composer's ignore list removed" => ['-r', '.findings[] | select(.abandoned_ignored != null) | "\(.package): " + ([.abandoned_ignored.rules[].pattern] | join(", "))', 'ignored-marking-stale-dagger'],
    ];

    /** The report-2 side of each migration row of §8.7.4, as a filter that selects findings or a program of its own. */
    private const MIGRATION = [
        'lead' => ['-r', '.findings[] | select(.lead == "abandoned") | .package', 'composite'],
        'lead at or above left-behind' => ['-r', '.findings[] | select(.lead as $l | ["abandoned","silent","pinned","left-behind"] | index($l) != null) | .package', 'composite'],
        'a counted flag' => ['-r', '.findings[] | select(any(.score.terms[]?; .flag == "old-promise")) | .package', 'composite'],
        'priority alias' => ['-r', '.findings[] | select(.priority == "critical" and .verdict == "critical") | .package', 'composite'],
        'verdict ok' => ['-r', '.findings[] | select(.verdict == "ok") | .package', 'ok-unchecked'],
        'leading counts' => ['-c', '{abandoned: .flags.abandoned.leading, "left-behind": .flags["left-behind"].leading}', 'composite'],
        'counts.ok' => ['-c', '.counts.ok', 'composite'],
        'has an advisory' => ['-r', '.findings[] | select(.security.status == "vulnerable") | .package', 'composite'],
        'gate kinds' => ['-c', '[(.run.gates // [])[].kind]', 'composite'],
        'recorded lead' => ['-r', '.findings[] | "\(.package) \(.baseline.recorded.lead)"', 'B-baselined-dagger'],
        'S7 lead' => ['-c', '[.findings[].signals[] | select(.id == "S7") | .data.packages[] | {package, verdict, lead}]', 'composite'],
        'gate fails' => ['-r', '.findings[] | select(.gate.fails) | .package', 'composite'],
        'evidence' => ['-c', '[.findings[] | {package, every_flag: [.flags[].summary]}] | .[:3]', 'composite'],
        'abandoned with replacement' => ['-c', '.abandoned', 'composite'],
        'libyears total' => ['-c', '[.libyears.total, ([.findings[].libyears | select(. != null)] | add * 100 | round / 100)]', 'composite'],
        'libyears unmeasured' => ['-c', '.libyears.unmeasured', 'composite'],
        'unrated severity' => ['-c', '[.findings[].signals[] | select(.id == "S9") | .data.advisories[] | {id, severity, severity_published}]', 'composite'],
        'S4 event' => ['-c', '[.findings[] | {package, event: .activity.event}] | .[:3]', 'composite'],
        'counted and accepted flags' => ['-c', '[.findings[] | {package, counted: [.score.terms[]?.flag], accepted_by_entry: .allowlist.flag_ids}] | .[:3]', 'composite'],
    ];

    /** @return iterable<string, array{string, string, string}> */
    public static function programs(): iterable
    {
        foreach (self::RECIPES + self::MIGRATION as $name => [$flag, $program, $document]) {
            yield $name => [$flag, $program, $document];
        }
    }

    /**
     * @dataProvider programs
     */
    #[DataProvider('programs')]
    public function testARecipeRunsOnTheRecordedDocumentAndPrintsSomething(string $flag, string $program, string $document): void
    {
        $path = self::report($document);
        self::assertSame(0, self::jq(['-e', self::GUARD], $path)[0], 'the guard admits a report-2 document');
        [$exit, $output] = self::jq([$flag, $program], $path);

        self::assertSame(0, $exit, $output);
        self::assertNotContains(trim($output), ['', '[]', 'null', 'false'], 'the recipe prints nothing on '.$document);
    }

    /** A report recipe fed a baseline-2 or explain-2 file prints nothing and exits 0, so the guard must refuse those files. */
    public function testTheGuardRefusesABaselineAndAnExplainDocument(): void
    {
        $baselines = glob(ContractBundle::DIRECTORY.'/baseline-2/*.json') ?: [];
        $explanations = glob(ContractBundle::DIRECTORY.'/explain-2/*.json') ?: [];
        self::assertNotSame([], $baselines);
        self::assertNotSame([], $explanations);

        self::assertSame(1, self::jq(['-e', self::GUARD], $baselines[0])[0]);
        self::assertSame(1, self::jq(['-e', self::GUARD], $explanations[0])[0]);
    }

    public function testTheOrderRulesHoldOnEveryRecordedReport(): void
    {
        $broken = [];
        foreach (self::reports() as $name => $report) {
            $ranks = JsonPath::column($report, ['findings'], 'rank');
            if ($ranks !== range(1, \count($ranks))) {
                $broken[] = $name.': findings[] is not in rank order';
            }
            $repositories = JsonPath::column($report, ['run', 'advisory_lookup', 'repositories'], 'composer_repository');
            foreach (JsonPath::arrayAt($report, ['findings']) as $finding) {
                self::assertIsArray($finding);
                $label = $name.' '.JsonPath::stringAt($finding, ['package']).': ';
                foreach (self::orderBreaks($finding, $repositories) as $break) {
                    $broken[] = $label.$break;
                }
            }
        }

        self::assertSame([], $broken);
    }

    /** SPEC-0.14.md §7.5: a run without a baseline writes no standing on any result, and no bag has the report-1 key. */
    public function testASarifLogWithoutABaselineCarriesNoStanding(): void
    {
        $checked = 0;
        foreach (glob(ContractBundle::DIRECTORY.'/sarif/*.json') ?: [] as $file) {
            $report = JsonPath::decodeFile(ContractBundle::DIRECTORY.'/report-2/'.basename($file));
            if (($report['baseline'] ?? null) !== null) {
                continue;
            }
            foreach (JsonPath::arrayAt(JsonPath::decodeFile($file), ['runs', 0, 'results']) as $result) {
                self::assertIsArray($result);
                $bag = JsonPath::arrayAt($result, ['properties']);
                self::assertArrayNotHasKey('baseline', $bag);
                self::assertNull($bag['baseline_status'] ?? null, basename($file));
                ++$checked;
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    /**
     * @param array<mixed, mixed> $finding
     * @param list<mixed>         $repositories
     *
     * @return list<string>
     */
    private static function orderBreaks(array $finding, array $repositories): array
    {
        $breaks = [];
        $flags = JsonPath::column($finding, ['flags'], 'id');
        if ($flags !== self::inOrder($flags, ScoreModel::FLAG_ORDER)) {
            $breaks[] = 'flags[] is not in flag order';
        }
        $terms = JsonPath::has($finding, ['score', 'terms']) ? JsonPath::arrayAt($finding, ['score', 'terms']) : [];
        $termFlags = array_map(static fn ($term): string => \is_array($term) && \is_string($term['flag'] ?? null) ? $term['flag'] : '', $terms);
        if ($termFlags !== array_values(array_unique($termFlags)) || ($terms !== [] && JsonPath::stringAt($terms, [0, 'role']) !== 'lead' && JsonPath::stringAt($terms, [0, 'part']) !== 'security')) {
            $breaks[] = 'terms[] does not open with the lead, or names a flag twice';
        }
        if (\in_array('vulnerable', $termFlags, true) && end($termFlags) !== 'vulnerable') {
            $breaks[] = 'the security term is not last';
        }
        $s9 = [];
        foreach (JsonPath::arrayAt($finding, ['signals']) as $signal) {
            if (\is_array($signal) && ($signal['id'] ?? null) === 'S9') {
                $s9 = JsonPath::column($signal, ['data', 'advisories'], 'id');
            }
        }
        foreach (self::factLists($finding) as $label => $list) {
            if ($list !== self::factOrder($list, $s9)) {
                $breaks[] = $label.' is not flags in flag order, then advisories in S9 row order';
            }
        }
        $feeds = JsonPath::has($finding, ['security', 'feeds']) ? JsonPath::column($finding, ['security', 'feeds'], 'composer_repository') : [];
        if ($feeds !== self::inOrder($feeds, $repositories)) {
            $breaks[] = 'security.feeds[] is not in run.advisory_lookup.repositories order';
        }
        $skipped = JsonPath::column($finding, ['checks_skipped'], 'check');
        if ($skipped !== self::inOrder($skipped, self::SKIP_ORDER)) {
            $breaks[] = 'checks_skipped[] is not in the fixed check order';
        }
        $missing = array_map(static fn ($entry) => \is_array($entry) && \is_array($entry['blocks'] ?? null) ? ($entry['blocks'][0] ?? '') : '', JsonPath::arrayAt($finding, ['checks_missing']));
        if ($missing !== self::inOrder($missing, self::SIGNAL_ORDER)) {
            $breaks[] = 'checks_missing[] is not in signal order';
        }

        return $breaks;
    }

    /**
     * Every fact list of the finding, by a label.
     *
     * @param array<mixed, mixed> $finding
     *
     * @return array<string, list<array<mixed, mixed>>>
     */
    private static function factLists(array $finding): array
    {
        $lists = [];
        $moves = \is_array($finding['next_step'] ?? null) ? ['next_step' => $finding['next_step']] : [];
        foreach (\is_array($finding['next_step']['also'] ?? null) ? $finding['next_step']['also'] : [] as $i => $also) {
            $moves['also['.$i.']'] = $also;
        }
        foreach ($moves as $label => $move) {
            foreach (['clears', 'unverified', 'leaves', 'adds'] as $key) {
                $lists[$label.'.'.$key] = \is_array($move) && \is_array($move[$key] ?? null) ? $move[$key] : [];
            }
        }
        foreach (['gets' => ['clears'], 'partial' => ['clears', 'unverified']] as $part => $keys) {
            foreach ($keys as $key) {
                $value = $finding['security'][$part][$key] ?? null;
                if (\is_array($value)) {
                    $lists['security.'.$part.'.'.$key] = $value;
                }
            }
        }

        return $lists;
    }

    /**
     * @param list<array<mixed, mixed>> $list
     * @param list<mixed>               $s9
     *
     * @return list<array<mixed, mixed>>
     */
    private static function factOrder(array $list, array $s9): array
    {
        $sorted = $list;
        usort($sorted, static function (array $a, array $b) use ($s9): int {
            $key = static fn (array $fact): array => ($fact['kind'] ?? null) === 'flag'
                ? [0, (int) array_search($fact['id'] ?? null, ScoreModel::FLAG_ORDER, true)]
                : [1, (int) array_search($fact['id'] ?? null, $s9, true)];

            return $key($a) <=> $key($b);
        });

        return $sorted;
    }

    /**
     * @param list<mixed> $values
     * @param list<mixed> $order
     *
     * @return list<mixed> $values sorted by $order, a value $order lacks last
     */
    private static function inOrder(array $values, array $order): array
    {
        $sorted = $values;
        usort($sorted, static fn ($a, $b): int => (array_search($a, $order, true) === false ? \PHP_INT_MAX : array_search($a, $order, true)) <=> (array_search($b, $order, true) === false ? \PHP_INT_MAX : array_search($b, $order, true)));

        return $sorted;
    }

    /** @return iterable<string, array<mixed, mixed>> */
    private static function reports(): iterable
    {
        $files = glob(ContractBundle::DIRECTORY.'/report-2/*.json') ?: [];
        sort($files);
        self::assertNotSame([], $files, 'record the bundle with tools/report/record');
        foreach ($files as $file) {
            yield basename($file, '.json') => JsonPath::decodeFile($file);
        }
    }

    private static function report(string $name): string
    {
        $path = ContractBundle::DIRECTORY.'/report-2/'.$name.'.json';
        self::assertFileExists($path, 'record the bundle with tools/report/record');

        return $path;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string} the exit code and the output
     */
    private static function jq(array $arguments, string $path): array
    {
        if ((new ExecutableFinder())->find('jq') === null) {
            self::markTestSkipped('jq runs the recipes, and the CI test jobs have it.');
        }
        $process = new Process(array_merge(['jq'], $arguments, [$path]));
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
    }
}
