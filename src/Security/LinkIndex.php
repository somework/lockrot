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
 * at the package's own version (https://getcomposer.org/doc/04-schema.md#replace).
 *
 * @internal
 */
final class LinkIndex
{
    private const SELF_VERSION = 'self.version';

    /** @var array<string, list<array{0: int, 1: Holder, 2: ?ConstraintInterface}>> package name => its links in index order, with the parsed constraint */
    private array $links = [];
    /** @var array<string, list<string>> package => the names it replaces or provides at its own version */
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
                        $index->add($parser, (string) $target, new Holder(Holder::PACKAGE, $package->name(), $package->version(), $link, $constraint), $package->normalizedVersion() ?? $package->version());
                    }
                }
            }
            foreach ($package->replaces() + $package->provides() as $name => $constraint) {
                if (!isset($locked[$name]) && strtolower($constraint) === self::SELF_VERSION) {
                    $index->alsoNamed[$package->name()][] = (string) $name;
                }
            }
        }

        return $index;
    }

    /**
     * The links that exclude the release, in index order: the root first, then the lock's packages.
     * A requirement excludes what it does not match, a conflict what it matches. A constraint that
     * does not parse excludes nothing. A holder with several links on the package and on the names
     * it replaces is listed once per link kind.
     *
     * @return list<Holder>
     */
    public function excluding(string $package, string $normalized): array
    {
        $package = strtolower($package);
        $release = new Constraint('==', $normalized);
        $links = [];
        foreach (array_merge([$package], $this->alsoNamed[$package] ?? []) as $name) {
            foreach ($this->links[$name] ?? [] as $link) {
                $links[] = $link;
            }
        }
        usort($links, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $holders = [];
        foreach ($links as [, $holder, $constraint]) {
            $key = $holder->source().' '.$holder->package().' '.$holder->link();
            if ($constraint === null || isset($holders[$key]) || $holder->package() === $package) {
                continue;
            }
            $matches = $constraint->matches($release);
            if ($holder->link() === Holder::CONFLICT ? $matches : !$matches) {
                $holders[$key] = $holder;
            }
        }

        return array_values($holders);
    }

    /** @param ?string $selfVersion the normalised version of the package that holds the link, null for the root */
    private function add(VersionParser $parser, string $target, Holder $holder, ?string $selfVersion): void
    {
        $text = $holder->constraint();
        if ($selfVersion !== null && strtolower($text) === self::SELF_VERSION) {
            $text = $selfVersion;
        }
        try {
            $constraint = $parser->parseConstraints($text);
        } catch (\UnexpectedValueException $e) {
            $constraint = null;
        }
        $this->links[$target][] = [$this->added++, $holder, $constraint];
    }
}
