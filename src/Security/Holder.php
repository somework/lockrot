<?php

declare(strict_types=1);

namespace Lockrot\Security;

use Composer\Semver\VersionParser;
use Lockrot\Verdict\GradedPackage;

/**
 * One link that excludes a candidate release: a `held_by[]` entry. A root link names no package
 * there, because the holder is composer.json. `target` names the package that the link points to
 * when it is not the moved package: a requirement or a conflict of the target release, or of the
 * root, that Composer's solver names ({@see \Lockrot\Solver\ProblemHolds}). `holder` is the
 * holder's own finding, which the report fills once every finding is graded ({@see filled()}).
 *
 * @internal
 */
final class Holder implements \JsonSerializable
{
    public const ROOT = 'root';
    public const PACKAGE = 'package';

    public const REQUIRE = 'require';
    public const REQUIRE_DEV = 'require-dev';
    public const CONFLICT = 'conflict';
    public const REPLACE = 'replace';
    public const ROOT_REQUIRE = 'root-require';
    public const PLATFORM = 'platform';

    /** `held_by[]`'s order: the root first, then by package, then by link in this order, then by target. */
    private const LINK_ORDER = [self::REQUIRE => 0, self::REQUIRE_DEV => 1, self::CONFLICT => 2, self::REPLACE => 3, self::ROOT_REQUIRE => 4, self::PLATFORM => 5];

    /** @var self::ROOT|self::PACKAGE */
    private string $source;
    private ?string $package;
    private ?string $version;
    /** @var self::REQUIRE|self::REQUIRE_DEV|self::CONFLICT|self::REPLACE|self::ROOT_REQUIRE|self::PLATFORM */
    private string $link;
    private string $constraint;
    private ?string $target;
    private ?GradedPackage $finding = null;
    private bool $locked = false;

    /**
     * @param self::ROOT|self::PACKAGE                                                       $source
     * @param ?string                                                                        $package the root's `name` (null when composer.json has none), the locked package, or the moved package at its target release
     * @param ?string                                                                        $version the package's version, null for the root
     * @param self::REQUIRE|self::REQUIRE_DEV|self::CONFLICT|self::REPLACE|self::ROOT_REQUIRE|self::PLATFORM $link    a locked package's link is `require`, `conflict` or `replace`, from packages-dev too
     * @param ?string                                                                        $target  the package the link points to, null when it is the moved package
     */
    public function __construct(string $source, ?string $package, ?string $version, string $link, string $constraint, ?string $target = null)
    {
        $this->source = $source;
        $this->package = $package;
        $this->version = $version;
        $this->link = $link;
        $this->constraint = $constraint;
        $this->target = $target;
    }

    /** @return self::ROOT|self::PACKAGE */
    public function source(): string
    {
        return $this->source;
    }

    public function package(): ?string
    {
        return $this->package;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    /** @return self::REQUIRE|self::REQUIRE_DEV|self::CONFLICT|self::REPLACE|self::ROOT_REQUIRE|self::PLATFORM */
    public function link(): string
    {
        return $this->link;
    }

    public function constraint(): string
    {
        return $this->constraint;
    }

    public function target(): ?string
    {
        return $this->target;
    }

    public function finding(): ?GradedPackage
    {
        return $this->finding;
    }

    /** A copy that names the holder's locked release: the release that holds the move in the lock. */
    public function asLocked(): self
    {
        $clone = clone $this;
        $clone->locked = true;

        return $clone;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    private function withFinding(?GradedPackage $finding): self
    {
        $clone = clone $this;
        $clone->finding = $finding;

        return $clone;
    }

    /**
     * A hold at another version than the locked one takes no finding: the finding grades the locked version.
     *
     * @param list<self>                   $holders
     * @param array<string, GradedPackage> $findings by package name
     *
     * @return list<self>
     */
    public static function filled(array $holders, array $findings): array
    {
        return array_map(static function (self $holder) use ($findings): self {
            $finding = $holder->package === null ? null : ($findings[$holder->package] ?? null);

            return $holder->withFinding($finding !== null && $finding->version() === $holder->version ? $finding : null);
        }, $holders);
    }

    /**
     * Each entry once, in `held_by[]`'s order. Of two entries that differ only in the version, the
     * holder's locked release wins. Else a stable version wins over a dev version, which
     * Composer installs only without the tag (T4). Between two stable versions the lower wins:
     * Composer's problem text names one rule's versions lowest first. Any other pair keeps the first.
     *
     * @param list<self> $holders
     *
     * @return list<self>
     */
    public static function distinct(array $holders): array
    {
        $kept = [];
        foreach ($holders as $holder) {
            $key = implode("\0", $holder->order());
            if (!isset($kept[$key]) || self::wins($holder, $kept[$key])) {
                $kept[$key] = $holder;
            }
        }
        usort($kept, static fn (self $a, self $b): int => $a->order() <=> $b->order());

        return $kept;
    }

    private static function wins(self $holder, self $kept): bool
    {
        if ($holder->locked !== $kept->locked) {
            return $holder->locked;
        }
        $stability = $holder->stability();
        if ($stability !== $kept->stability()) {
            return $kept->stability() === 'dev' && $stability === 'stable';
        }
        $parser = new VersionParser();

        return $stability === 'stable' && version_compare($parser->normalize((string) $holder->version), $parser->normalize((string) $kept->version), '<');
    }

    private function stability(): ?string
    {
        return $this->version === null ? null : VersionParser::parseStability($this->version);
    }

    /**
     * The root sorts first: its package is the empty string, and a package name is never empty.
     *
     * @return array{?string, int, ?string, string}
     */
    private function order(): array
    {
        return [$this->source === self::ROOT ? '' : $this->package, self::LINK_ORDER[$this->link], $this->target, $this->constraint];
    }

    /** @return array{source: string, package: ?string, version: ?string, link: string, target: ?string, constraint: string, holder: ?GradedPackage} */
    public function jsonSerialize(): array
    {
        $root = $this->source === self::ROOT;

        return [
            'source' => $this->source,
            'package' => $root ? null : $this->package,
            'version' => $root ? null : $this->version,
            'link' => $this->link,
            'target' => $this->target,
            'constraint' => $this->constraint,
            'holder' => $root ? null : $this->finding,
        ];
    }
}
