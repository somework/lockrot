<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Lockrot\Security\Holder;

/**
 * A move's check, with the holds and Composer's problem text of a refusal, and the version that a
 * resolved move installs.
 *
 * @internal
 */
final class CheckOutcome
{
    private MoveCheck $check;
    /** @var list<Holder> */
    private array $holds;
    private ?string $problem;
    /** @var array<string, string> */
    private array $changes = [];
    /** @var array<string, string> */
    private array $updatedFrom = [];
    /** @var list<array{package: string, installed_version: ?string, version: string}> */
    private array $movesWith = [];
    private bool $joint = false;
    private ?ConstraintInterface $range = null;

    /** @param list<Holder> $holds */
    public function __construct(MoveCheck $check, array $holds = [], ?string $problem = null)
    {
        $this->check = $check;
        $this->holds = $holds;
        $this->problem = $problem;
    }

    /**
     * A copy that knows every release that the solve installs or moves to, by name, and the
     * locked release of each name that it updates.
     *
     * @param array<string, string> $changes
     * @param array<string, string> $updatedFrom
     */
    public function withChanges(array $changes, array $updatedFrom = []): self
    {
        $clone = clone $this;
        $clone->changes = $changes;
        $clone->updatedFrom = $updatedFrom;

        return $clone;
    }

    /** @return array<string, string> name => the release that the solve installs, for each name it changes */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * The move after its joint request ({@see \Lockrot\Verdict\MoveChecks}, SPEC §5.6). The joint
     * solve resolves the move when it installs the moved package inside the move's constraint and
     * changes a holder's release. The move then has no hold and lists each changed holder. A new
     * install of the same tag is no change, but a branch version can move to another commit.
     * A joint refusal gives its own holds. Any other answer keeps this refusal.
     *
     * @param list<string> $holders
     */
    public function joinedWith(self $joint, string $package, string $constraint, array $holders): self
    {
        $check = $joint->check();
        if ($check->isRefused()) {
            return $joint;
        }
        $installs = $joint->changes()[$package] ?? null;
        if (!$check->isResolved() || $installs === null || !self::satisfies($constraint, $installs)) {
            return $this;
        }
        $movesWith = [];
        foreach ($holders as $holder) {
            $version = $joint->changes()[$holder] ?? null;
            $from = $joint->updatedFrom[$holder] ?? $this->holderVersion($holder);
            if ($version !== null && ($version !== $from || VersionParser::parseStability($version) === 'dev')) {
                $movesWith[] = ['package' => $holder, 'installed_version' => $from, 'version' => $version];
            }
        }
        if ($movesWith === []) {
            return $this;
        }
        $resolved = new self(MoveCheck::resolves(MoveCheck::INLINE, $installs));
        $resolved->movesWith = $movesWith;
        $resolved->joint = true;

        return $resolved;
    }

    /** Whether the joint request resolves the move: then nothing holds it. */
    public function isJoint(): bool
    {
        return $this->joint;
    }

    /** @return list<array{package: string, installed_version: ?string, version: string}> the holders that the joint solve moves, in holder order */
    public function movesWith(): array
    {
        return $this->movesWith;
    }

    /** @return list<string> the packages of the package holds other than $package, in hold order */
    public function holders(string $package): array
    {
        $holders = [];
        foreach ($this->holds as $hold) {
            $name = $hold->package();
            if ($hold->source() === Holder::PACKAGE && $name !== null && $name !== $package && !\in_array($name, $holders, true)) {
                $holders[] = $name;
            }
        }

        return $holders;
    }

    /**
     * A copy without each root requirement on a package that a package hold names as its `package`:
     * that package's own requirement is the hold, and the root requirement is the head of its chain.
     * A conflict or a replace that composer.json declares stays a hold of its own.
     */
    public function withoutRootOnHolders(): self
    {
        $holders = $this->holders('');
        $holds = array_values(array_filter($this->holds, static fn (Holder $hold): bool => $hold->source() !== Holder::ROOT
            || !\in_array($hold->link(), [Holder::REQUIRE, Holder::REQUIRE_DEV, Holder::ROOT_REQUIRE], true)
            || !\in_array($hold->target(), $holders, true)));
        $clone = clone $this;
        $clone->holds = $holds;

        return $clone;
    }

    public function check(): MoveCheck
    {
        return $this->check;
    }

    /** @return list<Holder> distinct, in `held_by[]`'s order; empty unless the check refused the move */
    public function holds(): array
    {
        return $this->holds;
    }

    /**
     * A copy without the root requirements on the moved package that admit $version, Composer's
     * steps on the way to the hold that refuses the move. When no other hold is left, the copy keeps
     * them: Composer then names only the root requirement.
     */
    public function withoutAdmitting(string $version): self
    {
        $holds = array_values(array_filter($this->holds, static fn (Holder $hold): bool => !self::admits($hold, $version)));
        $clone = clone $this;
        $clone->holds = $holds === [] ? $this->holds : $holds;

        return $clone;
    }

    /** The version of the moved package that a resolved move installs, null otherwise. */
    public function installs(): ?string
    {
        return $this->check->installs();
    }

    public function problem(): ?string
    {
        return $this->problem;
    }

    /** The version that the holds name for $holder: its locked release in the first refusal. */
    private function holderVersion(string $holder): ?string
    {
        foreach ($this->holds as $hold) {
            if ($hold->source() === Holder::PACKAGE && $hold->package() === $holder && $hold->version() !== null) {
                return $hold->version();
            }
        }

        return null;
    }

    private static function satisfies(string $constraint, string $version): bool
    {
        $parser = new VersionParser();
        try {
            return $parser->parseConstraints($constraint)->matches(new Constraint('==', $parser->normalize($version)));
        } catch (\UnexpectedValueException $e) {
            return false;
        }
    }

    /** A copy whose refusal holds the releases of $range alone: the versions that the solve could choose. */
    public function withRange(ConstraintInterface $range): self
    {
        $clone = clone $this;
        $clone->range = $range;

        return $clone;
    }

    /** The versions that a refused solve could choose, null when they are the move's constraint. */
    public function range(): ?ConstraintInterface
    {
        return $this->range;
    }

    private static function admits(Holder $hold, string $version): bool
    {
        if ($hold->source() !== Holder::ROOT) {
            return false;
        }
        if ($hold->target() !== null || !\in_array($hold->link(), [Holder::REQUIRE, Holder::REQUIRE_DEV], true)) {
            return false;
        }
        $parser = new VersionParser();
        try {
            return $parser->parseConstraints($hold->constraint())->matches(new Constraint('==', $parser->normalize($version)));
        } catch (\UnexpectedValueException $e) {
            return false;
        }
    }
}
