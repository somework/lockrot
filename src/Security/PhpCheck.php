<?php

declare(strict_types=1);

namespace Lockrot\Security;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Lockrot\Signal\PhpFloor;

/**
 * What a release needs from PHP, and whether the project's `require.php` and the target admit it
 * (`php_check`). A null answer means lockrot could not compare, never "no".
 *
 * @internal
 */
final class PhpCheck
{
    public const MAJOR = 'major';
    public const MINOR = 'minor';
    public const PATCH = 'patch';

    private ?string $requires;
    private ?bool $projectAllows;
    private ?bool $targetRuns;
    private ?string $raiseTo;
    /** @var self::MAJOR|self::MINOR|self::PATCH|null */
    private ?string $raiseSize;

    /** @param self::MAJOR|self::MINOR|self::PATCH|null $raiseSize */
    private function __construct(?string $requires, ?bool $projectAllows, ?bool $targetRuns, ?string $raiseTo, ?string $raiseSize)
    {
        $this->requires = $requires;
        $this->projectAllows = $projectAllows;
        $this->targetRuns = $targetRuns;
        $this->raiseTo = $raiseTo;
        $this->raiseSize = $raiseSize;
    }

    /**
     * `raise_to` and `raise_size` are set together, only when the project's point does not satisfy
     * the release's php and a stable version above that point does: then there is a floor to write.
     */
    public static function of(?string $php, PhpFloor $floor): self
    {
        $allows = $floor->allowsProject($php);
        $project = $floor->lowestAsString();
        $release = $allows === false && $php !== null && $project !== null ? self::pointAbove($php, $project) : null;
        $raiseTo = null;
        $raiseSize = null;
        if ($release !== null) {
            $raiseTo = '>='.preg_replace('/\.0$/', '', $release);
            $raiseSize = self::size($project, $release);
        }

        return new self($php, $allows, $floor->admitsTarget($php), $raiseTo, $raiseSize);
    }

    /**
     * The lowest stable `X.Y.Z` above the project's point that $php admits, read at the start of
     * each of its ranges and at the patch after it: a start such as `>=7.4.0-p1` or `>7.4.0` admits
     * no `X.Y.Z` of its own. A disjunction such as `7.1.* || >=8.1` starts below the project's
     * point, so its first range above the point counts.
     */
    private static function pointAbove(string $php, string $project): ?string
    {
        try {
            $constraint = (new VersionParser())->parseConstraints($php);
        } catch (\UnexpectedValueException $e) {
            return null;
        }
        foreach (Intervals::get($constraint)['numeric'] as $interval) {
            $point = PhpFloor::pointOf($interval->getStart()->getVersion());
            if ($point === null) {
                continue;
            }
            foreach ([$point, PhpFloor::pointOf('>'.$point)] as $candidate) {
                if ($candidate !== null && version_compare($candidate, $project, '>') && $constraint->matches(new Constraint('==', $candidate.'.0'))) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** @return self::MAJOR|self::MINOR|self::PATCH */
    private static function size(string $from, string $to): string
    {
        $a = explode('.', $from);
        $b = explode('.', $to);
        if ($a[0] !== $b[0]) {
            return self::MAJOR;
        }

        return $a[1] !== $b[1] ? self::MINOR : self::PATCH;
    }

    public function requires(): ?string
    {
        return $this->requires;
    }

    public function projectAllows(): ?bool
    {
        return $this->projectAllows;
    }

    public function targetRuns(): ?bool
    {
        return $this->targetRuns;
    }

    public function raiseTo(): ?string
    {
        return $this->raiseTo;
    }

    /** @return self::MAJOR|self::MINOR|self::PATCH|null */
    public function raiseSize(): ?string
    {
        return $this->raiseSize;
    }

    /** @return array{requires: ?string, project_allows: ?bool, target_runs: ?bool, raise_to: ?string, raise_size: ?string} */
    public function toArray(): array
    {
        return [
            'requires' => $this->requires,
            'project_allows' => $this->projectAllows,
            'target_runs' => $this->targetRuns,
            'raise_to' => $this->raiseTo,
            'raise_size' => $this->raiseSize,
        ];
    }
}
