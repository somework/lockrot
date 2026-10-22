<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Data\Repository\StableRelease;
use Lockrot\Security\Candidate;
use Lockrot\Security\Fix;
use Lockrot\Security\Holder;
use Lockrot\Signal\Data\AbandonedData;
use Lockrot\Signal\Data\AdvisoryData;
use Lockrot\Signal\Data\AdvisoryRow;
use Lockrot\Signal\Data\ArchivedData;
use Lockrot\Signal\Data\CappedData;
use Lockrot\Signal\Data\Check;
use Lockrot\Signal\Data\ExposedPackage;
use Lockrot\Signal\Data\ExposureData;
use Lockrot\Signal\Data\LeftBehindData;
use Lockrot\Signal\Data\NoPushData;
use Lockrot\Signal\Data\NoReleaseData;
use Lockrot\Signal\Data\NotCheckedData;
use Lockrot\Signal\Data\OldPromiseData;
use Lockrot\Signal\Data\PinnedData;
use Lockrot\Signal\Data\ReachableBranch;
use Lockrot\Signal\Data\SignalData;
use Lockrot\Signal\PhpCap;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\Assert;

/**
 * Builds the typed data of a signal for a test from the keys that the test sets, as report-2
 * writes them. Each key that the test omits takes a neutral value: a null, false, an empty list,
 * an empty text or a date of 2021-05-05. A key that the data does not write fails the test.
 */
final class SignalDataStub
{
    private const DATE = '2021-05-05T00:00:00+00:00';

    /** @param array<string, mixed> $d */
    public static function of(string $id, array $d): SignalData
    {
        $data = self::build($id, $d);
        self::assertWrites($data->jsonSerialize(), $d);

        return $data;
    }

