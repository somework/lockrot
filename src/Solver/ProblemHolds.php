<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Rule;
use Composer\DependencyResolver\SolverProblemsException;
use Composer\Package\AliasPackage;
use Composer\Package\Link;
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
 * | root requirement | the root: `require` on a moved name, `platform` on a platform name, else `root-require` |
 * | package requirement | the requiring package: `platform` on a platform name, else `require` |
 * | package conflict | the package that declares it: `conflict` |
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
    /** @var array<string, true> */
    private array $wanted = [];

    /**
     * @param list<string>          $moved     the names on the allow list: a link to one of them has no target
     * @param array<string, string> $requested the root requirements that the request writes: a refusal of one names the request, not a hold
     */
    public static function of(SolverProblemsException $e, Pool $pool, RootPackageInterface $root, array $moved, array $requested = []): self
    {
        $out = new self();
        $rootRequires = array_merge($root->getDevRequires(), $root->getRequires());
        foreach ($e->getProblems() as $problem) {
            foreach ($problem->getReasons() as $section) {
                foreach ((array) $section as $rule) {
                    if ($rule instanceof Rule) {
                        $out->add($rule, $pool, $root->getName(), $rootRequires, $moved, $requested);
                    }
                }
            }
        }

        return $out;
    }

    /** @return list<Holder> */
    public function holds(): array
    {
        return $this->holds;
    }

    /** @return list<string> the names that a requirement wants and that the pool holds no version of */
    public function wanted(): array
    {
        return array_keys($this->wanted);
    }

    /**
     * @param array<string, Link>   $rootRequires
     * @param list<string>          $moved
     * @param array<string, string> $requested
     */
    private function add(Rule $rule, Pool $pool, string $rootName, array $rootRequires, array $moved, array $requested): void
    {
        $data = $rule->getReasonData();
        switch ($rule->getReason()) {
            case Rule::RULE_ROOT_REQUIRE:
                /** @var array{packageName: string, constraint: ConstraintInterface} $data */
                $name = $data['packageName'];
                if (isset($requested[$name])) {
                    return;
                }
                $this->holds[] = self::rootHold($rootName, $name, $data['constraint']->getPrettyString(), $moved);

                return;
            case Rule::RULE_PACKAGE_REQUIRES:
            case Rule::RULE_PACKAGE_CONFLICT:
                if (!$data instanceof Link) {
                    return;
                }
                $isRequire = $rule->getReason() === Rule::RULE_PACKAGE_REQUIRES;
                $literals = $rule->getLiterals();
                $source = $isRequire || \count($literals) < 2 ? $pool->literalToPackage($literals[0]) : $rule->getSourcePackage($pool);
                if ($source instanceof AliasPackage) {
                    $source = $source->getAliasOf();
                }
                $target = $data->getTarget();
                $platform = PlatformRepository::isPlatformPackage($target);
                $link = $platform ? Holder::PLATFORM : ($isRequire ? Holder::REQUIRE : Holder::CONFLICT);
                // A conflict rule can name the moved package as either side: the target is the other one.
                $declarer = $data->getSource();
                $other = \in_array($target, $moved, true) ? null : $target;
                $this->holds[] = new Holder(Holder::PACKAGE, $declarer, $source->getPrettyVersion(), $link, $data->getPrettyConstraint(), $other);
                if ($isRequire && \count($literals) === 1 && !$platform) {
                    $this->wanted[$target] = true;
                    $byRoot = $rootRequires[$target] ?? null;
                    if ($byRoot !== null && $other !== null) {
                        $this->holds[] = new Holder(Holder::ROOT, $rootName, null, Holder::ROOT_REQUIRE, $byRoot->getPrettyConstraint(), $target);
                    }
                }

                return;
        }
    }

    /** @param list<string> $moved */
    private static function rootHold(string $rootName, string $name, string $constraint, array $moved): Holder
    {
        if (PlatformRepository::isPlatformPackage($name)) {
            return new Holder(Holder::ROOT, $rootName, null, Holder::PLATFORM, $constraint, $name);
        }

        return \in_array($name, $moved, true)
            ? new Holder(Holder::ROOT, $rootName, null, Holder::REQUIRE, $constraint)
            : new Holder(Holder::ROOT, $rootName, null, Holder::ROOT_REQUIRE, $constraint, $name);
    }
}
