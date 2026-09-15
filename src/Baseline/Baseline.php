<?php

declare(strict_types=1);

namespace Lockrot\Baseline;

use Lockrot\Analyzer\Report;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;
use Lockrot\Verdict\Finding;
use Lockrot\Version;

/**
 * The findings a project has already accepted, as read from — or about to be written to —
 * `lockrot-baseline.json` (SPEC F7).
 *
 * Only flagged verdicts are recorded: `ok`, `finished` and `unknown` are not findings, so accepting
 * them would mean nothing. Entries are keyed and sorted by package name, which keeps the file's
 * diff stable across runs and makes a regenerated baseline reviewable.
 */
final class Baseline
{
    /** Bumped only when the file layout changes in a way an older lockrot could misread. */
    public const SCHEMA = 1;

    /** @var array<string, BaselineEntry> package name => entry, sorted by name */
    private array $entries;
    private string $generatedAt;
    private string $toolVersion;

    /** @param array<string, BaselineEntry> $entries already sorted by package name */
    private function __construct(array $entries, string $generatedAt, string $toolVersion)
    {
        $this->entries = $entries;
        $this->generatedAt = $generatedAt;
        $this->toolVersion = $toolVersion;
    }

    /**
     * @param list<BaselineEntry> $entries in any order; sorted by package name here
     * @param string              $generatedAt ISO-8601 timestamp of the run that produced this baseline
     */
    public static function of(array $entries, string $generatedAt, string $toolVersion = Version::STRING): self
    {
        $byName = [];
        foreach ($entries as $entry) {
            $byName[$entry->package()] = $entry;
        }
        ksort($byName, \SORT_STRING);

        return new self($byName, $generatedAt, $toolVersion);
    }

    /**
     * The baseline a run would write for $report, carrying `first_seen` over from $previous for
     * every package it already knew — the version and the verdict are refreshed, the date a finding
     * first appeared is not.
     */
    public static function fromReport(Report $report, ?self $previous = null): self
    {
        $today = $report->generatedAt()->format('Y-m-d');
        $entries = [];
        foreach ($report->flagged() as $finding) {
            $entries[] = new BaselineEntry(
                $finding->package(),
                $finding->version(),
                $finding->verdict(),
                self::firstSeen($previous, $finding, $today)
            );
        }

        return self::of($entries, $report->generatedAt()->format(\DATE_ATOM));
    }

    private static function firstSeen(?self $previous, Finding $finding, string $today): string
    {
        $existing = $previous === null ? null : $previous->entryFor($finding->package());

        return $existing === null ? $today : $existing->firstSeen();
    }

    /**
     * @param array<string, mixed> $data a decoded baseline document
     *
     * @throws ConfigException when the document does not match resources/lockrot-baseline.schema.json
     */
    public static function fromArray(array $data): self
    {
        BaselineSchema::validate($data);

        // Guaranteed by the schema above (findings is a required object whose every value carries
        // three required strings); re-checked here only to narrow `mixed` for PHPStan, which cannot
        // see through the validator.
        $findings = $data['findings'] ?? [];
        if (!\is_array($findings)) {
            throw new ConfigException('baseline file is invalid: findings must be an object');
        }

        $entries = [];
        foreach ($findings as $package => $entry) {
            if (!\is_string($package) || !\is_array($entry)) {
                throw new ConfigException('baseline file is invalid: findings must map package names to objects');
            }
            $entries[] = new BaselineEntry(
                $package,
                self::text($entry, 'version'),
                self::text($entry, 'verdict'),
                self::text($entry, 'first_seen')
            );
        }

        return self::of($entries, self::optionalText($data, 'generated_at'), self::toolVersionOf($data));
    }

    /** @param array<mixed, mixed> $entry */
    private static function text(array $entry, string $key): string
    {
        $value = $entry[$key] ?? null;
        if (!\is_string($value)) {
            throw new ConfigException('baseline file is invalid: '.$key.' must be a string');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function optionalText(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $data */
    private static function toolVersionOf(array $data): string
    {
        $lockrot = $data['lockrot'] ?? null;

        return \is_array($lockrot) ? self::optionalText(JsonReader::stringKeyed($lockrot), 'version') : '';
    }

    public function entryFor(string $package): ?BaselineEntry
    {
        return $this->entries[$package] ?? null;
    }

    /** @return list<string> the baselined package names, sorted */
    public function packages(): array
    {
        return array_keys($this->entries);
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $findings = [];
        foreach ($this->entries as $package => $entry) {
            $findings[$package] = $entry->toArray();
        }

        return [
            'lockrot' => ['version' => $this->toolVersion, 'schema' => self::SCHEMA],
            'generated_at' => $this->generatedAt,
            'findings' => $findings,
        ];
    }
}
