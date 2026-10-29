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
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Lockrot\Analyzer\PhpWindow;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Runs Composer's own Solver offline for one request. It builds the request that
 * `Composer\Installer::doUpdate()` builds for `composer update <names> --dry-run`: the root's
 * stability settings and requirements, the lock as the locked packages, the platform, and the
 * remote versions of the names that the pool builder asks for ({@see LoadedVersions}). It ignores
 * the requirements on {@see IGNORED_PLATFORM} that `config.platform` does not set ({@see HostPlatformFilter}).
 *
 * @internal
 */
final class ComposerSolver
{
    /** The platform patterns that the machine running lockrot provides, in the order of `run.move_check.ignored_platform`. */
    public const IGNORED_PLATFORM = ['ext-*', 'lib-*', ...PhpWindow::HOST_FEATURES];
    /** The package types that Composer\Installer::$ignoredTypes leaves out of an update's pool. */
    private const IGNORED_TYPES = ['php-ext', 'php-ext-zend'];

    private RootPackageInterface $root;
    private LockArrayRepository $locked;
    private PlatformRepository $platform;
    private VersionSource $source;
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

    /** @param list<string> $configuredPlatform the lower-case names that `config.platform` sets */
    public function __construct(RootPackageInterface $root, LockArrayRepository $locked, PlatformRepository $platform, VersionSource $source, array $configuredPlatform = [])
    {
        $this->root = $root;
        $this->locked = $locked;
        $this->platform = $platform;
        $this->source = $source;
        $this->filter = new HostPlatformFilter($configuredPlatform);
    }

    /** A copy whose solves block what Composer blocks in an update, with the records that $advisories serves. */
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

    /**
     * A move check never stops the run: any throwable is a failed solve. Composer's solver is its
     * internal API, so a Composer minor can remove a method that lockrot calls, and that call then
     * throws an \Error at lockrot's own call site.
     */
    public function solve(SolveRequest $request): Solution
    {
        try {
            return $this->solveOrThrow($request);
        } catch (\Throwable $e) {
            return Solution::failed($e);
        }
    }

    private function solveOrThrow(SolveRequest $request): Solution
    {
        $root = $this->rootWith($request->rootChanges(), $request->devChanges());
        $requires = [];
        foreach (self::rootLinks($root) as $name => $link) {
            $requires[$name] = $link->getConstraint();
        }
        $parser = new VersionParser();
        $temporary = [];
        foreach ($request->inline() as $name => $constraint) {
            $temporary[$name] = $parser->parseConstraints($constraint);
        }
        $preferred = $this->preferredVersions($request);
        $policy = new DefaultPolicy($root->getPreferStable(), false, $preferred);
        [$set, $composerRequest, $built, $fixedRoot, $loaded, $feed] = $this->pool($root, $requires, $temporary, $request);
        [$pool, $answer] = $this->answer($policy, $composerRequest, $built, $fixedRoot, $preferred);
        if ($answer instanceof SolverProblemsException) {
            $locked = [];
            foreach ($this->locked->getPackages() as $package) {
                $locked[$package->getName()] = $package->getVersion();
            }
            $holds = ProblemHolds::of($answer, $pool, $root, $request->moved(), $request->rootChanges(), $locked);
            $setsPhp = $this->setsPhp;
            // A shadowed name has loaded versions, so the refusal never wants it, yet its hidden releases can change the answer.
            $shadowed = array_intersect($this->failures($loaded->asked()), [VersionSource::SHADOWED]);

            return Solution::refused($holds->holds(), static fn (): string => self::problemText($answer->getPrettyString($set, $composerRequest, $pool, false), $setsPhp), $this->failures($holds->wanted()) + $shadowed + $this->lockedOnlyWanted($built, $holds->wanted()));
        }

        // A pool that lacks a name the builder asked for can resolve at a release that the full pool would not choose.
        // A virtual name, which a package provides, is unreached too: only a refusal that wants an unreached name proves nothing.
        // Composer blocks a release by each name that it replaces too.
        $held = array_merge([], ...array_map(static fn (BasePackage $package): array => $package->getNames(false), $answer->getNewLockPackages(false)));

        return Solution::resolved(self::installs($answer), self::updatedFrom($answer), array_diff($this->failures($loaded->asked()), [VersionSource::UNREACHED]), $feed === null ? [] : array_values(array_intersect($feed->unread(), $held)));
    }

