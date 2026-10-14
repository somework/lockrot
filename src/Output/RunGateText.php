<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Analyzer\RunSettings;
use Lockrot\Config\Gate;

/**
 * The `gate:` line, `gate.text` (text grammar 1): rendered from the root `gate` and the run fields
 * only. It holds no package name and no user text, so it needs no escaping beyond each format's
 * own. Prose, not contract: a consumer keys on the fields. The grammar is SPEC §6.2, "RunGateText".
 *
 * @internal
 */
final class RunGateText
{
    /** An unknown source or reason prints its id. tools/report/record records these tables as the gate words. */
    public const FAIL_ON_SOURCE = [RunSettings::SOURCE_ENV => 'LOCKROT_FAIL_ON', RunSettings::SOURCE_CONFIG => 'extra.lockrot.fail-on', RunSettings::SOURCE_DEFAULT => 'default'];
    public const TARGET_SOURCE = [
        RunSettings::SOURCE_OPTION => '--target-php',
        RunSettings::SOURCE_ENV => 'LOCKROT_TARGET_PHP',
        RunSettings::SOURCE_CONFIG => 'extra.lockrot.target-php',
        RunSettings::SOURCE_PLATFORM => 'config.platform.php',
        RunSettings::SOURCE_RUNTIME => 'the running PHP',
    ];
    public const NOT_APPLIED = [
        'offline' => 'not checked (offline)',
        'composer_too_old' => 'not checked (Composer below 2.4)',
        'install_time_budget' => 'not checked (install-time budget spent)',
        'lookup_failed' => 'not checked (a repository failed)',
        'no_feed' => 'not checked (no repository publishes advisories)',
        'not_from_composer_repository' => 'not asked (advisory-lookup: composer-repositories)',
        'unparseable_version' => 'version not readable',
    ];

    public static function render(Gate $gate, RunSettings $run): string
    {
        return implode(' · ', self::parts($gate, $run));
    }

    /**
     * `gate.text` in its segments: the gate's state first.
     *
     * @return non-empty-list<string>
     */
    public static function parts(Gate $gate, RunSettings $run): array
    {
        $active = $run->failOnValue() !== 'none';
        $parts = ['gate: '.self::head($gate, $run, $active)];
        $source = $run->failOnSourceOrDefault();
        $parts[] = '--fail-on='.$run->failOnValue().($source === RunSettings::SOURCE_OPTION ? '' : ' ('.(self::FAIL_ON_SOURCE[$source] ?? $source).')');
        if ($active) {
            $parts[] = $gate->failOnApplied() ? self::counts($gate) : self::plural($gate->reaching(), 'finding reaches it', 'findings reach it');
        }
        if (\in_array(Gate::TRIP_STRICT_NETWORK, $gate->trippedBy(), true)) {
            $parts[] = '--strict-network: a lookup failed';
        }
        foreach ($gate->notAppliedEntries() as $gap) {
            $parts[] = \sprintf('%s not applied to %s: %s', $gap->check(), self::plural($gap->packages(), 'package', 'packages'), self::NOT_APPLIED[$gap->reason()] ?? $gap->reason());
        }
        $parts[] = \sprintf('target php %s (%s)', $run->targetPhp(), self::TARGET_SOURCE[$run->targetPhpSource()] ?? $run->targetPhpSource());

        return $parts;
    }

    private static function head(Gate $gate, RunSettings $run, bool $active): string
    {
        if ($gate->fails()) {
            return 'fails';
        }
        if ($run->mode() === Gate::MODE_GENERATE_BASELINE) {
            return 'not applied (--generate-baseline)';
        }

        return $active ? 'passes' : 'off';
    }

    /** The findings that fail, per value when two or more values are active, then the exempt ones apart. */
    private static function counts(Gate $gate): string
    {
        $failing = $gate->failing();
        $text = $failing === 0 ? 'no finding fails it' : self::plural($failing, 'finding fails it', 'findings fail it');
        $byValue = $gate->failingBy();
        if ($failing > 0 && \count($byValue) > 1) {
            $parts = [];
            foreach ($byValue as $value) {
                if ($value->failing() > 0) {
                    $parts[] = $value->value().' '.$value->failing();
                }
            }
            $text .= ' ('.implode(', ', $parts).')';
        }
        $exempt = $gate->exemptByBaseline();
        if ($exempt > 0) {
            $text .= '; '.($exempt === 1 ? '1 reaches it and is known to the baseline' : $exempt.' reach it and are known to the baseline');
        }

        return $text;
    }

    private static function plural(int $count, string $one, string $many): string
    {
        return $count.' '.($count === 1 ? $one : $many);
    }
}
