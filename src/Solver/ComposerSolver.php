<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\LockTransaction;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\PoolOptimizer;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver;
use Composer\DependencyResolver\SolverProblemsException;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface;
use Composer\IO\NullIO;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\ArrayRepository;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositorySet;
use Composer\Repository\RootPackageRepository;
use Composer\Semver\CompilingMatcher;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * Runs Composer's own Solver offline for one request. It builds the request that
 * `Composer\Installer::doUpdate()` builds for `composer update <names> --dry-run`: the root's
 * stability settings and requirements, the lock as the locked packages, the platform, and the
 * remote versions of the names that the pool builder asks for ({@see LoadedVersions}). It ignores
 * `ext-*` requirements, as `--ignore-platform-req=ext-*` does.
 *
 * @internal
 */
final class ComposerSolver
{
    private RootPackageInterface $root;
    private LockArrayRepository $locked;
    private PlatformRepository $platform;
    private VersionSource $source;
    /** @var array<string, list<ConstraintInterface>> */
    private array $blocked;
    private PlatformRequirementFilterInterface $filter;
    private ?PoolAdvisories $advisories = null;
    private ?ArrayRepository $lockedOnly = null;
    private bool $setsPhp = false;
    private bool $minimalChanges = false;
    private string $lastInputs = '';
    /** @phpstan-ignore property.onlyWritten (the last built pool keeps the object ids of its packages taken: {@see SolverInputs}) */
    private Pool $lastBuilt;
    /** @var array{0: Pool, 1: LockTransaction|SolverProblemsException} the optimized pool and Composer's answer of the last solve */
    private array $lastAnswer;

    /**
     * @param array<string, list<ConstraintInterface>> $blocked the advisory ranges by name that Composer 2.9 and later block in an update, none to block nothing
     */
    public function __construct(RootPackageInterface $root, LockArrayRepository $locked, PlatformRepository $platform, VersionSource $source, array $blocked)
    {
        $this->root = $root;
        $this->locked = $locked;
        $this->platform = $platform;
        $this->source = $source;
        $this->blocked = $blocked;
        $this->filter = PlatformRequirementFilterFactory::fromBoolOrList(['ext-*']);
    }

    /** @param array<string, list<ConstraintInterface>> $blocked */
    public function withBlocked(array $blocked): self
    {
        $clone = clone $this;
        $clone->blocked = $blocked;

        return $clone;
    }

    /** A copy that blocks what Composer 2.9 and later block in an update, for every name that the pool holds. */
    public function withAdvisories(PoolAdvisories $advisories): self
    {
        $clone = clone $this;
        $clone->advisories = $advisories;

        return $clone;
    }

    /**
     * A copy that offers each name at its locked version only, in place of the path or VCS
     * repository that serves it: Composer's pool builder never loads a package from the lock's own
     * repository.
     *
     * @param list<string> $names
     */
    public function withLockedOnly(array $names): self
    {
        $clone = clone $this;
        $clone->lockedOnly = new ArrayRepository();
        foreach ($names as $name) {
            foreach (self::copies($this->locked->findPackages($name)) as $package) {
                $clone->lockedOnly->addPackage($package);
            }
        }

        return $clone;
    }

    /** A copy whose problem text drops Composer's config.platform words on php: lockrot, not the project, set its version. */
    public function withPhpSetByLockrot(): self
    {
        $clone = clone $this;
        $clone->setsPhp = true;

        return $clone;
    }

    /** A copy that solves as `--minimal-changes` does: each locked name off the allow list prefers its locked release. */
    public function withMinimalChanges(): self
    {
        $clone = clone $this;
        $clone->minimalChanges = true;

        return $clone;
    }

    public function root(): RootPackageInterface
    {
        return $this->root;
    }

    public function locked(): LockArrayRepository
    {
        return $this->locked;
    }

    public function source(): VersionSource
    {
        return $this->source;
    }

    /** A move check never stops the run: an exception other than a refusal is a failed solve, and an \Error passes up. */
    public function solve(SolveRequest $request): Solution
    {
        try {
            return $this->solveOrThrow($request);
        } catch (\Exception $e) {
            return Solution::failed($e);
        }
    }

