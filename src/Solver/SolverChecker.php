<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Package\BasePackage;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Lockrot\Deadline;
use Lockrot\Security\Holder;

/**
 * Checks each move with {@see ComposerSolver}: the inline form first, then `-w`, then `-W`. The
 * first form that resolves is the move's form. A refusal of all three names its holds: the rules of
 * the last refusal and the {@see HoldScan} of the release that the move names. A refusal that needs
 * a name that the {@see VersionSource} could not load is not a refusal: the move is not checked.
 *
 * @internal
 */
final class SolverChecker implements MoveChecker
{
    private const NOT_LOADED = [
        VersionSource::OFFLINE => MoveCheck::OFFLINE,
        VersionSource::BUDGET => MoveCheck::INSTALL_TIME_BUDGET,
        VersionSource::FAILED => MoveCheck::UNLOADED_NAME,
    ];

    private ComposerSolver $solver;
    private HoldScan $scan;
    /** @var list<string> */
    private array $notFromComposerRepository;
    private string $platformPhp;
    private ?string $configuredPhp;
    private bool $blocksAdvisories;
    private Deadline $deadline;
    private ?PoolAdvisories $advisories;
    private ?CheckOutcome $baseline = null;
    /** @var array<string, CheckOutcome> */
    private array $checked = [];
    /** @var array{class: string, message: string}|null */
    private ?array $error = null;

    /**
     * @param list<string> $notFromComposerRepository the locked names that no Composer repository serves
     * @param string       $platformPhp              the php version that the solve runs at, as config.platform writes it
     * @param ?string      $configuredPhp            `config.platform.php` of Composer's merged config
     */
    public function __construct(ComposerSolver $solver, array $notFromComposerRepository, string $platformPhp, bool $blocksAdvisories, Deadline $deadline, ?PoolAdvisories $advisories = null, ?string $configuredPhp = null)
    {
        $this->configuredPhp = $configuredPhp;
        $this->advisories = $advisories;
        $this->solver = $solver;
        $this->scan = new HoldScan($solver->root(), $solver->locked());
        $this->notFromComposerRepository = $notFromComposerRepository;
        $this->platformPhp = $platformPhp;
        $this->blocksAdvisories = $blocksAdvisories;
        $this->deadline = $deadline;
    }

