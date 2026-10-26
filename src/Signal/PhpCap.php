<?php

declare(strict_types=1);

namespace Lockrot\Signal;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;

/**
 * R, the newest known PHP minor that the root php admits, and S11's test of a php requirement
 * against it (docs/verdicts.md#capped). The root php is composer.json's `require.php`, else the
 * lock's `platform.php`.
 *
 * @internal
 */
final class PhpCap
{
    /** The `newest_php_source` values. */
    public const KNOWN = 'known';
    public const REQUIRE_PHP = 'require_php';
    public const PLATFORM_PHP = 'platform_php';

    private VersionParser $parser;
    /** @var list<string> oldest first */
    private array $known;
    private ?string $newest;
    /** @var self::KNOWN|self::REQUIRE_PHP|self::PLATFORM_PHP */
    private string $source;

    /**
     * @param list<string> $known       the known PHP minors on the run clock, oldest first
     * @param ?string      $requirePhp  composer.json's `require.php`, null when absent
     * @param ?string      $platformPhp the lock's `platform.php`, read only without a `require.php`
     */
    public function __construct(array $known, ?string $requirePhp = null, ?string $platformPhp = null)
    {
        $this->parser = new VersionParser();
        $this->known = $known;
        $own = self::states($requirePhp);
        $root = $own ? $requirePhp : $platformPhp;
        $last = $known === [] ? null : $known[\count($known) - 1];
        $this->newest = self::states($root) ? $this->newestOf($root) : $last;
        $this->source = $this->newest === null || $this->newest === $last ? self::KNOWN : ($own ? self::REQUIRE_PHP : self::PLATFORM_PHP);
    }

    /** A cap with no R: S11 never fires on it. */
    public static function none(): self
    {
        return new self([]);
    }

    /** R, null when the root php admits no known minor or does not parse. */
    public function newest(): ?string
    {
        return $this->newest;
    }

    /** @return self::KNOWN|self::REQUIRE_PHP|self::PLATFORM_PHP */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * S11's test: the requirement's upper bound is finite, it admits nothing at or above R's
     * {@see PhpFloor::NEWEST_PATCH}, and it admits a known minor. Null for a release that states no php, which is no answer.
     */
    public function stopsBelow(?string $constraint): ?bool
    {
        if (!self::states($constraint)) {
            return null;
        }

        return $this->stopsAt($constraint) !== null;
    }

    /** The newest known minor that a capped requirement admits, null where {@see stopsBelow()} is not true. */
    public function stopsAt(string $constraint): ?string
    {
        $parsed = $this->parse($constraint);
        if ($this->newest === null || $parsed === null || Intervals::haveIntersections($parsed, new Constraint('>=', $this->point($this->newest)))) {
            return null;
        }
        $stops = null;
        foreach ($this->known as $minor) {
            $stops = $this->admitsMinor($parsed, $minor) ? $minor : $stops;
        }

        return $stops;
    }

    /** Whether the requirement admits R, as {@see PhpFloor::admitsTarget()} tests a minor. Null without an answer. */
    public function admitsNewest(?string $constraint): ?bool
    {
        $parsed = $constraint === null ? null : $this->parse($constraint);

        return $parsed === null || $this->newest === null ? null : $this->admitsMinor($parsed, $this->newest);
    }

    private function newestOf(string $root): ?string
    {
        $parsed = $this->parse($root);
        if ($parsed === null) {
            return null;
        }
        foreach (array_reverse($this->known) as $minor) {
            if ($this->admitsMinor($parsed, $minor)) {
                return $minor;
            }
        }

        return null;
    }

    private function admitsMinor(ConstraintInterface $constraint, string $minor): bool
    {
        return $constraint->matches(new Constraint('==', $this->point($minor)));
    }

    private function point(string $minor): string
    {
        return $this->parser->normalize($minor.'.'.PhpFloor::NEWEST_PATCH);
    }

    /** @phpstan-assert-if-true string $php */
    private static function states(?string $php): bool
    {
        return $php !== null && trim($php) !== '';
    }

    /** Null for a requirement that does not parse: Composer's parser refuses a blank one too. */
    private function parse(string $constraint): ?ConstraintInterface
    {
        try {
            return $this->parser->parseConstraints($constraint);
        } catch (\UnexpectedValueException $e) {
            return null;
        }
    }
}
