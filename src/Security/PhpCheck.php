<?php

declare(strict_types=1);

namespace Lockrot\Security;

use Lockrot\Signal\PhpFloor;

/**
 * What a release needs from PHP, and whether the project's `require.php` and the target admit it
 * (`php_check` of SPEC-0.14 5.3). A null answer means lockrot could not compare, never "no".
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
     * `raise_to` and `raise_size` are set together, only when the release's lowest PHP is above
     * the project's: then there is a floor to write.
     */
    public static function of(?string $php, PhpFloor $floor): self
    {
        $allows = $floor->admitsProject($php);
        $project = $floor->lowestAsString();
        $release = $php === null ? null : PhpFloor::pointOf($php);
        $raiseTo = null;
        $raiseSize = null;
        if ($project !== null && $release !== null && version_compare($release, $project, '>')) {
            $raiseTo = '>='.preg_replace('/\.0$/', '', $release);
            $raiseSize = self::size($project, $release);
        }

        return new self($php, $allows, $floor->admitsTarget($php), $raiseTo, $raiseSize);
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
