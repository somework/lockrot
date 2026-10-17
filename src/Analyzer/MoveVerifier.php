<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Composer\Composer;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\LockTransaction;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\PoolOptimizer;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Rule;
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
use Composer\Repository\ArrayRepository;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositorySet;
use Composer\Repository\RootPackageRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MultiConstraint;

/**
 * Spike: runs Composer's own Solver offline for one move, on the locked packages and the versions
 * that lockrot already loaded. It builds the request that `composer update <names> --dry-run` builds
 * in `Composer\Installer::doUpdate()`, without the repositories of the project.
 *
 * @internal
 */
final class MoveVerifier
{
    public const PLATFORM_MACHINE = 'machine';
    public const PLATFORM_IGNORE_EXT = 'ignore-ext';

    private RootPackageInterface $root;
    private LockArrayRepository $locked;
    /** @var array<string, string|false> */
    private array $platformOverrides;
    private string $platformMode;
    private ?PlatformRepository $platform = null;
    /** @var array<string, list<ConstraintInterface>> */
    private array $affected;

    /**
     * @param array<string, string|false>              $platformOverrides `config.platform` with `php` set to the target PHP
     * @param array<string, list<ConstraintInterface>> $affected          advisory ranges by name: Composer 2.9+ blocks these versions in an update
     */
    public function __construct(RootPackageInterface $root, LockArrayRepository $locked, array $platformOverrides, string $platformMode, array $affected = [])
    {
        $this->affected = $affected;
        $this->root = $root;
        $this->locked = $locked;
        $this->platformOverrides = $platformOverrides;
        $this->platformMode = $platformMode;
    }

    /**
     * @param array<string, list<BasePackage>> $versionsByName the loaded versions, aliases included
     * @param list<string>                     $allow          the update allow list
     * @param array<string, string>            $inline         `name => constraint` as `update name:constraint`
     * @param array<string, string>            $rootChange     `name => constraint` as `require name:constraint`
     * @param Request::UPDATE_*                $transitive     0 plain, 1 `-w`, 2 `-W`
     *
     * @return array{ok: bool, changes: array<string, array{0: ?string, 1: ?string}>, holds: list<array<string, ?string>>, problem: ?string, unloaded: list<string>, seconds: float, pool: int}
     */
    public function verify(array $versionsByName, array $allow, array $inline, array $rootChange, int $transitive): array
    {
        $start = hrtime(true);
        $root = $this->rootWith($rootChange);
        $platform = $this->platform();
        $filter = $this->filter();

        $rootRequires = [];
        foreach (array_merge($root->getRequires(), $root->getDevRequires()) as $name => $link) {
            if ($filter->isIgnored($name)) {
                continue;
            }
            $rootRequires[$name] = $link->getConstraint();
        }
        $fixedRoot = clone $root;
        $fixedRoot->setRequires([]);
        $fixedRoot->setDevRequires([]);

        $stabilityFlags = $root->getStabilityFlags();
        $stabilityFlags[$root->getName()] = BasePackage::$stabilities[VersionParser::parseStability($root->getVersion())];

        $parser = new VersionParser();
        $temporary = [];
        foreach ($inline as $name => $constraint) {
            $temporary[$name] = $parser->parseConstraints($constraint);
        }

        $requests = [];
        foreach (array_merge($root->getRequires(), $root->getDevRequires()) as $name => $link) {
            $requests[$name] = $link->getConstraint();
        }
        if (!self::hasTemporaryConstraints()) {
            // Composer 2.2 has no temporary constraints: the inline constraint joins the root requirement.
            foreach ($temporary as $name => $constraint) {
                $joined = isset($requests[$name]) ? MultiConstraint::create([$requests[$name], $constraint], true) : $constraint;
                $requests[$name] = $joined;
                if (!$filter->isIgnored($name)) {
                    $rootRequires[$name] = $joined;
                }
            }
            $temporary = [];
        }

        $policy = new DefaultPolicy($root->getPreferStable(), false);
        $io = new NullIO();
        $build = function (?array $keepLocked) use ($root, $stabilityFlags, $rootRequires, $temporary, $fixedRoot, $platform, $versionsByName, $requests, $allow, $transitive, $policy, $io): array {
            $repositorySet = new RepositorySet($root->getMinimumStability(), $stabilityFlags, $root->getAliases(), $root->getReferences(), $rootRequires, $temporary);
            // A package takes one repository, so each pass gets its own copy of the root.
            $passRoot = clone $fixedRoot;
            $repositorySet->addRepository(new RootPackageRepository($passRoot));
            $repositorySet->addRepository($platform);
            $repositorySet->addRepository(new LoadedVersions($this->unblocked($versionsByName, $keepLocked)));
            $repositorySet->addRepository($this->locked);

            $request = new Request($this->locked);
            $request->fixPackage($passRoot);
            $provided = $root->getProvides();
            foreach ($platform->getPackages() as $package) {
                if (!isset($provided[$package->getName()])) {
                    $request->fixPackage($package);
                }
            }
            foreach ($requests as $name => $constraint) {
                $request->requireName($name, $constraint);
            }
            // Composer before 2.6 reads the allow list from the keys, as Installer::setUpdateAllowList() flips it.
            $request->setUpdateAllowList(self::allowListAsKeys() ? array_flip($allow) : $allow, $transitive);
            $optimizer = class_exists(PoolOptimizer::class) ? new PoolOptimizer($policy) : null;

            return [$repositorySet, $request, $repositorySet->createPool($request, $io, null, $optimizer)];
        };
        [$repositorySet, $request, $pool] = $build(null);
        if ($this->affected !== []) {
            // The first pool tells which packages stay locked. Composer blocks every other affected version.
            $lockedNames = [];
            foreach ($request->getLockedPackages() as $package) {
                $lockedNames[$package->getName()] = true;
            }
            [$repositorySet, $request, $pool] = $build($lockedNames);
        }
        $solver = new Solver($policy, $pool, $io);

        try {
            $transaction = $solver->solve($request, $filter);
        } catch (SolverProblemsException $e) {
            $holds = [];
            foreach ($e->getProblems() as $problem) {
                foreach ($problem->getReasons() as $section) {
                    foreach ((array) $section as $rule) {
                        $hold = $this->hold($rule, $pool, $root->getName());
                        if ($hold !== null && !\in_array($hold, $holds, true)) {
                            $holds[] = $hold;
                        }
                    }
                }
            }

            return [
                'ok' => false,
                'changes' => [],
                'holds' => $holds,
                'problem' => $e->getPrettyString($repositorySet, $request, $pool, false),
                'unloaded' => $this->blockedByUnloaded($holds, $versionsByName),
                'pool_unloaded' => $this->unloaded($pool, $versionsByName),
                'seconds' => (hrtime(true) - $start) / 1e9,
                'pool' => \count($pool),
            ];
        }

        return [
            'ok' => true,
            'changes' => $this->changes($transaction),
            'holds' => [],
            'problem' => null,
            'unloaded' => [],
            'pool_unloaded' => $this->unloaded($pool, $versionsByName),
            'seconds' => (hrtime(true) - $start) / 1e9,
            'pool' => \count($pool),
        ];
    }

