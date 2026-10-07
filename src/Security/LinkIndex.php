<?php

declare(strict_types=1);

namespace Lockrot\Security;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;

/**
 * Every link of the lock on a package name: the root's `require`, `require-dev` and `conflict`,
 * and the `require` and `conflict` of every locked package, packages-dev included whatever `--dev`
 * says. `composer update` resolves with dev packages, so a dev package that holds a fix is a
 * holder (SPEC-0.14 5.3).
 *
 * @internal
 */
final class LinkIndex
{
    /** @var array<string, list<array{0: Holder, 1: ?ConstraintInterface}>> package name => its links, with the parsed constraint */
    private array $links = [];

    public static function of(LockFile $lock, ProjectConfig $project): self
    {
        $index = new self();
        $parser = new VersionParser();
        $root = $project->name();
        foreach ([Holder::REQUIRE => ProjectConfig::REQUIRE, Holder::REQUIRE_DEV => ProjectConfig::REQUIRE_DEV, Holder::CONFLICT => ProjectConfig::CONFLICT] as $link => $section) {
            foreach ($project->constraints($section) as $target => $constraint) {
                $index->add($parser, (string) $target, new Holder(Holder::ROOT, $root, null, $link, $constraint));
            }
        }
        foreach ($lock->packages(true) as $package) {
            foreach ([Holder::REQUIRE => $package->requireConstraints(), Holder::CONFLICT => $package->conflicts()] as $link => $constraints) {
                foreach ($constraints as $target => $constraint) {
                    if ($target !== $package->name()) {
                        $index->add($parser, (string) $target, new Holder(Holder::PACKAGE, $package->name(), $package->version(), $link, $constraint));
                    }
                }
            }
        }

        return $index;
    }

    /**
     * The links that exclude the release, in index order: the root first, then the lock's packages.
     * A requirement excludes what it does not match, a conflict what it matches. A constraint that
     * does not parse excludes nothing.
     *
     * @return list<Holder>
     */
    public function excluding(string $package, string $normalized): array
    {
        $release = new Constraint('==', $normalized);
        $holders = [];
        foreach ($this->links[strtolower($package)] ?? [] as [$holder, $constraint]) {
            if ($constraint === null) {
                continue;
            }
            $matches = $constraint->matches($release);
            if ($holder->link() === Holder::CONFLICT ? $matches : !$matches) {
                $holders[] = $holder;
            }
        }

        return $holders;
    }

    private function add(VersionParser $parser, string $target, Holder $holder): void
    {
        try {
            $constraint = $parser->parseConstraints($holder->constraint());
        } catch (\UnexpectedValueException $e) {
            $constraint = null;
        }
        $this->links[$target][] = [$holder, $constraint];
    }
}
