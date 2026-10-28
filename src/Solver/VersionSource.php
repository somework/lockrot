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
    /** No Composer repository lists the name, and a path, VCS or package repository that the source does not ask can serve it. */
    public const UNREACHED = 'unreached';
    /** A repository lists the name after a canonical repository that the source does not load, which can serve the name and hide it. */
    public const SHADOWED = 'shadowed';
    /** The install-time budget ran out before the source asked. */
    public const BUDGET = 'install_time_budget';

    /** @return list<BasePackage> the versions of $name, none when no repository lists it */
    public function versions(string $name): array;

    /**
     * The versions of several names, as {@see versions()} gives them, asked together.
     *
     * @param list<string> $names
     *
     * @return array<string, list<BasePackage>> in the order of $names
     */
    public function versionsOf(array $names): array;

    /**
     * Why the source has no versions of a name that it was asked for: {@see OFFLINE},
     * {@see FAILED}, {@see UNREACHED} or {@see BUDGET}. Or why its versions are no proof:
     * {@see SHADOWED}. Null when it was not asked, when it loaded versions that a repository can
     * not hide, or when a repository answered that it does not list the name.
     */
    public function failure(string $name): ?string;
}
