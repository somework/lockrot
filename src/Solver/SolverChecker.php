<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Package\BasePackage;
use Composer\Semver\Comparator;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
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
                $this->baseline = $solution->resolves() ? new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE)) : new CheckOutcome(MoveCheck::refused(), [], $solution->problem());
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
        if ($this->installsUnread($solution)) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::ADVISORY_LOOKUP_INCOMPLETE));
        }

        return $solution->resolves()
            ? new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE))
            : new CheckOutcome(MoveCheck::refused(), Holder::distinct($solution->holds()), $solution->problem());
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

    public function blockingDisabledBy(): array
    {
        return $this->advisories === null ? [] : $this->advisories->blocking()->disabledBy();
    }

    public function withAdvisories(array $records, array $covered): MoveChecker
    {
        if (!$this->blocksAdvisories || $this->advisories === null) {
            return $this;
        }
        $clone = clone $this;
        $clone->advisories = $this->advisories->withRecords($records, $covered);
        $clone->solver = $this->solver->withAdvisories($clone->advisories);
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
        if ($names === []) {
            // `composer update --lock` allows a name that the lock does not hold, as the baseline does.
            $solution = $this->attempt(SolveRequest::unlock([SolveRequest::BASELINE_NAME]));
            if (!$solution instanceof Solution) {
                return $solution;
            }

            return $solution->resolves() ? new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE)) : new CheckOutcome(MoveCheck::refused(), Holder::distinct($solution->holds()));
        }
        $tried = $this->forms(SolveRequest::unlock($names), $names);
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
        $tried = $this->forms($query->request(), [$query->package()], $query->newest());
        if ($tried instanceof CheckOutcome) {
            return $tried;
        }
        [$form, $solution] = $tried;
        if ($form !== null && $this->installsUnread($solution)) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::ADVISORY_LOOKUP_INCOMPLETE));
        }

        return $form === null ? $this->refusal($solution, $query) : $this->resolved($form, $solution, $query);
    }

    public function joint(MoveQuery $query, array $holders): CheckOutcome
    {
        $unchecked = $this->unchecked();
        if ($unchecked !== null) {
            return $unchecked;
        }
        $solution = $this->attempt($query->jointRequest($holders), $query->package(), ...$holders);
        if (!$solution instanceof Solution) {
            return $solution;
        }
        if (!$solution->resolves()) {
            return $this->refusal($solution, $query);
        }
        if ($this->installsUnread($solution)) {
            return new CheckOutcome(MoveCheck::notChecked(MoveCheck::ADVISORY_LOOKUP_INCOMPLETE));
        }

        return (new CheckOutcome(MoveCheck::resolves(MoveCheck::INLINE, $solution->installs()[$query->package()] ?? null)))->withChanges($solution->installs(), $solution->updatedFrom());
    }

    /**
     * The request as listed, then `-w`, then `-W`: the first form that resolves and installs
     * $newest, else the resolving form that installs the highest release, the first on a tie, with
     * its solution; a null form and the last refusal; or the outcome of a solve that did not run.
     *
     * @param non-empty-list<string> $moved the names that the request moves, the move's package first
     *
     * @return array{0: MoveCheck::INLINE|MoveCheck::WITH_DEPENDENCIES|MoveCheck::WITH_ALL_DEPENDENCIES|null, 1: Solution}|CheckOutcome
     */
    private function forms(SolveRequest $request, array $moved, ?string $newest = null)
    {
        $last = null;
        $resolved = null;
        // `-W` frees more packages than `-w`, and Composer 2.9 and later then block more of their
        // locked versions, so `-W` can refuse a move that `-w` resolves: each form is asked in turn.
        foreach ([SolveRequest::LISTED => MoveCheck::INLINE, SolveRequest::WITH_DEPENDENCIES => MoveCheck::WITH_DEPENDENCIES, SolveRequest::WITH_ALL_DEPENDENCIES => MoveCheck::WITH_ALL_DEPENDENCIES] as $transitive => $form) {
            $solution = $this->attempt($request->withTransitive($transitive), ...$moved);
            if (!$solution instanceof Solution) {
                return $resolved ?? $solution;
            }
            if ($solution->resolves() && self::installsNewest($solution, $moved[0], $newest)) {
                return [$form, $solution];
            }
            if ($solution->resolves()) {
                $resolved = $resolved !== null && !self::installsHigher($solution, $resolved[1], $moved[0]) ? $resolved : [$form, $solution];
            } else {
                $last = $solution;
            }
        }

        return $resolved ?? [null, $last];
    }

    /** Whether the solve installs $newest of $package, or $newest is null. */
    private static function installsNewest(Solution $solution, string $package, ?string $newest): bool
    {
        if ($newest === null) {
            return true;
        }
        $installs = $solution->installs()[$package] ?? null;
        $parser = new VersionParser();
        try {
            return $installs !== null && $parser->normalize($installs) === $parser->normalize($newest);
        } catch (\UnexpectedValueException $e) {
            return false;
        }
    }

    /**
     * Whether $solution installs a higher release of $package than $than. A solve that installs a
     * release is higher than one that keeps or drops the package. False when a release does not parse.
     */
    private static function installsHigher(Solution $solution, Solution $than, string $package): bool
    {
        $installs = $solution->installs()[$package] ?? null;
        $thanInstalls = $than->installs()[$package] ?? null;
        if ($installs === null || $thanInstalls === null) {
            return $installs !== null;
        }
        $parser = new VersionParser();
        try {
            return Comparator::greaterThan($parser->normalize($installs), $parser->normalize($thanInstalls));
        } catch (\UnexpectedValueException $e) {
            return false;
        }
    }

    /** Composer blocks only the advisories that it reads, so a solve that installs a name whose records the lookup could not read proves nothing. */
    private function installsUnread(Solution $solution): bool
    {
        return $this->advisories !== null && $this->advisories->unread(array_keys($solution->installs())) !== [];
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
        return new CheckOutcome(MoveCheck::resolves($form, $solution->installs()[$query->package()] ?? null));
    }

    private function refusal(Solution $last, MoveQuery $query): CheckOutcome
    {
        $target = $this->release($query->package(), $query->version());
        $scanned = $target === null ? [] : $this->scan->of($target, (new VersionParser())->normalize($this->platformPhp));

        // A move publishes its holds, never Composer's problem text, which is costly to render.
        $outcome = new CheckOutcome(MoveCheck::refused(), Holder::distinct(array_merge($last->holds(), $scanned)));
        if ($query->isRequire()) {
            return $outcome;
        }
        // An update keeps the root requirements on the package beside its inline constraint.
        $parser = new VersionParser();
        $range = [$parser->parseConstraints($query->constraint())];
        $root = $this->solver->root();
        foreach ([$root->getRequires(), $root->getDevRequires()] as $links) {
            if (isset($links[$query->package()])) {
                $range[] = $links[$query->package()]->getConstraint();
            }
        }

        return \count($range) === 1 ? $outcome : $outcome->withRange(MultiConstraint::create($range, true));
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