    private function solveOrThrow(SolveRequest $request): Solution
    {
        $root = $this->rootWith($request->rootChanges(), $request->devChanges());
        $links = array_merge($root->getRequires(), $root->getDevRequires());
        $requires = [];
        foreach ($links as $name => $link) {
            $requires[$name] = $link->getConstraint();
        }
        $parser = new VersionParser();
        $temporary = [];
        foreach ($request->inline() as $name => $constraint) {
            $temporary[$name] = $parser->parseConstraints($constraint);
        }
        $preferred = $this->preferredVersions($request);
        $policy = new DefaultPolicy($root->getPreferStable(), false, $preferred);
        [$set, $composerRequest, $pool, $fixedRoot, $loaded] = $this->pool($root, $requires, $temporary, $request);
        [$pool, $answer] = $this->answer($policy, $composerRequest, $pool, $fixedRoot, $preferred);
        if ($answer instanceof SolverProblemsException) {
            $locked = [];
            foreach ($this->locked->getPackages() as $package) {
                $locked[$package->getName()] = $package->getVersion();
            }
            $holds = ProblemHolds::of($answer, $pool, $root, $request->moved(), $request->rootChanges(), $locked);
            $setsPhp = $this->setsPhp;
            // A shadowed name has loaded versions, so the refusal never wants it, yet its hidden releases can change the answer.
            $shadowed = array_intersect($this->failures($loaded->asked()), [VersionSource::SHADOWED]);

            return Solution::refused($holds->holds(), static fn (): string => self::problemText($answer->getPrettyString($set, $composerRequest, $pool, false), $setsPhp), $this->failures($holds->wanted()) + $shadowed);
        }

        // A pool that lacks a name the builder asked for can resolve at a release that the full pool would not choose.
        // A virtual name, which a package provides, is unreached too: only a refusal that wants an unreached name proves nothing.
        return Solution::resolved(self::installs($answer), self::updatedFrom($answer), array_diff($this->failures($loaded->asked()), [VersionSource::UNREACHED]));
    }

    /**
     * As Installer::createPolicy() builds them for an update.
     *
     * @return array<string, string>|null name => locked version, null without minimal changes
     */
    private function preferredVersions(SolveRequest $request): ?array
    {
        if (!$this->minimalChanges) {
            return null;
        }
        $preferred = [];
        $allow = $request->allow();
        foreach ($this->locked->getPackages() as $package) {
            if (!$package instanceof AliasPackage && !isset($allow[$package->getName()])) {
                $preferred[$package->getName()] = $package->getVersion();
            }
        }

        return $preferred;
    }

    /**
     * @param array<string> $names
     *
     * @return array<string, string> the {@see VersionSource} failure of each name that has one
     */
    private function failures(array $names): array
    {
        $failures = [];
        foreach ($names as $name) {
            $failure = $this->source->failure($name);
            if ($failure !== null) {
                $failures[$name] = $failure;
            }
        }

        return $failures;
    }

    /**
     * The optimized pool and Composer's answer: the transaction, or the refusal. The forms of one
     * move often build the same pool, and Composer then answers the same.
     *
     * @param array<string, string>|null $preferred the policy's preferred versions
     *
     * @return array{0: Pool, 1: LockTransaction|SolverProblemsException}
     */
    private function answer(DefaultPolicy $policy, Request $request, Pool $pool, BasePackage $root, ?array $preferred): array
    {
        $inputs = SolverInputs::of($pool, $request, $root).serialize($preferred);
        if ($inputs !== $this->lastInputs) {
            $optimized = (new PoolOptimizer($policy))->optimize($request, $pool);
            try {
                $answer = (new Solver($policy, $optimized, new NullIO()))->solve($request, $this->filter);
            } catch (SolverProblemsException $e) {
                $answer = $e;
            }
            $this->lastInputs = $inputs;
            $this->lastBuilt = $pool;
            $this->lastAnswer = [$optimized, $answer];
        } else {
            // A pool numbers its packages, and Composer's problem text reads the numbers: number them
            // as the optimizer did.
            new Pool($this->lastAnswer[0]->getPackages());
        }

        return $this->lastAnswer;
    }

