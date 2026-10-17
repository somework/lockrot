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

    public function solve(SolveRequest $request): Solution
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
            $temporary = [];
        }
        $policy = new DefaultPolicy($root->getPreferStable(), false);
        [$set, $composerRequest, $pool] = $this->pool($root, $requires, $rootRequires, $temporary, $request, $policy, null);
        if ($this->blocked !== []) {
            // The first pool tells which packages stay locked. Composer blocks every other affected version.
            $stayLocked = [];
            foreach ($composerRequest->getLockedPackages() as $package) {
                $stayLocked[$package->getName()] = $package->getVersion();
            }
            [$set, $composerRequest, $pool] = $this->pool($root, $requires, $rootRequires, $temporary, $request, $policy, $stayLocked);
        }

        try {
            $transaction = (new Solver($policy, $pool, new NullIO()))->solve($composerRequest, $this->filter);
        } catch (SolverProblemsException $e) {
            $holds = ProblemHolds::of($e, $pool, $root, $request->allow());
            $unloaded = [];
            foreach ($holds->wanted() as $name) {
                $failure = $this->source->failure($name);
                if ($failure !== null) {
                    $unloaded[$name] = $failure;
                }
            }

            return Solution::refused($holds->holds(), trim($e->getPrettyString($set, $composerRequest, $pool, false)), $unloaded);
        }

        return Solution::resolved(self::installs($transaction));
    }

    /**
     * @param array<string, ConstraintInterface> $requires
     * @param array<string, ConstraintInterface> $rootRequires
     * @param array<string, ConstraintInterface> $temporary
     * @param array<string, string>|null         $stayLocked the version of each name that stays locked, null to block no advisory
     *
     * @return array{0: RepositorySet, 1: Request, 2: Pool}
     */
    private function pool(RootPackageInterface $root, array $requires, array $rootRequires, array $temporary, SolveRequest $request, DefaultPolicy $policy, ?array $stayLocked): array
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
        $set->addRepository(new LoadedVersions($this->source, $this->keeps($stayLocked)));
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

        return [$set, $composerRequest, $set->createPool($composerRequest, new NullIO(), null, new PoolOptimizer($policy))];
    }

    /**
     * Whether the pool may offer a loaded version: every version, or with advisories to block,
     * a dev version, the locked version of a name that stays locked, and a version outside every
     * range, as SecurityAdvisoryPoolFilter keeps them.
     *
     * @param array<string, string>|null $stayLocked
     *
     * @return \Closure(BasePackage): bool
     */
    private function keeps(?array $stayLocked): \Closure
    {
        if ($stayLocked === null) {
            return static fn (BasePackage $package): bool => true;
        }
        $blocked = $this->blocked;

        return static function (BasePackage $package) use ($blocked, $stayLocked): bool {
            $base = $package instanceof AliasPackage ? $package->getAliasOf() : $package;
            if ($base->isDev() || ($stayLocked[$base->getName()] ?? null) === $base->getVersion()) {
                return true;
            }
            $exact = new Constraint('==', $base->getVersion());
            foreach ($blocked[$base->getName()] ?? [] as $range) {
                if ($range->matches($exact)) {
                    return false;
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
