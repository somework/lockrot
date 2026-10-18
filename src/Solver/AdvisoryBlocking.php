<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Advisory\AuditConfig;
use Composer\Advisory\Auditor;
use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Config;
use Composer\Package\PackageInterface;
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\PolicyConfig;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * What Composer 2.9 and later block in an update: the settings of the `block` operation, read
 * through the API of the Composer that runs lockrot, and applied with Composer's own Auditor, as
 * `SecurityAdvisoryPoolFilter` applies them. phpstan analyses against the newest Composer, so the
 * Composer 2.9 settings are read as the object's properties.
 *
 * @internal
 */
final class AdvisoryBlocking
{
    private bool $blocks;
    /** @var array<int|string, ?string> */
    private array $list;
    /** @var array<string, ?string> */
    private array $severities;
    private bool $abandoned;
    /** @var array<int|string, ?string> */
    private array $abandonedIgnore;

    /**
     * Each list keeps the keys that this Composer's Auditor reads: a list on Composer 2.9.0 and 2.9.1.
     *
     * @param array<int|string, ?string> $list            the ignore list for blocking
     * @param array<string, ?string>     $severities      the severities that blocking ignores
     * @param array<int|string, ?string> $abandonedIgnore the abandoned packages that blocking ignores
     */
    private function __construct(bool $blocks, array $list, array $severities, bool $abandoned, array $abandonedIgnore)
    {
        $this->blocks = $blocks;
        $this->list = $list;
        $this->severities = $severities;
        $this->abandoned = $abandoned;
        $this->abandonedIgnore = $abandonedIgnore;
    }

    public static function none(): self
    {
        return new self(false, [], [], false, []);
    }

    /** Every advisory blocks, and no abandoned package: a test's Composer 2.9 default. */
    public static function everyAdvisory(): self
    {
        return new self(true, [], [], false, []);
    }

    /** A config that Composer rejects blocks every advisory: the update that it runs fails anyway. */
    public static function fromConfig(Config $config): self
    {
        if (!ComposerFeatures::blocksAdvisories()) {
            return self::none();
        }
        if (class_exists(PolicyConfig::class)) {
            try {
                $policy = PolicyConfig::fromConfig($config);
            } catch (\Throwable $e) {
                return self::everyAdvisory();
            }
            if (!$policy->enabled || !$policy->advisories->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_UPDATE)) {
                return self::none();
            }

            return new self(true, $policy->advisories->getIgnoreListForOperation('block'), $policy->advisories->getIgnoreSeverityForOperation('block'), $policy->abandoned->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_UPDATE), $policy->abandoned->getFlatIgnoreForOperation('block'));
        }
        // Composer 2.10 has no AuditConfig::fromConfig().
        $factory = [AuditConfig::class, 'fromConfig'];
        try {
            $audit = \is_callable($factory) ? $factory($config) : null;
        } catch (\Throwable $e) {
            return self::everyAdvisory();
        }
        $settings = \is_object($audit) ? get_object_vars($audit) : [];
        if (($settings['blockInsecure'] ?? true) === false) {
            return self::none();
        }
        // Composer 2.9.2 and later split each list by operation, 2.9.0 and 2.9.1 block with the whole audit list and no severity.
        if (\array_key_exists('ignoreListForBlocking', $settings)) {
            return new self(true, self::listOf($settings['ignoreListForBlocking']), self::severitiesOf($settings['ignoreSeverityForBlocking'] ?? []), ($settings['blockAbandoned'] ?? false) === true, self::listOf($settings['ignoreAbandonedForBlocking'] ?? []));
        }

        return new self(true, self::listOf($settings['ignoreList'] ?? []), [], ($settings['blockAbandoned'] ?? false) === true, self::listOf($settings['ignoreAbandonedPackages'] ?? []));
    }

    public function blocks(): bool
    {
        return $this->blocks;
    }

    /**
     * The ranges that an update blocks, by package name.
     *
     * @param array<string, list<PartialSecurityAdvisory>> $records every record of each name
     *
     * @return array<string, list<ConstraintInterface>>
     */
    public function ranges(array $records): array
    {
        if (!$this->blocks || $records === []) {
            return [];
        }
        $ranges = [];
        // @phpstan-ignore argument.type (the list keeps the shape that this Composer's Auditor reads)
        foreach ((new Auditor())->processAdvisories($records, $this->list, $this->severities)['advisories'] as $name => $advisories) {
            foreach ($advisories as $advisory) {
                $ranges[(string) $name][] = $advisory->affectedVersions;
            }
        }

        return $ranges;
    }

    /**
     * Whether partial records leave out what the ignore list matches: Composer 2.9.2 and later then
     * ask the complete records.
     *
     * @param array<string, list<PartialSecurityAdvisory>> $records
     */
    public function needsComplete(array $records): bool
    {
        // @phpstan-ignore function.alreadyNarrowedType (Composer 2.9.0 and 2.9.1 have no such method), argument.type (the list keeps the shape that this Composer's Auditor reads)
        return method_exists(Auditor::class, 'needsCompleteAdvisoryLoad') && (new Auditor())->needsCompleteAdvisoryLoad($records, $this->list);
    }

    public function blocksAbandoned(PackageInterface $package): bool
    {
        // @phpstan-ignore argument.type (the list keeps the shape that this Composer's Auditor reads)
        return $this->blocks && $this->abandoned && (new Auditor())->filterAbandonedPackages([$package], $this->abandonedIgnore) !== [];
    }

    /**
     * @param mixed $value
     *
     * @return array<string, ?string>
     */
    private static function severitiesOf($value): array
    {
        $severities = [];
        foreach (self::listOf($value) as $severity => $reason) {
            $severities[(string) $severity] = $reason;
        }

        return $severities;
    }

    /**
     * @param mixed $value
     *
     * @return array<int|string, ?string> the keys as written, a value that is no string as null
     */
    private static function listOf($value): array
    {
        return array_map(static fn ($reason): ?string => \is_string($reason) ? $reason : null, \is_array($value) ? $value : []);
    }
}