    /**
     * @param array<string, ConstraintInterface> $requires  the root requirements: RepositorySet leaves out the platform ones
     * @param array<string, ConstraintInterface> $temporary
     *
     * @return array{0: RepositorySet, 1: Request, 2: Pool, 3: BasePackage, 4: LoadedVersions} the set, the request, the built pool, the root's copy in it and the remote versions
     */
    private function pool(RootPackageInterface $root, array $requires, array $temporary, SolveRequest $request): array
    {
        $flags = $root->getStabilityFlags();
        $flags[$root->getName()] = BasePackage::$stabilities[VersionParser::parseStability($root->getVersion())];
        $set = new RepositorySet($root->getMinimumStability(), $flags, $root->getAliases(), $root->getReferences(), $requires, $temporary);
        // A package takes one repository, so each pass gets its own copy of the root.
        $fixedRoot = clone $root;
        if (!$fixedRoot instanceof BasePackage) {
            throw new \LogicException('the root package is no BasePackage: '.\get_class($fixedRoot));
        }
        $fixedRoot->setRequires([]);
        $set->addRepository(new RootPackageRepository($fixedRoot));
        if ($this->lockedOnly !== null) {
            $set->addRepository($this->lockedOnly);
        }
        $loaded = new LoadedVersions($this->source);
        $set->addRepository($loaded);
        $set->addRepository($this->locked);

        $composerRequest = new Request($this->locked);
        $composerRequest->fixPackage($fixedRoot);
        $provided = $root->getProvides();
        foreach ($this->platform->getPackages() as $package) {
            $provider = $provided[$package->getName()] ?? null;
            if ($provider === null || !$provider->getConstraint()->matches(new Constraint('=', $package->getVersion()))) {
                $composerRequest->fixPackage($package);
            }
        }
        foreach ($requires as $name => $constraint) {
            $composerRequest->requireName($name, $constraint);
        }
        $composerRequest->setUpdateAllowList($request->allow(), $request->transitive());

        // Composer 2.9 and later filter the built pool, then optimize it: the builder marks the packages that stay locked.
        $pool = $set->createPool($composerRequest, new NullIO());
        // By name and version, as Composer's isLockedPackage() reads it: a root alias of a locked release keeps the release.
        $stayLocked = [];
        foreach ($composerRequest->getLockedPackages() as $package) {
            $base = $package instanceof AliasPackage ? $package->getAliasOf() : $package;
            $key = $base->getName().' '.$base->getVersion();
            $stayLocked[$key] = $key;
        }
        // Composer looks up the advisories of every pool package that can move: packages-dev and new dependencies too.
        $names = [];
        foreach ($pool->getPackages() as $package) {
            $base = $package instanceof AliasPackage ? $package->getAliasOf() : $package;
            if (!$base instanceof RootPackageInterface && !PlatformRepository::isPlatformPackage($base->getName()) && !isset($stayLocked[$base->getName().' '.$base->getVersion()])) {
                $names[$base->getName()] = $base->getName();
            }
        }
        $blocking = $this->advisories === null ? null : $this->advisories->blocking();
        $blocked = $this->blocked;
        foreach ($this->advisories === null ? [] : $this->advisories->ranges($names) as $name => $ranges) {
            $blocked[$name] = array_merge($blocked[$name] ?? [], $ranges);
        }

        return [$set, $composerRequest, new Pool(array_filter($pool->getPackages(), self::keeps($blocked, $stayLocked, $blocking)), $pool->getUnacceptableFixedOrLockedPackages()), $fixedRoot, $loaded];
    }

    /**
     * Whether the pool may offer a version, as SecurityAdvisoryPoolFilter keeps them: not an
     * abandoned package that the config blocks, then a dev version, the locked version of a name
     * that stays locked, and a version outside every blocked range of each of its names.
     *
     * @param array<string, list<ConstraintInterface>> $blocked
     * @param array<string, string>                    $stayLocked by `name version`
     *
     * @return \Closure(BasePackage): bool
     */
    private static function keeps(array $blocked, array $stayLocked, ?AdvisoryBlocking $blocking): \Closure
    {
        return static function (BasePackage $package) use ($blocked, $stayLocked, $blocking): bool {
            $base = $package instanceof AliasPackage ? $package->getAliasOf() : $package;
            if ($base instanceof RootPackageInterface) {
                return true;
            }
            if ($blocking !== null && $blocking->blocksAbandoned($base)) {
                return false;
            }
            if ($base->isDev() || isset($stayLocked[$base->getName().' '.$base->getVersion()])) {
                return true;
            }
            foreach ($base->getNames(false) as $name) {
                foreach ($blocked[$name] ?? [] as $range) {
                    if (CompilingMatcher::match($range, Constraint::OP_EQ, $base->getVersion())) {
                        return false;
                    }
                }
            }

            return true;
        };
    }

