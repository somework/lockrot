<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Baseline\BaselineComparison;
use Lockrot\Data\Forge\ActivityLookup;
use Lockrot\Json\Schemas;
use Lockrot\Signal\Signal;

/**
 * The closed and open sets docs/compatibility.md names, read off the code in one place for the
 * tests that hold the schemas and the pages to them. The signal levels, the baseline standings and the signal ids are constants, not lists.
 */
final class ClosedSets
{
    /** @return list<string> lowest first */
    public static function levels(): array
    {
        return [Signal::LEVEL_INFO, Signal::LEVEL_WARN, Signal::LEVEL_HIGH];
    }

    /** @return list<string> the order report-2's schema lists them in */
    public static function activityStatuses(): array
    {
        return [ActivityLookup::READ, ActivityLookup::NOT_FOUND, ActivityLookup::NOT_READ];
    }

    /** @return list<string> */
    public static function standings(): array
    {
        return [BaselineComparison::KNOWN, BaselineComparison::NEW_FINDING, BaselineComparison::WORSENED];
    }

    /**
     * The closed sets of report-2 by the words docs/compatibility.md lists them with, each with the
     * schema nodes that spell it. A set spread over several single-value nodes (a discriminator)
     * lists them in the order the page names the values. The one registry: the page's list and
     * ClosedSetsTest both read it.
     *
     * @return array<string, list<list<string>>>
     */
    public static function report2(): array
    {
        $definition = static fn (string ...$path): array => array_merge(['definitions'], array_values($path));

        return [
            'Verdicts, worst first' => [$definition('verdict')],
            'Grades, highest first' => [$definition('grade')],
            'Flags, in flag order' => [$definition('flag')],
            'The maintenance flags, in flag order' => [$definition('maintenanceFlag')],
            'The `priority` alias, highest first' => [$definition('finding', 'properties', 'priority')],
            'Score parts: `run.score_model.parts[].id` and a flag\'s `part`' => [$definition('scoreModel', 'properties', 'parts', 'items', 'properties', 'id')],
            'A score term\'s `part`' => [$definition('termMaintenance', 'properties', 'part'), $definition('termSecurity', 'properties', 'part')],
            'The verdicts at score 0, in `run.score_model.zero_verdicts`' => [$definition('scoreModel', 'properties', 'zero_verdicts', 'items', 'properties', 'verdict')],
            'Advisory severities, highest first' => [$definition('severity')],
            'Signal levels, lowest first' => [$definition('level')],
            'A finding\'s `reach`' => [$definition('finding', 'properties', 'reach')],
            '`security.status`' => [$definition('securityVulnerable', 'properties', 'status'), $definition('securityClear', 'properties', 'status'), $definition('securityUnchecked', 'properties', 'status')],
            '`security.move_in`' => [$definition('securityVulnerable', 'properties', 'move_in', 'oneOf', '0')],
            'A finding\'s standing against the baseline' => [$definition('findingBaseline', 'oneOf', '0', 'properties', 'status')],
            'The `kind` of a move\'s fact and of a rerun\'s `remove`' => [$definition('factKind')],
            'A flag\'s `headline.unit`' => [$definition('headline', 'properties', 'unit')],
            'A flag\'s `headline.source`' => [$definition('headline', 'properties', 'source', 'oneOf', '0')],
            'A holder\'s `source`, in `held_by[]`' => [$definition('heldBy', 'properties', 'source')],
            'A holder\'s `link`, in `held_by[]`' => [$definition('heldBy', 'properties', 'link')],
            'A move\'s `composer_moves_because`' => [$definition('move', 'properties', 'composer_moves_because', 'items')],
            'A move check\'s `result`' => [$definition('moveCheck', 'oneOf', '0', 'properties', 'result')],
            '`run.move_check.baseline`' => [$definition('run', 'properties', 'move_check', 'oneOf', '0', 'properties', 'baseline')],
            'A move check\'s `form`' => [$definition('moveCheck', 'oneOf', '0', 'properties', 'form', 'oneOf', '0')],
            'A PHP check\'s `raise_size`' => [$definition('phpCheck', 'properties', 'raise_size', 'oneOf', '0')],
            '`allowlist.by`' => [$definition('allowlist', 'oneOf', '0', 'properties', 'by')],
            '`allowlist.reason_by`' => [$definition('allowlist', 'oneOf', '0', 'properties', 'reason_by')],
            '`metadata.status`' => [$definition('metadata', 'properties', 'status')],
            'S1\'s `marked_by`' => [$definition('s1', 'properties', 'marked_by')],
            'The `reasons` of an `abandoned` flag\'s `degree`' => [$definition('degreeAbandoned', 'properties', 'reasons', 'items')],
            '`run.fail_on_source`' => [$definition('run', 'properties', 'fail_on_source')],
            '`run.target_php_source`' => [$definition('run', 'properties', 'target_php_source')],
            'A finding\'s `gate.basis.limited_by`' => [$definition('findingGate', 'properties', 'basis', 'oneOf', '0', 'properties', 'limited_by')],
            'A finding\'s `activity.status`' => [$definition('activity', 'properties', 'status')],
            'The `level` of an `age` reading' => [$definition('ageLevel', 'oneOf', '0')],
            'A finding\'s `named_in`' => [$definition('composerJsonSection')],
            'A feed\'s `answer`, in `security.feeds[]`' => [$definition('advisoryFeed', 'properties', 'answer')],
            'An advisory repository\'s `outcome`, in `run.advisory_lookup.repositories[]`' => [$definition('advisoryRepository', 'properties', 'outcome')],
            '`run.advisory_lookup.scope`' => [$definition('advisoryLookup', 'properties', 'scope')],
            '`run.advisory_lookup.scope_source`' => [$definition('advisoryLookup', 'properties', 'scope_source')],
            'The `scope` of an `unchecked_accepted[]` entry\'s `accepted_by`' => [$definition('uncheckedAccepted', 'properties', 'accepted_by', 'properties', 'scope')],
            'The `link` of a PHP window link, in `run.php_window`' => [$definition('phpWindowReq', 'properties', 'link')],
            'The `target` of a PHP window link, in `run.php_window`' => [$definition('phpWindowReq', 'properties', 'target')],
            '`run.php_window.host_requires`' => [$definition('run', 'properties', 'php_window', 'oneOf', '0', 'properties', 'host_requires', 'items')],
            'S11\'s `newest_php_source`' => [$definition('s11', 'properties', 'newest_php_source')],
            'S11\'s `admits_newest.blocking`' => [$definition('s11', 'properties', 'admits_newest', 'oneOf', '0', 'properties', 'blocking', 'oneOf', '0')],
        ];
    }