    /**
     * The root links that Composer's solve reads: a require-dev link replaces the require link of its name.
     *
     * @return array<string, Link>
     */
    public static function rootLinks(RootPackageInterface $root): array
    {
        return array_merge($root->getRequires(), $root->getDevRequires());
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
     * The pool builder takes a freed name from the first repository that has it, so it never asks
     * the source for a name that {@see withLockedOnly()} offers.
     *
     * @param array<string, string> $wanted
     *
     * @return array<string, string> name => {@see VersionSource::UNREACHED}, for each wanted name that the pool takes from the lock only
     */
    private function lockedOnlyWanted(Pool $built, array $wanted): array
    {
        $unreached = [];
        if ($this->lockedOnly === null) {
            return $unreached;
        }
        foreach ($built->getPackages() as $package) {
            if (isset($wanted[$package->getName()]) && $package->getRepository() === $this->lockedOnly) {
                $unreached[$package->getName()] = VersionSource::UNREACHED;
            }
        }

        return $unreached;
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
     * @return array{0: RepositorySet, 1: Request, 2: Pool, 3: BasePackage, 4: LoadedVersions, 5: PoolAdvisoryFeed|null} the set, the request, the built pool, the root's copy in it, the remote versions and the advisory feed of a solve that blocks
     */
    private function pool(RootPackageInterface $root, array $requires, array $temporary, SolveRequest $request): array
    {
        $flags = $root->getStabilityFlags();
        $flags[$root->getName()] = BasePackage::$stabilities[VersionParser::parseStability($root->getVersion())];
        $set = new RepositorySet($root->getMinimumStability(), $flags, $root->getAliases(), $root->getReferences(), $requires, $temporary);
        // A package takes one repository, so each pass gets its own copy of the root. Each
        // RootPackageInterface class of composer/composer 2.10 extends BasePackage:
        // https://github.com/composer/composer/tree/2.10.3/src/Composer/Package
        /** @var BasePackage&RootPackageInterface $fixedRoot */
        $fixedRoot = clone $root;
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

        $pool = $set->createPool($composerRequest, new NullIO(), null, null, self::IGNORED_TYPES);
        if ($this->advisories === null || !$this->advisories->blocking()->blocks()) {
            return [$set, $composerRequest, $pool, $fixedRoot, $loaded, null];
        }
        // Composer filters the built pool, then optimizes it, as PoolBuilder::buildPool() does.
        $filter = $this->advisories->blocking()->poolFilter();
        $error = $this->advisories->blocking()->filterError();
        if ($filter === null) {
            throw new \RuntimeException('Composer\'s advisory pool filter cannot be built: '.($error === null ? 'unknown error' : $error->getMessage()));
        }
        $feed = new PoolAdvisoryFeed($this->advisories);
        try {
            $pool = $filter->filter($pool, [$feed], $composerRequest);
        } catch (\Error $e) {
            // The filter is Composer's internal API. The `move_check_failed` note names it as the
            // source of a drift, which an \Error from inside Composer does not.
            throw new \RuntimeException('Composer\'s advisory pool filter failed: '.$e->getMessage());
        }

        return [$set, $composerRequest, $pool, $fixedRoot, $loaded, $feed];
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
     * Composer's text as its console prints it without colour, and without the version of the platform that runs lockrot: Composer appends it to
     * each override (`actual: 8.5.1`, `same as actual`), and lockrot overrides `php` with the target.
     * When lockrot chose that php, Composer's `; overridden via config.platform` on it goes too.
     */
    private static function problemText(string $text, bool $setsPhp): string
    {
        try {
            $text = (new OutputFormatter(false))->format($text) ?? $text;
        } catch (\InvalidArgumentException $markup) {
            // Composer puts a repository's advisory id into the text unescaped, and a style that the id names can be unknown.
        }
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