    /** @param array<string, mixed> $d */
    private static function build(string $id, array $d): SignalData
    {
        switch ($id) {
            case Signal::S1:
                return new AbandonedData(self::str($d, 'marked_by', 'repository'), self::opt($d, 'replacement'), self::opt($d, 'replacement_url'));
            case Signal::S2:
                return new NoReleaseData(self::str($d, 'last_release', self::DATE), self::opt($d, 'last_version'), self::float($d, 'years'), self::opt($d, 'dated_by'));
            case Signal::S3:
                return new ArchivedData(self::str($d, 'repo', 'vendor/pkg'), self::str($d, 'host', 'github.com'), self::str($d, 'forge', 'github'));
            case Signal::S4:
                return new NoPushData(self::str($d, 'last_push', self::DATE), self::str($d, 'repo', 'vendor/pkg'), self::str($d, 'host', 'github.com'), self::float($d, 'years'), self::str($d, 'forge', 'github'), self::str($d, 'event', 'push'));
            case Signal::S5:
                $written = $d['written_for_php'] ?? null;

                return new OldPromiseData(self::str($d, 'target_php', '8.4'), self::str($d, 'target_major', '8.0'), self::str($d, 'ga_date', '2020-11-26'), self::str($d, 'php_constraint', ''), \is_int($written) ? $written : null, self::str($d, 'released', self::DATE));
            case Signal::S6:
                $stable = $d['has_stable_release'] ?? null;

                return new PinnedData(self::str($d, 'version', '1.0.0'), self::str($d, 'reason', 'branch_snapshot'), \is_bool($stable) ? $stable : null, self::opt($d, 'last_stable_release'), self::opt($d, 'last_stable_version'), self::opt($d, 'last_stable_dated_by'), self::opt($d, 'snapshot_time'), self::opt($d, 'tag_relation'));
            case Signal::S7:
                $packages = [];
                foreach (self::list($d, 'packages') as $p) {
                    $packages[] = new ExposedPackage(self::str($p, 'package', 'vendor/dep'), self::strings($p, 'chain'), self::str($p, 'verdict', 'low'), self::opt($p, 'lead'), self::strings($p, 'flag_ids'));
                }

                return new ExposureData($packages === [] ? [new ExposedPackage('vendor/dep', ['vendor/pkg', 'vendor/dep'], 'low', 'stale', ['stale'])] : $packages);
            case Signal::S8:
                $admits = \is_array($d['reachable_admits'] ?? null) ? $d['reachable_admits'] : [];
                $reachable = self::opt($d, 'reachable_branch');
                $orphans = array_intersect(array_keys(array_filter($d, static fn ($v): bool => $v !== null)), ['reachable_version', 'reachable_release', 'reachable_years', 'reachable_php', 'reachable_admits', 'suggested_constraint']);
                Assert::assertTrue($reachable !== null || $orphans === [], 'A fact of the branch within reach needs reachable_branch: '.implode(', ', $orphans));

                return new LeftBehindData(
                    self::str($d, 'branch', '1.x'),
                    self::str($d, 'branch_last_release', self::DATE),
                    self::opt($d, 'branch_last_version'),
                    self::float($d, 'years'),
                    self::opt($d, 'dated_by'),
                    self::str($d, 'newest_branch', '2.x'),
                    self::str($d, 'newest_version', '2.0.0'),
                    self::str($d, 'newest_release', self::DATE),
                    self::opt($d, 'newest_php'),
                    self::float($d, 'newest_years') ?? 0.0,
                    ($d['newest_within_reach'] ?? false) === true,
                    self::opt($d, 'floor_php'),
                    self::opt($d, 'floor_source'),
                    $reachable === null ? null : new ReachableBranch(
                        $reachable,
                        self::str($d, 'reachable_version', '1.5.0'),
                        self::str($d, 'reachable_release', self::DATE),
                        self::float($d, 'reachable_years') ?? 0.0,
                        self::opt($d, 'reachable_php'),
                        ['project' => self::optBool($admits, 'project'), 'target' => self::optBool($admits, 'target')],
                        self::opt($d, 'suggested_constraint')
                    )
                );
            case Signal::S11:
                $admits = $d['admits_newest'] ?? null;
                $source = self::str($d, 'newest_php_source', PhpCap::KNOWN);
                $source = $source === PhpCap::REQUIRE_PHP || $source === PhpCap::PLATFORM_PHP ? $source : PhpCap::KNOWN;

                return new CappedData(self::str($d, 'newest_php', '8.5'), $source, self::str($d, 'requires', '^7.0'), self::str($d, 'stops_at', '7.4'), ($d['excludes_target'] ?? false) === true, \is_array($admits) ? ['version' => self::str($admits, 'version', '2.0.0'), 'branch' => self::str($admits, 'branch', '2.x'), 'blocking' => self::opt($admits, 'blocking')] : null);
            case Signal::S9:
                return new AdvisoryData(array_map([self::class, 'row'], self::list($d, 'advisories')), ($d['releases_read'] ?? false) === true, ($d['complete'] ?? false) === true);
            default:
                $checks = array_map(static fn (array $c): Check => new Check(self::str($c, 'check', 'repository_activity'), self::str($c, 'reason', 'no_token'), self::strings($c, 'blocks')), self::list($d, 'unchecked'));

                return new NotCheckedData($checks === [] ? [new Check('repository_activity', 'no_token', [Signal::S3, Signal::S4])] : $checks);
        }
    }

    /**
     * The signal's data keys with their PHP values, a float kept a float. A key whose value is an
     * object keeps the object.
     *
     * @return array<string, mixed>
     */
    public static function fields(Signal $signal): array
    {
        return $signal->data()->jsonSerialize();
    }