    /**
     * The values of each set of {@see report2()}, read off the shipped report-2 schema.
     *
     * @return array<string, list<string>>
     */
    public static function report2Values(): array
    {
        $schema = JsonPath::decodeFile(Schemas::path(Schemas::REPORT, 2));
        $values = [];
        foreach (self::report2() as $set => $paths) {
            $values[$set] = [];
            foreach ($paths as $path) {
                foreach (JsonPath::arrayAt($schema, array_merge($path, ['enum'])) as $value) {
                    $values[$set][] = \is_string($value) ? $value : (string) json_encode($value);
                }
            }
        }

        return $values;
    }

    /**
     * Every `S<n>` constant on Signal, in declaration order. A constant that does not hold its own
     * name (`S11 = 'S12'`) is refused rather than returned, since the name is what the code reads.
     *
     * @return list<string>
     */
    public static function signalIds(): array
    {
        $ids = [];
        foreach ((new \ReflectionClass(Signal::class))->getConstants() as $name => $value) {
            if (preg_match('/^S\d+$/', $name) !== 1) {
                continue;
            }
            if ($value !== $name) {
                throw new \RuntimeException('Signal::'.$name.' holds '.var_export($value, true).', not its own id');
            }
            $ids[] = $name;
        }

        return $ids;
    }
}
