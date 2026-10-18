<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Config\Gate;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Verdict\FailOn;
use Lockrot\Verdict\FailOnValue;
use Lockrot\Verdict\ScoreModel;

/**
 * What a run was told to do, so its report can say so: docs/schema.md#what-the-run-was-told.
 * The report names the lock by file name only: an absolute path carries the account name and often
 * a client's directory name, and people publish reports.
 *
 * @internal
 */
final class RunSettings
{
    /** Where a setting came from: the first source that sets it wins, in this order. */
    public const SOURCE_OPTION = 'option';
    public const SOURCE_ENV = 'env';
    public const SOURCE_CONFIG = 'config';
    public const SOURCE_PLATFORM = 'platform';
    public const SOURCE_RUNTIME = 'runtime';
    public const SOURCE_DEFAULT = 'default';

    /** The version of the fix classification. A change of the fix kinds or of their rules raises it. */
    public const FIX_MODEL = 1;
    /** The version of the sentence grammars. ScoreText has its own, in the score model. */
    public const TEXT_GRAMMAR = 1;

    private ?string $project;
    private ?string $rootPackage;
    private ?string $targetPhp;
    private ?string $lockFile;
    private ?FailOn $failOn;
    private ?Thresholds $thresholds;
    private ?string $projectPhp;
    private bool $strictNetwork;
    private string $mode;
    private string $targetPhpSource;
    private string $failOnSource;
    private bool $includeDev;

    /**
     * @param self::SOURCE_* $targetPhpSource what set `$targetPhp`
     * @param self::SOURCE_* $failOnSource    what set `$failOn`
     * @param string         $mode            one of {@see Gate::MODES}
     *
     * @throws \InvalidArgumentException for a mode not in {@see Gate::MODES}
     */
    public function __construct(?string $project, ?string $rootPackage, ?string $targetPhp, string $targetPhpSource, ?string $lockPath, ?FailOn $failOn, string $failOnSource, ?Thresholds $thresholds, ?string $projectPhp = null, bool $strictNetwork = false, string $mode = Gate::MODE_CHECK, bool $includeDev = false)
    {
        if (!\in_array($mode, Gate::MODES, true)) {
            throw new \InvalidArgumentException(\sprintf('no run in mode "%s"; the modes are %s', $mode, implode(', ', Gate::MODES)));
        }
        $this->project = $project;
        $this->rootPackage = $rootPackage;
        $this->targetPhp = $targetPhp;
        $this->lockFile = $lockPath === null ? null : basename($lockPath);
        $this->failOn = $failOn;
        $this->thresholds = $thresholds;
        $this->projectPhp = $projectPhp;
        $this->strictNetwork = $strictNetwork;
        $this->mode = $mode;
        $this->targetPhpSource = $targetPhpSource;
        $this->failOnSource = $failOnSource;
        $this->includeDev = $includeDev;
    }

    /** Null only where nothing told the run. */
    public function failOn(): ?FailOn
    {
        return $this->failOn;
    }

    /** The PHP minor the run judges against, the running PHP's when nothing set it. */
    public function targetPhp(): string
    {
        return PhpReleaseDates::minorOf($this->targetPhp ?? \PHP_VERSION);
    }

    public function targetPhpSource(): string
    {
        return $this->targetPhp === null ? self::SOURCE_RUNTIME : $this->targetPhpSource;
    }

    /** `run.fail_on`: the canonical list, `none` when nothing set it. */
    public function failOnValue(): string
    {
        return $this->failOn === null ? FailOn::NONE : $this->failOn->value();
    }

    /** `run.fail_on_source`: `default` when nothing set fail-on. */
    public function failOnSourceOrDefault(): string
    {
        return $this->failOn === null ? self::SOURCE_DEFAULT : $this->failOnSource;
    }

    public function strictNetwork(): bool
    {
        return $this->strictNetwork;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * report-2's `run`. The report counts `score_rules_used` over its findings: without it the key
     * holds every rule id at 0, and `sort` at null.
     *
     * @param array<string, int|null>|null $scoreRulesUsed
     * @param ?bool                        $includeDev     whether the report analysed packages-dev, which wins over the setting
     * @param AdvisoryLookup               $advisoryLookup the report's
     * @param ?MoveCheckRun                $moveCheck      the report's, null when the run checked no move
     *
     * @return array<string, mixed>
     */
    public function toArray(?array $scoreRulesUsed, ?bool $includeDev, AdvisoryLookup $advisoryLookup, ?MoveCheckRun $moveCheck = null): array
    {
        return ['project' => $this->project, 'root_package' => $this->rootPackage]
            + $this->php()
            + ['lock_file' => $this->lockFile]
            + $this->failOnKeys()
            + ['strict_network' => $this->strictNetwork, 'mode' => $this->mode, 'include_dev' => $includeDev ?? $this->includeDev]
            + $this->toModelArray()
            + ['advisory_lookup' => $advisoryLookup, 'move_check' => $moveCheck]
            + ['score_rules_used' => $scoreRulesUsed ?? ScoreModel::rulesUnused()];
    }

    /**
     * explain-2's `run`: the settings that judge one package, in the order of {@see toArray()}.
     *
     * @return array<string, mixed>
     */
    public function toExplainArray(AdvisoryLookup $advisoryLookup, ?MoveCheckRun $moveCheck = null): array
    {
        return $this->php() + $this->failOnKeys() + ['include_dev' => $this->includeDev] + $this->toModelArray()
            + ['advisory_lookup' => $advisoryLookup, 'move_check' => $moveCheck];
    }

    /** @return array{target_php: string, target_php_source: string, project_php: ?string, project_php_lowest: ?string} */
    private function php(): array
    {
        return [
            'target_php' => $this->targetPhp(),
            'target_php_source' => $this->targetPhpSource(),
            'project_php' => $this->projectPhp,
            'project_php_lowest' => (new PhpFloor(null, $this->projectPhp))->lowestAsString(),
        ];
    }

    /** @return array{fail_on: string, fail_on_source: string, gates: list<FailOnValue>} */
    private function failOnKeys(): array
    {
        return [
            'fail_on' => $this->failOnValue(),
            'fail_on_source' => $this->failOnSourceOrDefault(),
            'gates' => $this->failOn === null ? [] : $this->failOn->values(),
        ];
    }

    /** @return array{thresholds: array<string, int>, flag_ids: list<string>, verdicts: list<string>, graded_verdicts: list<string>, signal_ids: list<string>, fix_model: int, text_grammar: int, score_model: array<string, mixed>} */
    private function toModelArray(): array
    {
        $thresholds = $this->thresholds ?? new Thresholds();

        return [
            'thresholds' => [
                'release-warn-years' => $thresholds->releaseWarnYears(),
                'release-high-years' => $thresholds->releaseHighYears(),
                'push-warn-years' => $thresholds->pushWarnYears(),
                'push-high-years' => $thresholds->pushHighYears(),
            ],
            'flag_ids' => ScoreModel::FLAG_ORDER,
            'verdicts' => array_merge(...ScoreModel::VERDICT_ORDER),
            'graded_verdicts' => ScoreModel::GRADES,
            'signal_ids' => Signal::IDS,
            'fix_model' => self::FIX_MODEL,
            'text_grammar' => self::TEXT_GRAMMAR,
            'score_model' => ScoreModel::toArray(),
        ];
    }

}
