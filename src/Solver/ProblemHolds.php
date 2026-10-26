<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Rule;
use Composer\DependencyResolver\SolverProblemsException;
use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\ConstraintInterface;
use Lockrot\Security\Holder;

/**
 * The holds that the rules of a refusal name. The solver stops at the first failing chain, so the
 * list can be short of `composer prohibits` ({@see HoldScan} adds the rest).
 *
 * | Rule | Hold |
 * |---|---|
 * | root requirement | the root: on a moved name `require`, or `require-dev` when that section holds the constraint and `require` does not; `platform` on a platform name; else `root-require` |
 * | package requirement | the requiring package: `platform` on a platform name, else `require` |
 * | package conflict | the package or the root that declares it: `conflict` |
 * | same name | the package or the root that replaces the name: `replace` |
 * | root requirement of a requested name | the root, when the pool holds no release of it and no other rule names a hold: `root-require` on the moved package |
 *
 * A root requirement of a dependency is no rule of its own: the dependency shows as a requirement
 * with no candidate left, and the hold names the root's constraint on it as `root-require`.
 *
 * @internal
 */
final class ProblemHolds
{
    /** @var list<Holder> */
    private array $holds = [];
    /** @var array<string, string> */
    private array $wanted = [];
    /** The request's own requirement when the pool holds no release of it. */
    private ?Holder $noRelease = null;
    /** @var array<string, Link> */
    private array $requires = [];
    /** @var array<string, Link> */
    private array $devRequires = [];
    /** @var array<string, string> name => the normalized version of each locked package */
    private array $locked = [];

    /**
     * @param array<string, string> $moved     the names on the allow list: a link to one of them has no target
     * @param array<string, string> $requested the root requirements that the request writes: a refusal of one names the request, not a hold
     * @param array<string, string> $locked    name => the normalized version of each locked package: a hold at that release is marked locked
     */
    public static function of(SolverProblemsException $e, Pool $pool, RootPackageInterface $root, array $moved, array $requested = [], array $locked = []): self
    {
        $out = new self();
        $out->locked = $locked;
        $out->requires = $root->getRequires();
        $out->devRequires = $root->getDevRequires();
        $rootRequires = array_merge($root->getDevRequires(), $root->getRequires());
        foreach ($e->getProblems() as $problem) {
            foreach ($problem->getReasons() as $section) {
                foreach ($section as $rule) {
                    if ($rule instanceof Rule) {
                        $out->add($rule, $pool, $root->getName(), $rootRequires, $moved, $requested);
                    }
                }
            }
        }
        // The request's own requirement holds nothing while another hold explains the refusal.
        if ($out->holds === [] && $out->noRelease !== null) {
            $out->holds[] = $out->noRelease;
        }

        return $out;
    }

    /** @return list<Holder> */
    public function holds(): array
    {
        return $this->holds;
    }

    /** @return array<string, string> name => name, for each name that a requirement wants and that the pool holds no version of */
    public function wanted(): array
    {
        return $this->wanted;
    }

    /**
     * @param array<string, Link>   $rootRequires
     * @param array<string, string> $moved
     * @param array<string, string> $requested
     */
    private function add(Rule $rule, Pool $pool, string $rootName, array $rootRequires, array $moved, array $requested): void
    {
        $reason = $rule->getReason();
        $data = $rule->getReasonData();
        if ($reason === Rule::RULE_ROOT_REQUIRE) {
            /** @var array{packageName: string, constraint: ConstraintInterface} $data */
            $this->addRootRequire($rule, $data['packageName'], $data['constraint']->getPrettyString(), $rootName, $moved, $requested);
        } elseif ($reason === Rule::RULE_PACKAGE_REQUIRES || $reason === Rule::RULE_PACKAGE_CONFLICT) {
            /** @var Link $data */
            $this->addLink($rule, $data, $pool, $rootName, $rootRequires, $moved);
        } elseif ($reason === Rule::RULE_PACKAGE_SAME_NAME) {
            /** @var string $data */
            $this->addReplaces($rule, $data, $pool, $rootName, $moved);
        }
    }

