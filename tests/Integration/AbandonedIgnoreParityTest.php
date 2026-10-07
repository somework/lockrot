<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Advisory\AuditConfig;
use Composer\Advisory\Auditor;
use Composer\Config;
use Composer\Package\CompletePackage;
use Composer\Package\PackageInterface;
use Composer\Policy\PolicyConfig;
use Lockrot\Data\Abandoned\AbandonedIgnore;
use PHPUnit\Framework\TestCase;

/**
 * lockrot's abandoned ignore list matches a name exactly when Composer's own
 * `Auditor::filterAbandonedPackages` drops the package. Keyed by capability: Composer 2.2 to 2.8
 * have no list, 2.9 reads `AuditConfig`, 2.10 and later the policy API. CI runs Composer 2.2 and
 * the newest release, so the 2.9 leg runs only by hand on Composer 2.9.
 */
final class AbandonedIgnoreParityTest extends TestCase
{
    private const NAMES = ['doctrine/cache', 'doctrine/annotations', 'symfony/debug', 'acme/Mixed-Case', 'acme/other', 'other/doctrine'];

    /** @return iterable<string, array<string, mixed>> the `config` sections that Composer 2.9 reads */
    private static function auditConfigs(): iterable
    {
        yield 'no list' => [];
        yield 'a list' => ['audit' => ['ignore-abandoned' => ['doctrine/cache', 'symfony/*']]];
        yield 'a map with reasons' => ['audit' => ['ignore-abandoned' => ['doctrine/*' => 'PSR-6 migration planned', 'acme/mixed-case' => null]]];
    }

    /** @return iterable<string, array<string, mixed>> */
    private static function policyConfigs(): iterable
    {
        yield from self::auditConfigs();
        yield 'the legacy list with apply' => ['audit' => ['ignore-abandoned' => ['doctrine/cache' => ['apply' => 'block'], 'symfony/debug' => ['apply' => 'audit']]]];
        yield 'every policy shape' => ['policy' => ['abandoned' => ['ignore' => [
            'doctrine/cache' => null,
            'doctrine/annotations' => ['constraint' => '^1.0', 'reason' => 'a constraint audit drops'],
            'symfony/*' => [['reason' => 'a'], ['reason' => 'b', 'on-audit' => false]],
            'acme/other' => ['on-audit' => false],
        ]]]];
        yield 'the policy beside the legacy list' => ['policy' => ['abandoned' => ['ignore' => ['acme/other' => null]]], 'audit' => ['ignore-abandoned' => ['doctrine/cache']]];
        yield 'the list turned off' => ['policy' => ['abandoned' => false], 'audit' => ['ignore-abandoned' => ['doctrine/cache']]];
        yield 'the policy turned off' => ['policy' => false, 'audit' => ['ignore-abandoned' => ['doctrine/cache']]];
    }

    public function testThePolicyApiLegMatchesComposersFilter(): void
    {
        if (!class_exists(PolicyConfig::class)) {
            self::markTestSkipped('Composer below 2.10 has no policy API');
        }
        foreach (self::policyConfigs() as $label => $section) {
            $config = self::config($section);
            $flat = PolicyConfig::fromConfig($config)->abandoned->getFlatIgnoreForOperation('audit');

            self::assertSame(self::kept($flat), self::lockrotKept(AbandonedIgnore::fromConfig($config)), $label);
        }
    }

    /** Composer 2.9 only. No CI leg has such a Composer. */
    public function testTheAuditConfigLegMatchesComposersFilter(): void
    {
        if (class_exists(PolicyConfig::class)) {
            self::markTestSkipped('Composer 2.10 or later: this leg runs only on Composer 2.9, which no CI leg has');
        }
        if (!class_exists(AuditConfig::class) || !method_exists(AuditConfig::class, 'fromConfig')) {
            self::markTestSkipped('Composer 2.2 to 2.8 have no abandoned ignore list');
        }
        foreach (self::auditConfigs() as $label => $section) {
            $config = self::config($section);
            $auditConfig = (new \ReflectionMethod(AuditConfig::class, 'fromConfig'))->invoke(null, $config);
            $properties = \is_object($auditConfig) ? get_object_vars($auditConfig) : [];
            $list = $properties['ignoreAbandonedForAudit'] ?? $properties['ignoreAbandonedPackages'] ?? [];

            self::assertSame(self::kept(\is_array($list) ? $list : []), self::lockrotKept(AbandonedIgnore::fromConfig($config)), $label);
        }
    }

    /** @param array<string, mixed> $section */
    private static function config(array $section): Config
    {
        $config = new Config(false);
        $config->merge(['config' => $section]);

        return $config;
    }

    /** @return list<PackageInterface> every name, marked abandoned */
    private static function packages(): array
    {
        return array_map(static function (string $name): PackageInterface {
            $package = new CompletePackage($name, '1.0.0.0', '1.0.0');
            $package->setAbandoned(true);

            return $package;
        }, self::NAMES);
    }

    /**
     * @param array<mixed> $ignoreAbandoned
     *
     * @return list<string> the names Composer still reports as abandoned
     */
    private static function kept(array $ignoreAbandoned): array
    {
        // Through reflection: the parameter type of Composer 2.9 and 2.10 differ, and phpstan reads 2.10.
        $filter = new \ReflectionMethod(Auditor::class, 'filterAbandonedPackages');
        $kept = $filter->invoke(new Auditor(), self::packages(), $ignoreAbandoned);
        self::assertIsArray($kept);
        $kept = array_map(static fn ($package): string => $package instanceof PackageInterface ? $package->getName() : '', $kept);

        return array_values($kept);
    }

    /** @return list<string> */
    private static function lockrotKept(AbandonedIgnore $ignore): array
    {
        return array_values(array_filter(array_map('strtolower', self::NAMES), static fn (string $name): bool => $ignore->match($name) === null));
    }
}
