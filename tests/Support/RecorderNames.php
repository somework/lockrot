<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Lock\LockFile;

/** The locked names that `bin/record-fixtures` asks for. */
final class RecorderNames
{
    /**
     * @param bool $into a recording with `--into`, which the corpus runs replay and which reads no packages-dev name
     *
     * @return list<string>
     */
    public static function advisory(LockFile $lock, bool $into): array
    {
        return array_map(static fn ($package): string => $package->name(), $lock->packages(!$into));
    }

    /**
     * Composer lists the names of a repository in `available-packages` and, with `*` wildcards, in
     * `available-package-patterns`. A repository with a `metadata-url` and no non-empty list is asked
     * for every name, as {@see FixtureRepositoryServer::withMirror()} serves it on replay. Names match
     * without regard to case.
     *
     * @param array<mixed, mixed> $packages the repository's packages.json
     * @param list<string>        $locked
     *
     * @return list<string> the locked names that the repository lists, in lock order
     */
    public static function repository(array $packages, array $locked): array
    {
        if (empty($packages['available-packages']) && empty($packages['available-package-patterns']) && \is_string($packages['metadata-url'] ?? null)) {
            return $locked;
        }
        $exact = array_map('strtolower', self::strings($packages['available-packages'] ?? null));
        $patterns = array_map(static fn (string $pattern): string => '{^'.str_replace('\\*', '.*', preg_quote($pattern, '{')).'$}i', self::strings($packages['available-package-patterns'] ?? null));

        return array_values(array_filter($locked, static function (string $name) use ($exact, $patterns): bool {
            if (\in_array(strtolower($name), $exact, true)) {
                return true;
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $name) === 1) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * True when the repository has no `metadata-url` and lists a locked name or serves packages
     * through a Composer 1 list, which the recorder does not record.
     *
     * @param array<mixed, mixed> $packages the repository's packages.json
     * @param list<string>        $names    {@see self::repository()}
     */
    public static function unrecordable(array $packages, array $names): bool
    {
        if (\is_string($packages['metadata-url'] ?? null)) {
            return false;
        }

        return $names !== [] || !empty($packages['packages']) || !empty($packages['includes']) || !empty($packages['provider-includes']) || !empty($packages['providers-url']);
    }

    /**
     * @param mixed $list
     *
     * @return list<string>
     */
    private static function strings($list): array
    {
        return \is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