    /**
     * Drops the versions that an advisory affects, as SecurityAdvisoryPoolFilter does, except a dev
     * version and the locked version of a name that stays locked. A null map drops nothing.
     *
     * @param array<string, list<BasePackage>> $versionsByName
     * @param array<string, true>|null         $keepLocked
     *
     * @return list<BasePackage>
     */
    private function unblocked(array $versionsByName, ?array $keepLocked): array
    {
        if ($keepLocked === null) {
            return array_merge([], ...array_values($versionsByName));
        }
        $kept = [];
        foreach ($versionsByName as $name => $versions) {
            $lockedVersions = isset($keepLocked[$name]) ? array_map(static fn (BasePackage $p): string => $p->getVersion(), $this->locked->findPackages($name)) : [];
            foreach ($versions as $package) {
                $base = $package instanceof AliasPackage ? $package->getAliasOf() : $package;
                if (!$base->isDev() && !\in_array($base->getVersion(), $lockedVersions, true)) {
                    $exact = new Constraint('==', $base->getVersion());
                    foreach ($this->affected[$name] ?? [] as $range) {
                        if ($range->matches($exact)) {
                            continue 2;
                        }
                    }
                }
                $kept[] = $package;
            }
        }

        return $kept;
    }

    public static function allowListAsKeys(): bool
    {
        return version_compare(Composer::getVersion(), '2.6', '<');
    }

    /** Composer 2.9 and later remove the versions that an advisory affects from an update's pool. */
    public static function blocksAdvisories(): bool
    {
        return class_exists('Composer\\DependencyResolver\\SecurityAdvisoryPoolFilter');
    }

    public static function hasTemporaryConstraints(): bool
    {
        return (new \ReflectionMethod(RepositorySet::class, '__construct'))->getNumberOfParameters() >= 6;
    }

    /** @param array<string, string> $rootChange */
    private function rootWith(array $rootChange): RootPackageInterface
    {
        if ($rootChange === []) {
            return $this->root;
        }
        $root = clone $this->root;
        $parser = new VersionParser();
        $requires = $root->getRequires();
        $devRequires = $root->getDevRequires();
        foreach ($rootChange as $name => $constraint) {
            $link = new Link($root->getName(), $name, $parser->parseConstraints($constraint), 'requires', $constraint);
            if (isset($devRequires[$name]) && !isset($requires[$name])) {
                $devRequires[$name] = new Link($root->getName(), $name, $link->getConstraint(), 'requires (for development)', $constraint);
                continue;
            }
            $requires[$name] = $link;
        }
        $root->setRequires($requires);
        $root->setDevRequires($devRequires);

        return $root;
    }

    private function platform(): PlatformRepository
    {
        if ($this->platform === null) {
            $this->platform = new PlatformRepository([], $this->platformOverrides);
        }

        return $this->platform;
    }

    private function filter(): PlatformRequirementFilterInterface
    {
        return $this->platformMode === self::PLATFORM_IGNORE_EXT
            ? PlatformRequirementFilterFactory::fromBoolOrList(['ext-*'])
            : PlatformRequirementFilterFactory::ignoreNothing();
    }

