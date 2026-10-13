<?php

declare(strict_types=1);

namespace Lockrot\Baseline;

use Lockrot\Analyzer\Report;
use Lockrot\Analyzer\RunSettings;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;
use Lockrot\Score\CountedAdvisory;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\ScoreModel;
use Lockrot\Version;

/**
 * The facts a project knows, as `lockrot-baseline.json` records them (baseline-2): docs/baseline.md.
 *
 * @internal
 */
final class Baseline
{
    /** Increase it only when an older lockrot could misread the new file layout. */
    public const SCHEMA = 2;

    /** @var array<string, BaselineEntry> package name => entry, sorted by name */
    private array $entries;
    private string $generatedAt;
    private string $toolVersion;
    private string $targetPhp;
    private string $targetPhpSource;
    private int $scoreModelId;
    private int $fixModel;
    /** @var list<string> */
    private array $carriedForward = [];

    /** @param array<string, BaselineEntry> $entries already sorted by package name */
    private function __construct(array $entries, string $generatedAt, string $toolVersion, string $targetPhp, string $targetPhpSource, int $scoreModelId, int $fixModel)
    {
        $this->entries = $entries;
        $this->generatedAt = $generatedAt;
        $this->toolVersion = $toolVersion;
        $this->targetPhp = $targetPhp;
        $this->targetPhpSource = $targetPhpSource;
        $this->scoreModelId = $scoreModelId;
        $this->fixModel = $fixModel;
    }

    /**
     * @param list<BaselineEntry> $entries     in any order, sorted by package name here
     * @param string              $generatedAt ISO-8601 timestamp
     * @param string              $targetPhp   a PHP minor, `8.4`
     */
    public static function of(array $entries, string $generatedAt, string $targetPhp, string $targetPhpSource, string $toolVersion = Version::STRING, int $scoreModelId = ScoreModel::ID, int $fixModel = RunSettings::FIX_MODEL): self
    {
        $byName = [];
        foreach ($entries as $entry) {
            $byName[$entry->package()] = $entry;
        }
        ksort($byName, \SORT_STRING);

        return new self($byName, $generatedAt, $toolVersion, $targetPhp, $targetPhpSource, $scoreModelId, $fixModel);
    }

    /**
     * Every graded finding of the report, with its counted flags and advisories as they count now.
     * Each `first_seen` and `known_since` of $previous is kept. An advisory that the run did not see
     * on an incomplete lookup is carried forward: only a complete lookup drops an id.
     */
    public static function fromReport(Report $report, ?self $previous = null): self
    {
        $today = $report->generatedAt()->format('Y-m-d');
        $run = $report->run();
        $entries = [];
        $carried = [];
        foreach ($report->findings() as $finding) {
            $before = $previous === null ? null : $previous->entryFor($finding->package());
            $incomplete = !$finding->details()->advisoriesComplete();
            if ($finding->isGraded()) {
                $entry = self::entryOf($finding, $before, $today, $incomplete);
                $entries[] = $entry;
                if (self::keepsUncounted($entry, $finding)) {
                    $carried[] = $finding->package();
                }
            } elseif ($before !== null && $incomplete) {
                $entries[] = $before;
                $carried[] = $finding->package();
            }
        }
        $baseline = self::of(
            $entries,
            $report->generatedAt()->format(\DATE_ATOM),
            $run === null ? PhpReleaseDates::minorOf(\PHP_VERSION) : $run->targetPhp(),
            $run === null ? RunSettings::SOURCE_RUNTIME : $run->targetPhpSource()
        );
        $baseline->carriedForward = $carried;

        return $baseline;
    }

    private static function entryOf(Finding $finding, ?BaselineEntry $before, string $today, bool $incomplete): BaselineEntry
    {
        $flags = [];
        foreach ($finding->score()->maintenanceTerms() as $term) {
            $flags[$term->flag()] = $before === null ? $today : ($before->flags()[$term->flag()] ?? $today);
        }
        $advisories = [];
        foreach ($finding->flags()->advisories() as $advisory) {
            $bucketed = $advisory->bucketed();
            $known = $before === null ? null : $before->advisory($advisory->id());
            $advisories[$advisory->id()] = new RecordedAdvisory($advisory->id(), $known === null ? $today : $known->knownSince(), $bucketed->severity(), $bucketed->fixKind());
        }
        foreach ($incomplete && $before !== null ? $before->advisories() : [] as $recorded) {
            $advisories[$recorded->id()] ??= $recorded;
        }

        return new BaselineEntry($finding->package(), $finding->version(), $before === null ? $today : $before->firstSeen(), $flags, $advisories);
    }