    /**
     * @param array<string, string> $moved
     * @param array<string, string> $requested
     */
    private function addRootRequire(Rule $rule, string $name, string $constraint, string $rootName, array $moved, array $requested): void
    {
        if ($rule->getLiterals() === []) {
            $this->wanted[$name] = $name;
        }
        if (!isset($requested[$name])) {
            $this->holds[] = $this->rootHold($rootName, $name, $constraint, $moved);
        } elseif ($rule->getLiterals() === []) {
            $this->noRelease = new Holder(Holder::ROOT, $rootName, null, Holder::ROOT_REQUIRE, $constraint);
        }
    }

    /**
     * @param array<string, Link> $rootRequires
     * @param array<string, string> $moved
     */
    private function addLink(Rule $rule, Link $data, Pool $pool, string $rootName, array $rootRequires, array $moved): void
    {
        $isRequire = $rule->getReason() === Rule::RULE_PACKAGE_REQUIRES;
        $source = $rule->getSourcePackage($pool);
        if ($source instanceof AliasPackage) {
            $source = $source->getAliasOf();
        }
        $target = $data->getTarget();
        $platform = PlatformRepository::isPlatformPackage($target);
        $link = $platform ? Holder::PLATFORM : ($isRequire ? Holder::REQUIRE : Holder::CONFLICT);
        // A conflict rule can name the moved package as either side: the target is the other one.
        $declarer = $data->getSource();
        $other = \in_array($target, $moved, true) ? null : $target;
        $this->holds[] = $declarer === $rootName
            ? new Holder(Holder::ROOT, $rootName, null, $link, $data->getPrettyConstraint(), $other)
            : $this->marked(new Holder(Holder::PACKAGE, $declarer, $source->getPrettyVersion(), $link, $data->getPrettyConstraint(), $other), $source);
        // One literal is a requirement with no candidate left: a conflict rule has two.
        if (\count($rule->getLiterals()) === 1 && !$platform) {
            $this->wanted[$target] = $target;
            $byRoot = $rootRequires[$target] ?? null;
            if ($byRoot !== null && $other !== null) {
                $this->holds[] = new Holder(Holder::ROOT, $rootName, null, Holder::ROOT_REQUIRE, $byRoot->getPrettyConstraint(), $target);
            }
        }
    }

    /** @param array<string, string> $moved */
    private function addReplaces(Rule $rule, string $name, Pool $pool, string $rootName, array $moved): void
    {
        foreach ($rule->getLiterals() as $literal) {
            $package = $pool->literalToPackage($literal);
            $package = $package instanceof AliasPackage ? $package->getAliasOf() : $package;
            $replace = $package->getReplaces()[$name] ?? null;
            if ($replace !== null) {
                $target = \in_array($name, $moved, true) ? null : $name;
                $this->holds[] = $package->getName() === $rootName
                    ? new Holder(Holder::ROOT, $rootName, null, Holder::REPLACE, $replace->getPrettyConstraint(), $target)
                    : $this->marked(new Holder(Holder::PACKAGE, $package->getName(), $package->getPrettyVersion(), Holder::REPLACE, $replace->getPrettyConstraint(), $target), $package);
            }
        }
    }

    private function marked(Holder $hold, PackageInterface $package): Holder
    {
        return ($this->locked[$package->getName()] ?? null) === $package->getVersion() ? $hold->asLocked() : $hold;
    }

    /**
     * The composer.json section of the constraint that the rule names: `require` when it holds that constraint.
     *
     * @return Holder::REQUIRE|Holder::REQUIRE_DEV
     */
    private function section(string $name, string $constraint): string
    {
        $require = $this->requires[$name] ?? null;
        if (!isset($this->devRequires[$name]) || ($require !== null && $require->getPrettyConstraint() === $constraint)) {
            return Holder::REQUIRE;
        }

        return Holder::REQUIRE_DEV;
    }

    /** @param array<string, string> $moved */
    private function rootHold(string $rootName, string $name, string $constraint, array $moved): Holder
    {
        if (PlatformRepository::isPlatformPackage($name)) {
            return new Holder(Holder::ROOT, $rootName, null, Holder::PLATFORM, $constraint, $name);
        }

        return \in_array($name, $moved, true)
            ? new Holder(Holder::ROOT, $rootName, null, $this->section($name, $constraint), $constraint)
            : new Holder(Holder::ROOT, $rootName, null, Holder::ROOT_REQUIRE, $constraint, $name);
    }
}
