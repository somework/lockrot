<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Config;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\VersionParser;
use Lockrot\Data\Advisory\AdvisoryCoverage;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Deadline;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\PhpFloor;

/**
 * Builds the move check of one project (docs/verdicts.md#the-move-check).
 *
 * @internal
 */
final class MoveCheckers
{
    /**
     * The patch that the solve runs `php` at when `config.platform.php` names none in the target
     * minor: the point at which {@see PhpFloor} tests the target PHP.
     */
    public const NEWEST_PATCH = PhpFloor::NEWEST_PATCH;

    /**
     * @param RootPackageInterface      $root         the project's root package, as Composer loads composer.json
     * @param list<RepositoryInterface> $repositories the project's Composer repositories, in lookup order
     * @param string                    $targetPhp    the target PHP's minor
     * @param string                    $advisoryScope one of {@see AdvisoryCoverage::SCOPES}
     */
    public static function forProject(RootPackageInterface $root, LockFile $lock, ProjectConfig $project, Config $config, array $repositories, string $targetPhp, bool $offline, Deadline $deadline, string $advisoryScope = AdvisoryCoverage::SCOPE_ALL): MoveChecker
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
        $solver = (new ComposerSolver($root, $lock->lockedRepository(), new PlatformRepository([], $overrides), $source, []))->withLockedOnly($notFromComposerRepository);
        $solver = $php === $configured ? $solver : $solver->withPhpSetByLockrot();

        $blocking = AdvisoryBlocking::fromConfig($config);
        $lookup = new RepositoryAdvisoryLoader($repositories, $offline, $deadline);

        $keptOut = $advisoryScope === AdvisoryCoverage::SCOPE_COMPOSER_REPOSITORIES ? $notFromComposerRepository : [];

        return new SolverChecker($solver, $notFromComposerRepository, $php, $blocking->blocks(), $deadline, new PoolAdvisories($blocking, static fn (array $names, bool $complete): ?array => $lookup->records($names, $complete), $keptOut), $configured);
    }

    /**
     * `config.platform.php` when it lies in the target minor, with or without a patch, else the
     * target minor at {@see NEWEST_PATCH}.
     */
    public static function platformPhp(string $targetMinor, ?string $platform): string
    {
        return $platform !== null && self::minorOf($platform) === $targetMinor ? $platform : $targetMinor.'.'.self::NEWEST_PATCH;
    }

    /** The minor that Composer reads from $version (`8` is 8.0.0), null for a version that is not stable, has no minor or does not parse. */
    private static function minorOf(string $version): ?string
    {
        $parser = new VersionParser();
        try {
            $normalized = $parser->normalize($version);
        } catch (\UnexpectedValueException $e) {
            return null;
        }
        $parts = explode('.', $normalized);
        if (VersionParser::parseStability($normalized) !== 'stable' || \count($parts) < 2) {
            return null;
        }

        return $parts[0].'.'.$parts[1];
    }
}
