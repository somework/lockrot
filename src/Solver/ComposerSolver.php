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
use Composer\Package\Link;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositorySet;
use Composer\Repository\RootPackageRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MultiConstraint;

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
        $root = $this->rootWith($request->rootChanges());
        $links = array_merge($root->getRequires(), $root->getDevRequires());
        $requires = [];
        $rootRequires = [];
        foreach ($links as $name => $link) {
            $requires[$name] = $link->getConstraint();
            if (!$this->filter->isIgnored($name)) {
                $rootRequires[$name] = $link->getConstraint();
            }
        }
        $parser = new VersionParser();
        $requested = $request->rootChanges();
        $temporary = [];
        foreach ($request->inline() as $name => $constraint) {
            $temporary[$name] = $parser->parseConstraints($constraint);
        }
        if (!ComposerFeatures::hasTemporaryConstraints()) {
            // Composer 2.2 and 2.3 have no temporary constraints: the inline constraint joins the root requirement.
            foreach ($temporary as $name => $constraint) {
                $requires[$name] = isset($requires[$name]) ? MultiConstraint::create([$requires[$name], $constraint], true) : $constraint;
                $rootRequires[$name] = $requires[$name];
            }
            $requested += $request->inline();
            $temporary = [];
        }
        $policy = new DefaultPolicy($root->getPreferStable(), false);
        [$set, $composerRequest, $pool] = $this->pool($root, $requires, $rootRequires, $temporary, $request, $policy);

        try {
            $transaction = (new Solver($policy, $pool, new NullIO()))->solve($composerRequest, $this->filter);
        } catch (SolverProblemsException $e) {
            $holds = ProblemHolds::of($e, $pool, $root, $request->allow(), $requested);
            $unloaded = [];
            foreach ($holds->wanted() as $name) {
                $failure = $this->source->failure($name);
                if ($failure !== null) {
                    $unloaded[$name] = $failure;
                }
            }

            return Solution::refused($holds->holds(), static fn (): string => self::problemText($e->getPrettyString($set, $composerRequest, $pool, false)), $unloaded);
        }

        return Solution::resolved(self::installs($transaction));
    }

    /**
     * @param array<string, ConstraintInterface> $requires
     * @param array<string, ConstraintInterface> $rootRequires
     * @param array<string, ConstraintInterface> $temporary
     *
     * @return array{0: RepositorySet, 1: Request, 2: Pool}
     */
    private function pool(RootPackageInterface $root, array $requires, array $rootRequires, array $temporary, SolveRequest $request, DefaultPolicy $policy): array
    {
        $flags = $root->getStabilityFlags();
        $flags[$root->getName()] = BasePackage::$stabilities[VersionParser::parseStability($root->getVersion())];
        $arguments = [$root->getMinimumStability(), $flags, $root->getAliases(), $root->getReferences(), $rootRequires];
        if (ComposerFeatures::hasTemporaryConstraints()) {
            $arguments[] = $temporary;
        }
        $set = new RepositorySet(...$arguments);
        // A package takes one repository, so each pass gets its own copy of the root.
        $fixedRoot = clone $root;
        if (!$fixedRoot instanceof BasePackage) {
            throw new \LogicException('the root package is no BasePackage: '.\get_class($fixedRoot));
        }
        $fixedRoot->setRequires([]);
        $fixedRoot->setDevRequires([]);
        $set->addRepository(new RootPackageRepository($fixedRoot));
        $set->addRepository($this->platform);
        $set->addRepository(new LoadedVersions($this->source));
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
        // Composer before 2.6 reads the names from the keys, and later versions from the values.
        $allow = $request->allow();
        $composerRequest->setUpdateAllowList(array_combine($allow, $allow), $request->transitive());

        $blocking = $this->advisories === null ? null : $this->advisories->blocking();
        if ($this->blocked === [] && ($blocking === null || !$blocking->blocks())) {
            return [$set, $composerRequest, $set->createPool($composerRequest, new NullIO(), null, new PoolOptimizer($policy))];
        }
        // Composer 2.9 and later filter the built pool, then optimize it: the builder marks the packages that stay locked.
        $pool = $set->createPool($composerRequest, new NullIO());
        // By name and version, as Composer's isLockedPackage() reads it: a root alias of a locked release keeps the release.
        $stayLocked = [];
        foreach ($composerRequest->getLockedPackages() as $package) {
            $base = $package instanceof AliasPackage ? $package->getAliasOf() : $package;
            $stayLocked[$base->getName().' '.$base->getVersion()] = true;
        }
        // Composer looks up the advisories of every pool package that can move: packages-dev and new dependencies too.
        $names = [];
        foreach ($pool->getPackages() as $package) {
            $base = $package instanceof AliasPackage ? $package->getAliasOf() : $package;
            if (!$base instanceof RootPackageInterface && !PlatformRepository::isPlatformPackage($base->getName()) && !isset($stayLocked[$base->getName().' '.$base->getVersion()])) {
                $names[$base->getName()] = true;
            }
        }
        $blocked = $this->blocked;
        foreach ($this->advisories === null ? [] : $this->advisories->ranges(array_keys($names)) as $name => $ranges) {
            $blocked[$name] = array_merge($blocked[$name] ?? [], $ranges);
        }
        $kept = array_values(array_filter($pool->getPackages(), self::keeps($blocked, $stayLocked, $blocking)));
        if (\count($kept) < \count($pool->getPackages())) {
            // Composer before 2.9 keeps the removed versions private: they only word the problem text.
            // @phpstan-ignore function.alreadyNarrowedType (Composer before 2.9 has no getAllRemovedVersions())
            $removed = method_exists($pool, 'getAllRemovedVersions') ? [$pool->getAllRemovedVersions(), $pool->getAllRemovedVersionsByPackage()] : [[], []];
            $pool = new Pool($kept, $pool->getUnacceptableFixedOrLockedPackages(), ...$removed);
        }

        return [$set, $composerRequest, (new PoolOptimizer($policy))->optimize($composerRequest, $pool)];
    }

    /**
     * Whether the pool may offer a version, as SecurityAdvisoryPoolFilter keeps them: not an
     * abandoned package that the config blocks, then a dev version, the locked version of a name
     * that stays locked, and a version outside every blocked range of each of its names.
     *
     * @param array<string, list<ConstraintInterface>> $blocked
     * @param array<string, true>                      $stayLocked by `name version`
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
            $exact = new Constraint('==', $base->getVersion());
            foreach ($base->getNames(false) as $name) {
                foreach ($blocked[$name] ?? [] as $range) {
                    if ($range->matches($exact)) {
                        return false;
                    }
                }
            }

            return true;
        };
    }

    /** @param array<string, string> $changes name => constraint, as `require name:constraint` */
    private function rootWith(array $changes): RootPackageInterface
    {
        if ($changes === []) {
            return $this->root;
        }
        $root = clone $this->root;
        $parser = new VersionParser();
        $requires = $root->getRequires();
        $devRequires = $root->getDevRequires();
        foreach ($changes as $name => $constraint) {
            $parsed = $parser->parseConstraints($constraint);
            if (isset($devRequires[$name]) && !isset($requires[$name])) {
                $devRequires[$name] = new Link($root->getName(), $name, $parsed, Link::TYPE_DEV_REQUIRE, $constraint);
                continue;
            }
            $requires[$name] = new Link($root->getName(), $name, $parsed, Link::TYPE_REQUIRE, $constraint);
        }
        $root->setRequires($requires);
        $root->setDevRequires($devRequires);

        return $root;
    }

    /**
     * Composer's text without the version of the platform that runs lockrot: Composer appends it to
     * each override (`actual: 8.5.1`, `same as actual`), and lockrot overrides `php` with the target.
     */
    private static function problemText(string $text): string
    {
        return trim((string) preg_replace('/, (?:actual: [^)]*|same as actual)\)/', ')', $text));
    }

    /** @return array<string, string> name => the pretty version that the solve installs, for each name it installs or moves */
    private static function installs(LockTransaction $transaction): array
    {
        $installs = [];
        foreach ($transaction->getOperations() as $operation) {
            if ($operation instanceof UpdateOperation) {
                $installs[$operation->getTargetPackage()->getName()] = $operation->getTargetPackage()->getPrettyVersion();
            } elseif ($operation instanceof InstallOperation) {
                $installs[$operation->getPackage()->getName()] = $operation->getPackage()->getPrettyVersion();
            }
        }
        ksort($installs);

        return $installs;
    }
}
