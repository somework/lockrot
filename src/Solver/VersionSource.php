<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Package\BasePackage;

/**
 * The versions that the move check offers the solver, loaded when Composer's pool builder asks for
 * a name. Each name is loaded once per run.
 *
 * @internal
 */
interface VersionSource
{
    /** The source could not ask: `--offline`, and Composer's cache has no answer. */
    public const OFFLINE = 'offline';
    /** The repository did not answer. */
    public const FAILED = 'failed';
    /** The install-time budget ran out before the source asked. */
    public const BUDGET = 'install_time_budget';

    /** @return list<BasePackage> the versions of $name, none when no repository lists it */
    public function versions(string $name): array;

    /**
     * Why the source has no versions of a name that it was asked for: {@see OFFLINE},
     * {@see FAILED} or {@see BUDGET}. Null when it was not asked, when it loaded versions, or when
     * a repository answered that it does not list the name.
     */
    public function failure(string $name): ?string;
}
