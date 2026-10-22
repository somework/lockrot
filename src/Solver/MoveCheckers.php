<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Config;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;

/**
 * Builds the move check of one project (docs/verdicts.md#the-move-check).
 *
 * @internal
 */
final class MoveCheckers
{
    /**
     * The patch that the solve runs `php` at when `config.platform.php` names none in the target
     * minor: the newest one, so a requirement that admits a patch of the target admits it, as
     * lockrot's target PHP does ({@see \Lockrot\Signal\PhpFloor}).
     */
    public const NEWEST_PATCH = '99';

    /**
     * @param RootPackageInterface      $root         the project's root package, as Composer loads composer.json
     * @param list<RepositoryInterface> $repositories the project's Composer repositories, in lookup order
     * @param string                    $targetPhp    the target PHP's minor
     */
    public static function forProject(RootPackageInterface $root, LockFile $lock, ProjectConfig $project, Config $config, array $repositories, string $targetPhp, bool $offline, Deadline $deadline): MoveChecker
    {
        $overrides = [];
        $configured = $config->get('platform');
        foreach (\is_array($configured) ? $configured : [] as $name => $version) {
            if (\is_string($name) && (\is_string($version) || $version === false)) {
                $overrides[$name] = $version;
            }
        }
        $configured = \is_string($overrides['php'] ?? null) ? $overrides['php'] : null;
        $php = self::platformPhp($targetPhp, $configured);
        $overrides['php'] = $php;
        $notFromComposerRepository = [];
        foreach ($lock->packages(true) as $package) {
            if (!$package->withRepositories($project->repositories())->isFromComposerRepository()) {
                $notFromComposerRepository[] = $package->name();
            }
        }
        $source = new RepositoryVersions($repositories, $notFromComposerRepository, $offline, $deadline, $root->getMinimumStability(), $root->getStabilityFlags());
        $solver = new ComposerSolver($root, $lock->lockedRepository(), new PlatformRepository([], $overrides), $source, []);

        $blocking = AdvisoryBlocking::fromConfig($config);
        $lookup = new RepositoryAdvisoryLoader($repositories, $offline, $deadline);

        return new SolverChecker($solver, $notFromComposerRepository, $php, $blocking->blocks(), $deadline, new PoolAdvisories($blocking, static fn (array $names, bool $complete): array => $lookup->records($names, $complete)), $configured);
    }

    /**
     * `config.platform.php` when it lies in the target minor, with or without a patch, else the
     * target minor at {@see NEWEST_PATCH}.
     */
    public static function platformPhp(string $targetMinor, ?string $platform): string
    {
        // Composer reads a version with no patch as patch 0 (`8.4` is 8.4.0).
        if ($platform !== null && preg_match('/^'.preg_quote($targetMinor, '/').'(\.\d+)*$/', $platform) === 1) {
            return $platform;
        }

        return $targetMinor.'.'.self::NEWEST_PATCH;
    }
}