    /**
     * @param array<string, mixed> $d an S9 row, its `fix` as `{kind, ...}`. Without `points` the row
     *                                takes the points of its severity and fix.
     */
    public static function row(array $d): AdvisoryRow
    {
        $severity = self::str($d, 'severity', 'unrated');
        $fix = self::fix(\is_array($d['fix'] ?? null) ? $d['fix'] : []);
        $points = $d['points'] ?? null;
        $row = new AdvisoryRow(
            self::str($d, 'id', 'PKSA-1'),
            self::opt($d, 'cve'),
            self::opt($d, 'title'),
            self::opt($d, 'link'),
            self::opt($d, 'reported_at'),
            $severity,
            self::opt($d, 'severity_published'),
            self::opt($d, 'affected_versions'),
            \is_int($points) ? $points : ScoreModel::advisoryPoints($severity, $fix->kind()),
            $fix
        );
        self::assertWrites($row->jsonSerialize(), $d);

        return $row;
    }

    /**
     * @param array<string, mixed> $written
     * @param array<mixed, mixed>  $set
     */
    private static function assertWrites(array $written, array $set): void
    {
        $unknown = array_diff(array_map('strval', array_keys($set)), array_keys($written));
        Assert::assertSame([], array_values($unknown), 'The data does not write these keys: '.implode(', ', $unknown));
    }

    /**
     * A fix of that kind. A kind with a release fixes at `version`, `2.0.0` unless the test sets
     * it, on that version's branch, and is held by the `held_by` holders.
     *
     * @param array<mixed, mixed> $d
     */
    public static function fix(array $d): Fix
    {
        $kind = self::str($d, 'kind', Fix::UNKNOWN);
        if ($kind === Fix::UNKNOWN) {
            return Fix::unknown(self::str($d, 'reason', Fix::RELEASES_UNKNOWN) === Fix::AFFECTED_RANGE_UNKNOWN ? Fix::AFFECTED_RANGE_UNKNOWN : Fix::RELEASES_UNKNOWN);
        }
        if ($kind === Fix::NONE) {
            return Fix::none();
        }
        $version = self::str($d, 'version', '2.0.0');
        $branch = ReleaseBranch::of($version) ?? '2';
        $holders = [];
        foreach (self::list($d, 'held_by') as $h) {
            $link = self::str($h, 'link', Holder::REQUIRE);
            $holders[] = new Holder(self::str($h, 'source', Holder::PACKAGE) === Holder::ROOT ? Holder::ROOT : Holder::PACKAGE, self::opt($h, 'package'), self::opt($h, 'version'), $link === Holder::CONFLICT || $link === Holder::REQUIRE_DEV ? $link : Holder::REQUIRE, self::str($h, 'constraint', '*'));
        }
        /** @var Fix::UPDATE|Fix::UPGRADE|Fix::RAISE_PHP|Fix::BLOCKED $kind */
        $candidate = new Candidate(new StableRelease($version.'.0', $version, null, self::opt($d, 'php'), false), $branch, $kind, $holders);

        return Fix::to($candidate, self::str($d, 'newest', $version), ($d['on_installed_branch'] ?? false) === true ? $branch : null, new PhpFloor(null));
    }

    /** @param array<mixed, mixed> $d */
    private static function str(array $d, string $key, string $default): string
    {
        return \is_string($d[$key] ?? null) ? $d[$key] : $default;
    }

    /** @param array<mixed, mixed> $d */
    private static function opt(array $d, string $key): ?string
    {
        return \is_string($d[$key] ?? null) ? $d[$key] : null;
    }

    /** @param array<mixed, mixed> $d */
    private static function optBool(array $d, string $key): ?bool
    {
        return \is_bool($d[$key] ?? null) ? $d[$key] : null;
    }

    /** @param array<mixed, mixed> $d */
    private static function float(array $d, string $key): ?float
    {
        $value = $d[$key] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }

    /**
     * @param array<mixed, mixed> $d
     *
     * @return list<array<string, mixed>>
     */
    private static function list(array $d, string $key): array
    {
        $rows = [];
        foreach (\is_array($d[$key] ?? null) ? $d[$key] : [] as $row) {
            if (\is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<mixed, mixed> $d
     *
     * @return list<string>
     */
    private static function strings(array $d, string $key): array
    {
        return array_values(array_filter(\is_array($d[$key] ?? null) ? $d[$key] : [], 'is_string'));
    }
}