    /** @return array<string, ?string>|null */
    private function hold(Rule $rule, Pool $pool, string $rootName): ?array
    {
        $data = $rule->getReasonData();
        switch ($rule->getReason()) {
            case Rule::RULE_ROOT_REQUIRE:
                return [
                    'source' => 'root',
                    'package' => null,
                    'version' => null,
                    'link' => PlatformRepository::isPlatformPackage($data['packageName']) ? 'platform' : 'root-require',
                    'target' => $data['packageName'],
                    'constraint' => $data['constraint']->getPrettyString(),
                ];
            case Rule::RULE_FIXED:
                $package = $data['package'];
                if ($package->getName() === $rootName) {
                    return null;
                }

                return [
                    'source' => PlatformRepository::isPlatformPackage($package->getName()) ? 'platform' : 'lock',
                    'package' => $package->getName(),
                    'version' => $package->getPrettyVersion(),
                    'link' => 'fixed',
                    'target' => null,
                    'constraint' => null,
                ];
            case Rule::RULE_PACKAGE_REQUIRES:
            case Rule::RULE_PACKAGE_CONFLICT:
                /** @var Link $data */
                $literals = $rule->getLiterals();
                $source = $rule->getReason() === Rule::RULE_PACKAGE_REQUIRES || \count($literals) < 2
                    ? $pool->literalToPackage($literals[0])
                    : $rule->getSourcePackage($pool);
                if ($source instanceof AliasPackage) {
                    $source = $source->getAliasOf();
                }
                $isRequire = $rule->getReason() === Rule::RULE_PACKAGE_REQUIRES;

                return [
                    'source' => $data->getSource() === $rootName ? 'root' : 'package',
                    'package' => $data->getSource(),
                    'version' => $source->getPrettyVersion(),
                    'link' => PlatformRepository::isPlatformPackage($data->getTarget()) ? 'platform' : ($isRequire ? 'require' : 'conflict'),
                    'target' => $data->getTarget(),
                    'constraint' => $data->getPrettyConstraint(),
                    'unsatisfiable' => $isRequire && \count($literals) === 1 ? 'yes' : null,
                ];
        }

        return null;
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    private function changes(LockTransaction $transaction): array
    {
        $changes = [];
        foreach ($transaction->getOperations() as $operation) {
            if ($operation instanceof UpdateOperation) {
                $changes[$operation->getTargetPackage()->getName()] = [$operation->getInitialPackage()->getPrettyVersion(), $operation->getTargetPackage()->getPrettyVersion()];
            } elseif ($operation instanceof InstallOperation) {
                $changes[$operation->getPackage()->getName()] = [null, $operation->getPackage()->getPrettyVersion()];
            } elseif ($operation instanceof UninstallOperation) {
                $changes[$operation->getPackage()->getName()] = [$operation->getPackage()->getPrettyVersion(), null];
            }
        }
        ksort($changes);

        return $changes;
    }

    /**
     * Names that a refused move requires with no candidate at all, and that lockrot did not load:
     * the refusal can come from missing metadata, not from the project.
     *
     * @param list<array<string, ?string>>      $holds
     * @param array<string, list<BasePackage>> $versionsByName
     *
     * @return list<string>
     */
    private function blockedByUnloaded(array $holds, array $versionsByName): array
    {
        $names = [];
        foreach ($holds as $hold) {
            $target = $hold['target'];
            if (($hold['unsatisfiable'] ?? null) === 'yes' && !PlatformRepository::isPlatformPackage($target) && !isset($versionsByName[$target]) && $this->locked->findPackages($target) === []) {
                $names[$target] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Names that a package in the pool requires, that no package in the pool provides, and that
     * lockrot did not load: the solver had no versions of them to try.
     *
     * @param array<string, list<BasePackage>> $versionsByName
     *
     * @return list<string>
     */
    private function unloaded(Pool $pool, array $versionsByName): array
    {
        $present = [];
        foreach ($pool->getPackages() as $package) {
            $present[$package->getName()] = true;
            foreach (array_merge($package->getProvides(), $package->getReplaces()) as $link) {
                $present[$link->getTarget()] = true;
            }
        }
        $missing = [];
        foreach ($pool->getPackages() as $package) {
            foreach ($package->getRequires() as $target => $link) {
                if (!isset($present[$target]) && !isset($versionsByName[$target]) && !PlatformRepository::isPlatformPackage($target)) {
                    $missing[$target] = true;
                }
            }
        }
        $names = array_keys($missing);
        sort($names);

        return $names;
    }
}

/**
 * Holds the package objects that a ComposerRepository returned, without taking them over: an
 * ArrayRepository sets itself as the repository of each package, and a package refuses a second one.
 *
 * @internal
 */
final class LoadedVersions extends ArrayRepository
{
    /** @var list<BasePackage> */
    private array $loaded;

    /** @param list<BasePackage> $loaded */
    public function __construct(array $loaded)
    {
        parent::__construct();
        $this->loaded = $loaded;
    }

    protected function initialize()
    {
        $this->packages = $this->loaded;
    }
}