    /** Whether the entry keeps an advisory id that the finding does not count. */
    private static function keepsUncounted(BaselineEntry $entry, Finding $finding): bool
    {
        $counted = array_map(static fn (CountedAdvisory $advisory): string => $advisory->id(), $finding->flags()->advisories());

        return array_diff(array_map(static fn (RecordedAdvisory $advisory): string => $advisory->id(), $entry->advisories()), $counted) !== [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws ConfigException when the document does not match resources/lockrot-baseline-2.schema.json
     */
    public static function fromArray(array $data): self
    {
        BaselineSchema::validate($data);

        // The schema guarantees these. The checks only narrow `mixed` for PHPStan, which cannot read
        // the validator.
        $findings = $data['findings'] ?? [];
        if (!\is_array($findings)) {
            throw new ConfigException('baseline file is invalid: findings must be an object');
        }
        $entries = [];
        foreach ($findings as $package => $entry) {
            if (!\is_string($package)) {
                throw new ConfigException('baseline file is invalid: findings must map package names to objects');
            }
            $entries[] = self::entryFromArray($package, $entry);
        }

        return self::of(
            $entries,
            self::text($data, 'generated_at'),
            self::text($data, 'target_php'),
            self::text($data, 'target_php_source'),
            self::toolVersionOf($data['lockrot'] ?? null),
            self::integer($data['score_model_id'] ?? null, 'score_model_id'),
            self::integer($data['fix_model'] ?? null, 'fix_model')
        );
    }

    /**
     * Advisory ids are read without JsonReader::stringKeyed(): `json_decode(…, true)` turns the key
     * `"123"` into an integer, which stringKeyed() drops. Each key is cast back to a string.
     *
     * @param mixed $entry
     */
    private static function entryFromArray(string $package, $entry): BaselineEntry
    {
        $entry = self::object($entry);
        $flags = array_map(static fn ($fact): string => self::text(self::object($fact), 'known_since'), JsonReader::stringKeyed(self::object($entry['flags'] ?? [])));
        $advisories = [];
        foreach (self::object($entry['advisories'] ?? []) as $id => $fact) {
            $fact = self::object($fact);
            $advisories[] = new RecordedAdvisory((string) $id, self::text($fact, 'known_since'), self::text($fact, 'severity'), self::text($fact, 'fix_kind'));
        }

        return new BaselineEntry($package, self::text($entry, 'version'), self::text($entry, 'first_seen'), $flags, $advisories);
    }

    /**
     * @param mixed $value
     *
     * @return array<mixed, mixed>
     */
    private static function object($value): array
    {
        if (!\is_array($value)) {
            throw new ConfigException('baseline file is invalid: an entry must hold objects');
        }

        return $value;
    }

    /** @param array<mixed, mixed> $data */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            throw new ConfigException('baseline file is invalid: '.$key.' must be a string');
        }

        return $value;
    }

    /** @param mixed $value */
    private static function integer($value, string $key): int
    {
        if (!\is_int($value)) {
            throw new ConfigException('baseline file is invalid: '.$key.' must be an integer');
        }

        return $value;
    }

    /** @param mixed $lockrot the `lockrot` object of the file */
    private static function toolVersionOf($lockrot): string
    {
        $version = \is_array($lockrot) ? (JsonReader::stringKeyed($lockrot)['version'] ?? null) : null;

        return \is_string($version) ? $version : '';
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

    public function targetPhp(): string
    {
        return $this->targetPhp;
    }

    public function targetPhpSource(): string
    {
        return $this->targetPhpSource;
    }

    public function fixModel(): int
    {
        return $this->fixModel;
    }

    public function scoreModelId(): int
    {
        return $this->scoreModelId;
    }

    /** @return list<string> the packages whose recorded advisories a writer kept after an incomplete lookup */
    public function carriedForward(): array
    {
        return $this->carriedForward;
    }

    /**
     * The file's body without `$schema`, which BaselineFile adds. An empty `findings` map is an
     * object, so the file stays valid baseline-2.
     *
     * @return array{lockrot: array{version: string, schema: int}, generated_at: string, target_php: string, target_php_source: string, score_model_id: int, fix_model: int, findings: array<string, BaselineEntry>|\stdClass}
     */
    public function toArray(): array
    {
        return [
            'lockrot' => ['version' => $this->toolVersion, 'schema' => self::SCHEMA],
            'generated_at' => $this->generatedAt,
            'target_php' => $this->targetPhp,
            'target_php_source' => $this->targetPhpSource,
            'score_model_id' => $this->scoreModelId,
            'fix_model' => $this->fixModel,
            'findings' => $this->entries === [] ? new \stdClass() : $this->entries,
        ];
    }
}
