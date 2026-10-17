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
    private bool $blocksAdvisories;
    private Deadline $deadline;
    private ?CheckOutcome $baseline = null;
    /** @var array<string, CheckOutcome> */
    private array $checked = [];

    /**
     * @param list<string> $notFromComposerRepository the locked names that no Composer repository serves
     * @param string       $platformPhp              the php version that the solve runs at, as config.platform writes it
     */
    public function __construct(ComposerSolver $solver, array $notFromComposerRepository, string $platformPhp, bool $blocksAdvisories, Deadline $deadline)
    {
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
            $this->baseline = $solution === null
                ? new CheckOutcome(MoveCheck::notChecked(MoveCheck::INSTALL_TIME_BUDGET))
                : ($solution->resolves() ? new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE)) : new CheckOutcome(MoveCheck::refused(), [], null, $solution->problem()));
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
        $baseline = $this->baseline();
        if (!$baseline->check()->isResolved()) {
            return new CheckOutcome(MoveCheck::notChecked($baseline->check()->isRefused() ? MoveCheck::BASELINE_REFUSED : (string) $baseline->check()->reason()));
        }
        if ($this->deadline->isPast()) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::INSTALL_TIME_BUDGET));
        }
        $solution = $this->solver->solve(SolveRequest::update($inline));
        $unloaded = $this->notLoaded($solution);
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

    public function composerVersion(): string
    {
        return ComposerFeatures::version();
    }

    public function blocksAdvisories(): bool
    {
        return $this->blocksAdvisories;
    }

    public function withBlockedAdvisories(array $ranges): MoveChecker
    {
        if (!$this->blocksAdvisories) {
            return $this;
        }
        $clone = clone $this;
        $clone->solver = $this->solver->withBlocked($ranges);
        $clone->baseline = null;
        $clone->checked = [];

        return $clone;
    }

    private function solve(MoveQuery $query): CheckOutcome
    {
        $baseline = $this->baseline();
        if (!$baseline->check()->isResolved()) {
            return new CheckOutcome(MoveCheck::notChecked($baseline->check()->isRefused() ? MoveCheck::BASELINE_REFUSED : (string) $baseline->check()->reason()));
        }
        if (\in_array($query->package(), $this->notFromComposerRepository, true)) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::NOT_FROM_COMPOSER_REPOSITORY));
        }
        $request = $query->request();
        $inline = $this->attempt($request->withTransitive(SolveRequest::LISTED));
        if (!$inline instanceof Solution || $inline->resolves()) {
            return $inline instanceof Solution ? $this->resolved(MoveCheck::INLINE, $inline, $query) : $inline;
        }
        // `-W` frees more packages than `-w`, and Composer 2.9 and later then block more of their
        // locked versions, so `-W` can refuse a move that `-w` resolves: each form is asked in turn.
        foreach ([SolveRequest::WITH_DEPENDENCIES => MoveCheck::WITH_DEPENDENCIES, SolveRequest::WITH_ALL_DEPENDENCIES => MoveCheck::WITH_ALL_DEPENDENCIES] as $transitive => $form) {
            $solution = $this->attempt($request->withTransitive($transitive));
            if (!$solution instanceof Solution || $solution->resolves()) {
                return $solution instanceof Solution ? $this->resolved($form, $solution, $query) : $solution;
            }
            $inline = $solution;
        }

        return $this->refusal($inline, $query);
    }

    /**
     * The solution, or the outcome of a solve that the budget stops or that needs a name the
     * source could not load.
     *
     * @return Solution|CheckOutcome
     */
    private function attempt(SolveRequest $request)
    {
        if ($this->deadline->isPast()) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::INSTALL_TIME_BUDGET));
        }
        $solution = $this->solver->solve($request);
        $unloaded = $solution->resolves() ? null : $this->notLoaded($solution);

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

    /** The reason of a refusal that needs a name the source could not load, null for a real refusal. */
    private function notLoaded(Solution $solution): ?string
    {
        $failures = array_values($solution->unloaded());
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
