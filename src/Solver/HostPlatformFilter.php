<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface;

/**
 * Ignores the requirements on {@see ComposerSolver::IGNORED_PLATFORM}, as Composer's
 * `--ignore-platform-req` does, except the names that `config.platform` sets: those are the
 * project's facts, not the facts of the machine that runs lockrot (ruling R6-2).
 *
 * @internal
 */
final class HostPlatformFilter implements PlatformRequirementFilterInterface
{
    private PlatformRequirementFilterInterface $ignored;
    /** @var array<string, true> */
    private array $configured;

    /** @param list<string> $configured the lower-case names that `config.platform` sets */
    public function __construct(array $configured)
    {
        $this->ignored = PlatformRequirementFilterFactory::fromBoolOrList(ComposerSolver::IGNORED_PLATFORM);
        $this->configured = array_fill_keys($configured, true);
    }

    public function isIgnored(string $req): bool
    {
        return !isset($this->configured[$req]) && $this->ignored->isIgnored($req);
    }

    public function isUpperBoundIgnored(string $req): bool
    {
        return !isset($this->configured[$req]) && $this->ignored->isUpperBoundIgnored($req);
    }
}
