<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Config\Gate;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Verdict\FailOn;
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

    /** The version of the fix classification: baselines compare fixes only under equal fix models. */
    public const FIX_MODEL = 1;
    /** The version of the sentence grammars. ScoreText has its own, in the score model. */
    public const TEXT_GRAMMAR = 1;

    /** The kind of each report-1 fail-on kind in report-2's `run.gates[]`. */
    private const GATE_KINDS = [FailOn::KIND_PRIORITY => 'grade', FailOn::KIND_VERDICT => 'flag', FailOn::KIND_UNCHECKED => 'unchecked'];

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
     * @param string $mode one of {@see Gate::MODES}
     *
     * @throws \InvalidArgumentException for a mode not in {@see Gate::MODES}
     */
    public function __construct(?string $project, ?string $rootPackage, ?string $targetPhp, ?string $lockPath, ?FailOn $failOn, ?Thresholds $thresholds, ?string $projectPhp = null, bool $strictNetwork = false, string $mode = Gate::MODE_CHECK, string $targetPhpSource = self::SOURCE_RUNTIME, string $failOnSource = self::SOURCE_DEFAULT, bool $includeDev = false)
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
     *
     * @return array<string, mixed>
     */
    public function toArray(?array $scoreRulesUsed = null, ?bool $includeDev = null): array
    {
        $thresholds = $this->thresholds ?? new Thresholds();
        $failOn = $this->failOn === null ? FailOn::fromString('none') : $this->failOn;
        $model = ScoreModel::toArray();

        return [
            'project' => $this->project,
            'root_package' => $this->rootPackage,
            'target_php' => PhpReleaseDates::minorOf($this->targetPhp ?? \PHP_VERSION),
            'target_php_source' => $this->targetPhp === null ? self::SOURCE_RUNTIME : $this->targetPhpSource,
            'project_php' => $this->projectPhp,
            'project_php_lowest' => (new PhpFloor(null, $this->projectPhp))->lowestAsString(),
            'lock_file' => $this->lockFile,
            'fail_on' => $failOn->value(),
            'fail_on_source' => $this->failOn === null ? self::SOURCE_DEFAULT : $this->failOnSource,
            'gates' => self::gates($failOn),
            'strict_network' => $this->strictNetwork,
            'mode' => $this->mode,
            'include_dev' => $includeDev ?? $this->includeDev,
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
            'score_model' => $model,
            'score_rules_used' => $scoreRulesUsed ?? ScoreModel::rulesUnused(),
        ];
    }

    /**
     * One entry for report-1's fail-on value, none for `none`: a grade word is a grade gate and a
     * report-1 verdict word a flag gate, each its own threshold.
     *
     * @return list<array{value: string, kind: string, threshold: ?string}>
     */
    private static function gates(FailOn $failOn): array
    {
        $kind = self::GATE_KINDS[$failOn->kind()] ?? null;
        if ($kind === null) {
            return [];
        }

        return [['value' => $failOn->value(), 'kind' => $kind, 'threshold' => $kind === 'unchecked' ? null : $failOn->value()]];
    }
}
