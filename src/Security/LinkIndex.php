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
 * holder. A link on a name that no package of the lock carries is also a link on each package that
 * replaces or provides that name at `self.version`: Composer satisfies the name with that package,
 * at the package's own version (https://getcomposer.org/doc/04-schema.md#replace). A conflict
 * applies only through a replaced name: Composer reads no conflict on a provided one.
 *
 * @internal
 */
final class LinkIndex
{
    private const SELF_VERSION = 'self.version';

    /** @var array<string, array<int, array{0: Holder, 1: ?ConstraintInterface}>> package name => its links keyed by index order, with the parsed constraint */
    private array $links = [];
    /** @var array<string, array<string, bool>> package => each name it replaces or provides at its own version => whether it replaces it */
    private array $alsoNamed = [];
    private int $added = 0;

    public static function of(LockFile $lock, ProjectConfig $project): self
    {
        $index = new self();
        $parser = new VersionParser();
        $root = $project->name();
        foreach ([Holder::REQUIRE => ProjectConfig::REQUIRE, Holder::REQUIRE_DEV => ProjectConfig::REQUIRE_DEV, Holder::CONFLICT => ProjectConfig::CONFLICT] as $link => $section) {
            foreach ($project->constraints($section) as $target => $constraint) {
                $index->add($parser, (string) $target, new Holder(Holder::ROOT, $root, null, $link, $constraint), null);
            }
        }
        $packages = $lock->packages(true);
        $locked = [];
        foreach ($packages as $package) {
            $locked[$package->name()] = true;
        }
        foreach ($packages as $package) {
            foreach ([Holder::REQUIRE => $package->requireConstraints(), Holder::CONFLICT => $package->conflicts()] as $link => $constraints) {
                foreach ($constraints as $target => $constraint) {
                    if ($target !== $package->name()) {
                        $index->add($parser, (string) $target, new Holder(Holder::PACKAGE, $package->name(), $package->version(), $link, $constraint), $package->version());
                    }
                }
            }
            foreach ([[$package->provides(), false], [$package->replaces(), true]] as [$names, $replaced]) {
                foreach ($names as $name => $constraint) {
                    if (!isset($locked[$name]) && $constraint === self::SELF_VERSION) {
                        $index->alsoNamed[$package->name()][$name] = $replaced;
                    }
                }
            }
        }

        return $index;
    }

    /**
     * The links that exclude the release, in index order: the root first, then the lock's packages.
     * A requirement excludes what it does not match, a conflict what it matches. A constraint that
     * does not parse excludes nothing. Links that read the same, such as one package's requirements
     * on two names that one package replaces, give one holder.
     *
     * @return list<Holder>
     */
    public function excluding(string $package, string $normalized): array
    {
        $package = strtolower($package);
        $release = new Constraint('==', $normalized);
        $links = $this->links[$package] ?? [];
        foreach ($this->alsoNamed[$package] ?? [] as $name => $replaced) {
            foreach ($this->links[$name] ?? [] as $order => $link) {
                if ($replaced || $link[0]->link() !== Holder::CONFLICT) {
                    $links[$order] = $link;
                }
            }
        }
        ksort($links);
        $holders = [];
        foreach ($links as [$holder, $constraint]) {
            if ($constraint === null || $holder->package() === $package || \in_array($holder, $holders)) {
                continue;
            }
            $matches = $constraint->matches($release);
            if ($holder->link() === Holder::CONFLICT ? $matches : !$matches) {
                $holders[] = $holder;
            }
        }

        return $holders;
    }

    /** @param ?string $selfVersion the version of the package that holds the link, null for the root */
    private function add(VersionParser $parser, string $target, Holder $holder, ?string $selfVersion): void
    {
        $text = $holder->constraint();
        if ($selfVersion !== null && $text === self::SELF_VERSION) {
            $text = $selfVersion;
        }
        try {
            $constraint = $parser->parseConstraints($text);
        } catch (\UnexpectedValueException $e) {
            $constraint = null;
        }
        $this->links[$target][$this->added++] = [$holder, $constraint];
    }
}
