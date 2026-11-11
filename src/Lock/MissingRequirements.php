<?php

declare(strict_types=1);

namespace Lockrot\Lock;

use Composer\Json\JsonFile;
use Composer\Package\Link;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Repository\InstalledRepository;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RootPackageRepository;

/**
 * The root requirements that a lock does not hold, read as Composer's
 * {@see \Composer\Package\Locker::getMissingRequirementInfo()} reads them: a `require` link is
 * looked up in the non-dev packages, a `require-dev` link in all of them, and the root package
 * counts too. A name is present when a package or the root has it as its name, in `replace` or in
 * `provide`. A platform link and a `self.version` link are never missing.
 *
 * @internal
 */
final class MissingRequirements
{
    /**
     * @param string $manifest the contents of composer.json
     *
     * @return list<string> sorted and without duplicates
     *
     * @throws \Throwable when Composer cannot read the manifest
     */
    public static function of(string $manifest, LockFile $lock, bool $includeDev): array
    {
        $parsed = JsonFile::parseJson($manifest, 'composer.json');
        $data = \is_array($parsed) ? $parsed : [];
        $name = isset($data['name']) && \is_string($data['name']) ? $data['name'] : '__root__';
        $loader = new ArrayLoader();
        $root = new RootPackage($name, '1.0.0', '1.0.0');
        $root->setRequires(self::byTarget($loader->parseLinks($name, '1.0.0', Link::TYPE_REQUIRE, self::strings($data['require'] ?? null))));
        $root->setDevRequires(self::byTarget($loader->parseLinks($name, '1.0.0', Link::TYPE_DEV_REQUIRE, self::strings($data['require-dev'] ?? null))));
        $root->setReplaces(self::byTarget($loader->parseLinks($name, '1.0.0', Link::TYPE_REPLACE, self::strings($data['replace'] ?? null))));
        $root->setProvides(self::byTarget($loader->parseLinks($name, '1.0.0', Link::TYPE_PROVIDE, self::strings($data['provide'] ?? null))));
        $all = $lock->lockedRepository();
        $nonDev = new LockArrayRepository();
        foreach ($all->getPackages() as $package) {
            if (!$lock->isDevEntry($package->getName())) {
                $nonDev->addPackage(clone $package);
            }
        }
        $sets = [[$nonDev, $root->getRequires()]];
        if ($includeDev) {
            $sets[] = [$all, $root->getDevRequires()];
        }
        $missing = [];
        foreach ($sets as [$locked, $links]) {
            $installed = new InstalledRepository([$locked, new RootPackageRepository(clone $root)]);
            foreach ($links as $link) {
                $target = $link->getTarget();
                if (PlatformRepository::isPlatformPackage($target) || $link->getPrettyConstraint() === 'self.version') {
                    continue;
                }
                if ($installed->findPackagesWithReplacersAndProviders($target) === []) {
                    $missing[$target] = $target;
                }
            }
        }
        $names = $missing;
        sort($names);

        return $names;
    }

    /**
     * @param mixed $section
     *
     * @return array<string, string> the links with a string constraint, as Composer's loader reads them
     */
    private static function strings($section): array
    {
        $out = [];
        foreach (\is_array($section) ? $section : [] as $target => $constraint) {
            if (\is_string($constraint)) {
                $out[(string) $target] = $constraint;
            }
        }

        return $out;
    }

    /**
     * @param array<Link> $links
     *
     * @return array<string, Link>
     */
    private static function byTarget(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            $out[$link->getTarget()] = $link;
        }

        return $out;
    }
}
