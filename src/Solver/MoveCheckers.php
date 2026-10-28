<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Config;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Composer\Util\Platform;
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
        $served = [];
        foreach ($lock->lockedRepository()->getPackages() as $package) {
            foreach (array_unique(array_filter([$package->getSourceUrl(), $package->getDistUrl()])) as $url) {
                $served[RepositoryVersions::urlKey($url)][] = $package->getName();
            }
        }
        $source = new RepositoryVersions($repositories, $notFromComposerRepository, $offline, $deadline, $root->getMinimumStability(), $root->getStabilityFlags(), $served);
        $solver = (new ComposerSolver($root, $lock->lockedRepository(), new PlatformRepository([], $overrides), $source, []))->withLockedOnly($notFromComposerRepository);
        $solver = $php === $configured ? $solver : $solver->withPhpSetByLockrot();
        $solver = self::minimalChanges($config) ? $solver->withMinimalChanges() : $solver;

        $blocking = AdvisoryBlocking::fromConfig($config);
        $lookup = new RepositoryAdvisoryLoader($repositories, $offline, $deadline);

        $keptOut = $advisoryScope === AdvisoryCoverage::SCOPE_COMPOSER_REPOSITORIES ? $notFromComposerRepository : [];

        return new SolverChecker($solver, $notFromComposerRepository, $php, $blocking->blocks(), $deadline, new PoolAdvisories($blocking, static fn (array $names, bool $complete): ?array => $lookup->records($names, $complete), $keptOut), $configured);
    }

    /** Whether the printed `composer update` and `composer require` run with `--minimal-changes`, as UpdateCommand and RequireCommand read it. */
    public static function minimalChanges(Config $config): bool
    {
        return (bool) Platform::getEnv('COMPOSER_MINIMAL_CHANGES') || (bool) $config->get('update-with-minimal-changes');
    }

    /**
     * `config.platform.php` when it lies in the target minor, with or without a patch, else the
     * target minor at {@see NEWEST_PATCH}.
     */
    public static function platformPhp(string $targetMinor, ?string $platform): string
    {
        return $platform !== null && self::isStableIn($platform, $targetMinor) ? $platform : $targetMinor.'.'.self::NEWEST_PATCH;
    }

    /** Whether Composer reads $version as a stable release of $minor: `8` is 8.0.0. */
    private static function isStableIn(string $version, string $minor): bool
    {
        try {
            $parser = new VersionParser();

            return VersionParser::parseStability($version) === 'stable'
                && $parser->parseConstraints('~'.$minor.'.0')->matches(new Constraint('==', $parser->normalize($version)));
        } catch (\UnexpectedValueException $e) {
            return false;
        }
    }
}