    public function baseline(): CheckOutcome
    {
        if ($this->baseline === null) {
            $solution = $this->deadline->isPast() ? null : $this->solver->solve(SolveRequest::baseline());
            if ($solution === null || $this->failed($solution)) {
                $this->baseline = new CheckOutcome(MoveCheck::notChecked($solution === null ? MoveCheck::INSTALL_TIME_BUDGET : MoveCheck::SOLVER_ERROR));
            } else {
                $this->baseline = $solution->resolves() ? new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE)) : new CheckOutcome(MoveCheck::refused(), [], null, $solution->problem());
            }
        }

        return $this->baseline;
    }

    public function check(MoveQuery $query): CheckOutcome
    {
        $key = $query->key();
        if (!isset($this->checked[$key])) {
            $this->checked[$key] = $this->solve($query);
        }

        return $this->checked[$key];
    }

    public function combined(array $inline): CheckOutcome
    {
        $unchecked = $this->unchecked();
        if ($unchecked !== null) {
            return $unchecked;
        }
        if ($this->deadline->isPast()) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::INSTALL_TIME_BUDGET));
        }
        $solution = $this->solver->solve(SolveRequest::update($inline));
        if ($this->failed($solution)) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::SOLVER_ERROR));
        }
        $unloaded = $this->notLoaded($solution, ...array_keys($inline));
        if ($unloaded !== null) {
            return new CheckOutcome(MoveCheck::notChecked($unloaded));
        }

        return $solution->resolves()
            ? new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE))
            : new CheckOutcome(MoveCheck::refused(), Holder::distinct($solution->holds()), null, $solution->problem());
    }

    public function platformPhp(): string
    {
        return $this->platformPhp;
    }

    public function configuredPhp(): ?string
    {
        return $this->configuredPhp;
    }

    public function composerVersion(): string
    {
        return ComposerFeatures::version();
    }

    public function solverError(): ?array
    {
        return $this->error;
    }

    public function blocksAdvisories(): bool
    {
        return $this->blocksAdvisories;
    }

    public function withAdvisories(array $records, array $covered): MoveChecker
    {
        if (!$this->blocksAdvisories || $this->advisories === null) {
            return $this;
        }
        $clone = clone $this;
        $clone->solver = $this->solver->withAdvisories($this->advisories->withRecords($records, $covered));
        $clone->baseline = null;
        $clone->checked = [];

        return $clone;
    }

    public function solveAt(array $names): CheckOutcome
    {
        $baseline = $this->baseline()->check();
        if (!$baseline->isResolved() && !$baseline->isRefused()) {
            return new CheckOutcome($baseline);
        }
        if (array_intersect($names, $this->notFromComposerRepository) !== []) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::NOT_FROM_COMPOSER_REPOSITORY));
        }
        $tried = $this->forms(SolveRequest::unlock($names), ...$names);
        if ($tried instanceof CheckOutcome) {
            return $tried;
        }
        [$form, $solution] = $tried;

        return $form === null ? new CheckOutcome(MoveCheck::refused(), Holder::distinct($solution->holds())) : new CheckOutcome(MoveCheck::resolves($form));
    }

    private function solve(MoveQuery $query): CheckOutcome
    {
        $unchecked = $this->unchecked();
        if ($unchecked !== null) {
            return $unchecked;
        }
        if (\in_array($query->package(), $this->notFromComposerRepository, true)) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::NOT_FROM_COMPOSER_REPOSITORY));
        }
        $tried = $this->forms($query->request(), $query->package());
        if ($tried instanceof CheckOutcome) {
            return $tried;
        }
        [$form, $solution] = $tried;

        return $form === null ? $this->refusal($solution, $query) : $this->resolved($form, $solution, $query);
    }

    /**
     * The request as listed, then `-w`, then `-W`: the first form that resolves and its solution,
     * a null form and the last refusal, or the outcome of a solve that did not run.
     *
     * @return array{0: MoveCheck::INLINE|MoveCheck::WITH_DEPENDENCIES|MoveCheck::WITH_ALL_DEPENDENCIES|null, 1: Solution}|CheckOutcome
     */
    private function forms(SolveRequest $request, string ...$moved)
    {
        $last = null;
        // `-W` frees more packages than `-w`, and Composer 2.9 and later then block more of their
        // locked versions, so `-W` can refuse a move that `-w` resolves: each form is asked in turn.
        foreach ([SolveRequest::LISTED => MoveCheck::INLINE, SolveRequest::WITH_DEPENDENCIES => MoveCheck::WITH_DEPENDENCIES, SolveRequest::WITH_ALL_DEPENDENCIES => MoveCheck::WITH_ALL_DEPENDENCIES] as $transitive => $form) {
            $solution = $this->attempt($request->withTransitive($transitive), ...$moved);
            if (!$solution instanceof Solution) {
                return $solution;
            }
            if ($solution->resolves()) {
                return [$form, $solution];
            }
            $last = $solution;
        }

        return [null, $last];
    }

    /** A move is not checked while the baseline does not resolve: for the baseline's reason, or because it is refused. */
    private function unchecked(): ?CheckOutcome
    {
        $baseline = $this->baseline()->check();
        if ($baseline->isResolved()) {
            return null;
        }

        return new CheckOutcome($baseline->isRefused() ? MoveCheck::notChecked(MoveCheck::BASELINE_REFUSED) : $baseline);
    }

    /**
     * The solution, or the outcome of a solve that the budget stops or that needs a name the
     * source could not load.
     *
     * @return Solution|CheckOutcome
     */
    private function attempt(SolveRequest $request, string ...$moved)
    {
        if ($this->deadline->isPast()) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::INSTALL_TIME_BUDGET));
        }
        $solution = $this->solver->solve($request);
        if ($this->failed($solution)) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::SOLVER_ERROR));
        }
        $unloaded = $solution->resolves() ? null : $this->notLoaded($solution, ...$moved);

        return $unloaded === null ? $solution : new CheckOutcome(MoveCheck::notChecked($unloaded));
    }

    /** @param MoveCheck::INLINE|MoveCheck::WITH_DEPENDENCIES|MoveCheck::WITH_ALL_DEPENDENCIES $form */
    private function resolved(string $form, Solution $solution, MoveQuery $query): CheckOutcome
    {
        return new CheckOutcome(MoveCheck::resolves($form), [], $solution->installs()[$query->package()] ?? null);
    }

    private function refusal(Solution $last, MoveQuery $query): CheckOutcome
    {
        $target = $this->release($query->package(), $query->version());
        $scanned = $target === null ? [] : $this->scan->of($target, (new VersionParser())->normalize($this->platformPhp));

        // A move publishes its holds, never Composer's problem text, which is costly to render.
        return new CheckOutcome(MoveCheck::refused(), Holder::distinct(array_merge($last->holds(), $scanned)));
    }

    /** Whether Composer failed on the solve: the first failure of the run is kept for its run note. */
    private function failed(Solution $solution): bool
    {
        $error = $solution->error();
        $this->error ??= $error;

        return $error !== null;
    }

    /** The reason of a refusal that needs a name the source could not load, the moved names included, null for a real refusal. */
    private function notLoaded(Solution $solution, string ...$moved): ?string
    {
        $failures = $solution->unloaded();
        foreach ($moved as $name) {
            $failures[] = $this->solver->source()->failure($name);
        }
        foreach ([VersionSource::BUDGET, VersionSource::OFFLINE, VersionSource::FAILED] as $failure) {
            if (\in_array($failure, $failures, true)) {
                return self::NOT_LOADED[$failure];
            }
        }

        return null;
    }

    private function release(string $package, string $version): ?BasePackage
    {
        try {
            $normalized = (new VersionParser())->normalize($version);
        } catch (\UnexpectedValueException $e) {
            return null;
        }
        $exact = new Constraint('==', $normalized);
        foreach ($this->solver->source()->versions($package) as $candidate) {
            if ($exact->matches(new Constraint('==', $candidate->getVersion()))) {
                return $candidate;
            }
        }

        return null;
    }
}