    /**
     * `composer require` loads composer.json again after it writes the change, so a changed name
     * takes the stability flag and the `#commit` reference of its new constraint and loses its root alias.
     *
     * @param array<string, string> $changes name => constraint, as `require name:constraint` without
     *                                       `--dev` writes it: in `require`, and out of `require-dev`
     * @param array<string, string> $dev     name => name, the changes that `require --dev` writes in `require-dev` instead
     */
    private function rootWith(array $changes, array $dev = []): RootPackageInterface
    {
        $root = clone $this->root;
        $parser = new VersionParser();
        $requires = $root->getRequires();
        $devRequires = $root->getDevRequires();
        foreach ($changes as $name => $constraint) {
            if (isset($dev[$name])) {
                $devRequires[$name] = new Link($root->getName(), $name, $parser->parseConstraints($constraint), Link::TYPE_DEV_REQUIRE, $constraint);
                continue;
            }
            $requires[$name] = new Link($root->getName(), $name, $parser->parseConstraints($constraint), Link::TYPE_REQUIRE, $constraint);
            unset($devRequires[$name]);
        }
        $root->setRequires($requires);
        $root->setDevRequires($devRequires);
        if ($changes !== []) {
            $root->setStabilityFlags(RootPackageLoader::extractStabilityFlags($changes, $root->getMinimumStability(), array_diff_key($root->getStabilityFlags(), $changes)));
            $root->setReferences(RootPackageLoader::extractReferences($changes, array_diff_key($root->getReferences(), $changes)));
            $aliases = [];
            foreach ($root->getAliases() as $alias) {
                if (!isset($changes[$alias['package']])) {
                    $aliases[] = $alias;
                }
            }
            $root->setAliases($aliases);
        }

        return $root;
    }

    /**
     * Copies of the packages and their branch aliases: a package keeps the repository that holds
     * it. A root alias comes from composer.json, as the pool builder adds it, never from the lock.
     *
     * @param array<BasePackage> $packages
     *
     * @return list<BasePackage>
     */
    private static function copies(array $packages): array
    {
        $copies = [];
        $bases = [];
        foreach ($packages as $package) {
            if (!$package instanceof AliasPackage) {
                $copy = clone $package;
                $bases[spl_object_id($package)] = $copy;
                $copies[] = $copy;
            }
        }
        foreach ($packages as $package) {
            $base = $package instanceof AliasPackage && !$package->isRootPackageAlias() ? ($bases[spl_object_id($package->getAliasOf())] ?? null) : null;
            if ($base instanceof CompletePackage) {
                $copies[] = new CompleteAliasPackage($base, $package->getVersion(), $package->getPrettyVersion());
            }
        }

        return $copies;
    }

    /**
     * Composer's text without the version of the platform that runs lockrot: Composer appends it to
     * each override (`actual: 8.5.1`, `same as actual`), and lockrot overrides `php` with the target.
     * When lockrot chose that php, Composer's `; overridden via config.platform` on it goes too.
     */
    private static function problemText(string $text, bool $setsPhp): string
    {
        if ($setsPhp) {
            $text = preg_replace('/(php(?:-[a-z0-9]+)? version \([^;)]*); overridden via config\.platform(?=[,)])/', '$1', $text) ?? $text;
        }

        return trim((string) preg_replace('/, (?:actual: [^)]*|same as actual)\)/', ')', $text));
    }

    /** @return array<string, string> name => the pretty version that the solve installs, for each name it installs or moves to another version */
    private static function installs(LockTransaction $transaction): array
    {
        $installs = [];
        foreach ($transaction->getOperations() as $operation) {
            if ($operation instanceof UpdateOperation && self::movesVersion($operation)) {
                $installs[$operation->getTargetPackage()->getName()] = $operation->getTargetPackage()->getPrettyVersion();
            } elseif ($operation instanceof InstallOperation) {
                $installs[$operation->getPackage()->getName()] = $operation->getPackage()->getPrettyVersion();
            }
        }
        ksort($installs);

        return $installs;
    }

    /** Another reference of the same tag is no move. A branch moves with its commit, not with its metadata. */
    private static function movesVersion(UpdateOperation $operation): bool
    {
        $target = $operation->getTargetPackage();
        $initial = $operation->getInitialPackage();
        if (!$target->isDev()) {
            return $target->getVersion() !== $initial->getVersion();
        }

        return $target->getVersion() !== $initial->getVersion() || ($target->getSourceReference() ?? $target->getDistReference()) !== ($initial->getSourceReference() ?? $initial->getDistReference());
    }

    /** @return array<string, string> name => the locked pretty version, for each name that the solve updates */
    private static function updatedFrom(LockTransaction $transaction): array
    {
        $from = [];
        foreach ($transaction->getOperations() as $operation) {
            if ($operation instanceof UpdateOperation && self::movesVersion($operation)) {
                $from[$operation->getInitialPackage()->getName()] = $operation->getInitialPackage()->getPrettyVersion();
            }
        }

        return $from;
    }
}
