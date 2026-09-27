<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Composer\Repository\AdvisoryProviderInterface;
use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataBatch;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Explain\Explanation;
use Lockrot\Json\Schemas;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\NoFix;
use Lockrot\Verdict\Priority;
use Lockrot\Verdict\PriorityBasis;
use Lockrot\Verdict\Verdict;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

/**
 * A finding's `priority_basis` and `no_fix_expected` against the rest of the document, in every
 * report and `--explain` document written here, with the advisory paths seeded so each runs end to
 * end: an abandoned package a listed release fixes and one nothing fixes, a silent one nothing
 * fixes, a left-behind one whose branch carries the fix and one fixed only on a higher branch, an
 * abandoned-in-the-lock one whose metadata failed, a stale one, an allowlisted one and an ok one.
 * Hand-written expectations per package, then the general rules on every finding. Every document is
 * validated against its schema and the strict twin.
 *
 * Composer builds every advisory with an affected range, and a repository that serves them inline
 * must serve them whole, so `affected_range_unknown` cannot come through here: FindingTest covers it.
 */
final class FindingFactsConsistencyTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const FIXTURES = __DIR__.'/../fixtures/';
    private const NOW = '2026-09-14T00:00:00+00:00';
    private const WALLABAG = 'apps/wallabag_wallabag';
    /** The package whose metadata the repository fails to serve; its lock marks it abandoned. */
    private const METADATA_FAILS = 'hoa/compiler';

    /** Per package: the verdict, the basis as base and steps, and the no-fix list. */
    private const EXPECTED = [
        'doctrine/cache' => ['abandoned', ['critical', [['transitive', 'critical', 'high'], ['no_fix_expected', 'high', 'critical']]], [['PKSA-cache-1', 'no_release_fixes']]],
        'hoa/consistency' => ['abandoned', ['critical', [['transitive', 'critical', 'high']]], []],
        'javibravo/simpleue' => ['silent', ['critical', [['no_fix_expected', 'critical', 'critical']]], [['PKSA-simpleue-1', 'no_release_fixes']]],
        'lcobucci/jwt' => ['left-behind', ['high', []], []],
        'spomky-labs/otphp' => ['left-behind', ['high', [['transitive', 'high', 'medium'], ['no_fix_expected', 'medium', 'high']]], [['PKSA-otphp-1', 'not_on_installed_branch'], ['PKSA-otphp-2', 'no_release_fixes']]],
        self::METADATA_FAILS => ['abandoned', ['critical', [['transitive', 'critical', 'high'], ['no_fix_expected', 'high', 'critical']]], [['PKSA-compiler-1', 'releases_unknown']]],
        'defuse/php-encryption' => ['stale', ['medium', []], null],
        'psr/log' => ['finished', ['none', []], null],
        'twig/twig' => ['ok', ['none', []], null],
    ];

    /** The same lock read without its composer.json: nothing reaches any package. */
    private const EXPECTED_UNREACHED = [
        'doctrine/cache' => ['abandoned', ['critical', [['unreached', 'critical', 'high'], ['no_fix_expected', 'high', 'critical']]], [['PKSA-cache-1', 'no_release_fixes']]],
        'javibravo/simpleue' => ['silent', ['critical', [['unreached', 'critical', 'high'], ['no_fix_expected', 'high', 'critical']]], [['PKSA-simpleue-1', 'no_release_fixes']]],
        'lcobucci/jwt' => ['left-behind', ['high', [['unreached', 'high', 'medium']]], []],
        'defuse/php-encryption' => ['stale', ['medium', [['unreached', 'medium', 'low']]], null],
    ];

    /** @var array<string, int> every step and no-fix reason the documents gave */
    private array $seen = [];

    public function testThePriorityBasisAndTheNoFixListAgreeWithEverythingElseOnEveryFinding(): void
    {
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            self::markTestSkipped('Composer 2.2 has no advisory API, so no finding carries S9.');
        }
        $locks = [self::WALLABAG, 'apps/matomo-org_matomo', 'skeletons/laravel'];
        $server = FixtureRepositoryServer::fromLockFiles(array_map(static fn (string $dir): string => self::FIXTURES.$dir.'/composer.lock', $locks));
        $server->withSecurityAdvisories(self::advisories());
        $server->start();
        try {
            $analyzer = self::analyzer($server);
            $wallabag = self::wallabagLock();
            $runs = [
                'wallabag --dev' => $analyzer->analyzeWithFacts($wallabag->packages(true), $wallabag, ProjectConfig::fromFile(self::FIXTURES.self::WALLABAG.'/composer.json'), true),
                'wallabag without composer.json' => $analyzer->analyzeWithFacts($wallabag->packages(false), $wallabag, ProjectConfig::empty(), false),
            ];
            foreach (['apps/matomo-org_matomo', 'skeletons/laravel'] as $dir) {
                $lock = LockFile::fromFile(self::FIXTURES.$dir.'/composer.lock');
                $runs[$dir] = $analyzer->analyzeWithFacts($lock->packages(false), $lock, ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json'), false);
            }
        } finally {
            $server->stop();
        }

        $this->expectations($runs['wallabag --dev'], self::EXPECTED);
        $this->expectations($runs['wallabag without composer.json'], self::EXPECTED_UNREACHED);
        foreach ($runs as $name => $analysis) {
            $this->agree($analysis, $name);
        }

        foreach (array_merge(PriorityBasis::STEPS, array_diff(NoFix::REASONS, [NoFix::AFFECTED_RANGE_UNKNOWN])) as $reason) {
            self::assertArrayHasKey($reason, $this->seen, 'the documents give '.$reason.', or the rules prove little: '.json_encode($this->seen));
        }
    }

    /**
     * @param array<string, array{string, array{string, list<array{string, string, string}>}, ?list<array{string, string}>}> $expected
     */
    private function expectations(Analysis $analysis, array $expected): void
    {
        $findings = [];
        foreach (JsonPath::arrayAt($analysis->report()->toArray(), ['findings']) as $row) {
            self::assertIsArray($row);
            $findings[JsonPath::stringAt($row, ['package'])] = $row;
        }
        foreach ($expected as $package => [$verdict, [$base, $steps], $noFix]) {
            self::assertArrayHasKey($package, $findings);
            $row = $findings[$package];
            self::assertSame($verdict, $row['verdict'], $package);
            self::assertSame(['base' => $base, 'steps' => array_map(static fn (array $step): array => ['reason' => $step[0], 'from' => $step[1], 'to' => $step[2]], $steps)], $row['priority_basis'], $package);
            self::assertSame($noFix === null ? null : array_map(static fn (array $item): array => ['id' => $item[0], 'reason' => $item[1]], $noFix), $row['no_fix_expected'], $package);
        }
    }

    private function agree(Analysis $analysis, string $run): void
    {
        $report = $analysis->report();
        $json = (new JsonFormatter())->format($report);
        $this->assertValid(Schemas::REPORT, $json, $run);
        $this->assertValid(Schemas::REPORT, $json, $run, true);
        $document = json_decode($json, true);
        self::assertIsArray($document);
        foreach (JsonPath::arrayAt($document, ['findings']) as $row) {
            self::assertIsArray($row);
            $package = JsonPath::stringAt($row, ['package']);
            $this->rules($row, $run.' '.$package);

            $finding = $analysis->finding($package);
            $facts = $analysis->facts($package);
            self::assertNotNull($finding);
            self::assertNotNull($facts);
            if (!Verdict::flagged($finding->verdict()) && !self::carries($finding, Signal::S9)) {
                continue;
            }
            $explained = (new ExplainFormatter())->json(new Explanation($finding, $facts, new Thresholds(), '8.4', $report));
            $this->assertValid(Schemas::EXPLAIN, $explained, $run.' --explain '.$package);
            $this->assertValid(Schemas::EXPLAIN, $explained, $run.' --explain '.$package, true);
            $explanation = json_decode($explained, true);
            self::assertIsArray($explanation);
            $inExplain = JsonPath::arrayAt($explanation, ['finding']);
            self::assertSame($row['priority_basis'], $inExplain['priority_basis'], $run.' '.$package.': the explanation\'s copy');
            self::assertSame($row['no_fix_expected'], $inExplain['no_fix_expected'], $run.' '.$package.': the explanation\'s copy');
        }
    }

    /**
     * The rules a page reads the two fields by, checked against the finding's other fields.
     *
     * @param array<mixed, mixed> $row
     */
    private function rules(array $row, string $what): void
    {
        $basis = JsonPath::arrayAt($row, ['priority_basis']);
        $steps = JsonPath::arrayAt($basis, ['steps']);
        $at = $basis['base'];
        $reasons = [];
        foreach ($steps as $step) {
            self::assertIsArray($step);
            self::assertSame($at, $step['from'], $what.': each step starts where the last ended');
            $at = $step['to'];
            $reason = JsonPath::stringAt($step, ['reason']);
            $reasons[] = $reason;
            $this->seen[$reason] = ($this->seen[$reason] ?? 0) + 1;
        }
        self::assertSame($row['priority'], $at, $what.': the steps fold to the priority');

        $noFix = $row['no_fix_expected'];
        self::assertSame(!\in_array($row['verdict'], Finding::NO_FIX_VERDICTS, true), $noFix === null, $what.': null exactly off the no-fix verdicts');
        $expected = [];
        if (Verdict::flagged(JsonPath::stringAt($row, ['verdict']))) {
            if ($row['direct'] !== true) {
                $expected[] = $row['chain'] === [] ? PriorityBasis::STEP_UNREACHED : PriorityBasis::STEP_TRANSITIVE;
            }
            if ($row['dev'] === true) {
                $expected[] = PriorityBasis::STEP_DEV;
            }
            if (\is_array($noFix) && $noFix !== []) {
                $expected[] = PriorityBasis::STEP_NO_FIX_EXPECTED;
            }
        } else {
            self::assertSame(Priority::NONE, $basis['base'], $what);
        }
        self::assertSame($expected, $reasons, $what.': one step for each fact that holds, in order');
        self::assertSame(\is_array($noFix) && $noFix !== [], strpos(JsonPath::stringAt($row, ['evidence']), 'no fix expected') !== false, $what.': the evidence says it exactly when the list names one');

        $s9 = self::s9($row);
        $rows = [];
        foreach ($s9 === null ? [] : JsonPath::arrayAt($s9, ['advisories']) as $advisory) {
            self::assertIsArray($advisory);
            $rows[JsonPath::stringAt($advisory, ['id'])] = $advisory;
        }
        $order = array_keys($rows);
        $ids = [];
        foreach (\is_array($noFix) ? $noFix : [] as $item) {
            self::assertIsArray($item);
            $id = JsonPath::stringAt($item, ['id']);
            $reason = JsonPath::stringAt($item, ['reason']);
            self::assertArrayHasKey($id, $rows, $what.': an id of one of the finding\'s S9 rows');
            $advisory = $rows[$id];
            $ids[] = $id;
            $this->seen[$reason] = ($this->seen[$reason] ?? 0) + 1;
            self::assertNotNull($s9);
            $read = $s9['releases_read'];
            switch ($reason) {
                case NoFix::NOT_ON_INSTALLED_BRANCH:
                    self::assertSame(Verdict::LEFT_BEHIND, $row['verdict'], $what);
                    self::assertNotNull($advisory['fixed_by'], $what);
                    self::assertFalse($advisory['fixed_on_branch'], $what);

                    break;
                case NoFix::RELEASES_UNKNOWN:
                    self::assertFalse($read, $what);

                    break;
                case NoFix::AFFECTED_RANGE_UNKNOWN:
                    self::assertTrue($read, $what);
                    self::assertNull($advisory['affected_versions'], $what);

                    break;
                case NoFix::NO_RELEASE_FIXES:
                    self::assertTrue($read, $what);
                    self::assertNull($advisory['fixed_by'], $what);
                    self::assertNotNull($advisory['affected_versions'], $what);

                    break;
                default:
                    self::fail($what.': a reason this release does not write: '.json_encode($item));
            }
        }
        self::assertSame($ids, array_values(array_intersect($order, $ids)), $what.': in S9 row order, each once');
    }

    /**
     * @param array<mixed, mixed> $row
     *
     * @return ?array<mixed, mixed>
     */
    private static function s9(array $row): ?array
    {
        foreach (JsonPath::arrayAt($row, ['signals']) as $signal) {
            self::assertIsArray($signal);
            if ($signal['id'] === Signal::S9) {
                return JsonPath::arrayAt($signal, ['data']);
            }
        }

        return null;
    }

    private static function carries(Finding $finding, string $id): bool
    {
        foreach ($finding->signals() as $signal) {
            if ($signal->id() === $id) {
                return true;
            }
        }

        return false;
    }

    /** wallabag's lock with lcobucci/jwt a release below its branch's highest, 4.3.0, which the advisory seeded for it spares. */
    private static function wallabagLock(): LockFile
    {
        $lock = JsonPath::decodeFile(self::FIXTURES.self::WALLABAG.'/composer.lock');
        $packages = JsonPath::arrayAt($lock, ['packages']);
        foreach ($packages as $i => $package) {
            self::assertIsArray($package);
            if ($package['name'] === 'lcobucci/jwt') {
                $packages[$i] = array_merge($package, ['version' => '4.2.0']);
            }
        }
        $lock['packages'] = $packages;
        $typed = [];
        foreach ($lock as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return LockFile::fromArray($typed);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function advisories(): array
    {
        $full = static fn (string $package, string $id, string $range, string $severity): array => [
            'advisoryId' => $id,
            'packageName' => $package,
            'remoteId' => $id,
            'cve' => null,
            'title' => 'Advisory '.$id,
            'link' => 'https://example.test/'.$id,
            'affectedVersions' => $range,
            'sources' => [['name' => 'FriendsOfPHP/security-advisories', 'remoteId' => $id]],
            'reportedAt' => '2024-03-01 12:00:00',
            'severity' => $severity,
        ];

        return [
            // abandoned, no release above 2.2.0 on its branch or anywhere: nothing fixes it
            'doctrine/cache' => [$full('doctrine/cache', 'PKSA-cache-1', '>=2.0,<2.3', 'high')],
            // abandoned, fixed by the package's highest tag, 2.17.08.29
            'hoa/consistency' => [$full('hoa/consistency', 'PKSA-consistency-1', '>=1.0,<2.0', 'medium')],
            // silent, and nothing above 2.1.0 is listed
            'javibravo/simpleue' => [$full('javibravo/simpleue', 'PKSA-simpleue-1', '>=2.0', 'medium')],
            // left behind on 4.x, fixed by 4.3.0 on that branch
            'lcobucci/jwt' => [$full('lcobucci/jwt', 'PKSA-jwt-1', '>=4.0,<4.3.0', 'high')],
            // left behind on 10.x: one fixed only by 11.5.0, one fixed nowhere
            'spomky-labs/otphp' => [$full('spomky-labs/otphp', 'PKSA-otphp-1', '>=10.0,<11.0', 'high'), $full('spomky-labs/otphp', 'PKSA-otphp-2', '>=10.0', 'low')],
            // abandoned in the lock; the metadata it would be checked against fails
            self::METADATA_FAILS => [$full(self::METADATA_FAILS, 'PKSA-compiler-1', '>=3.0', 'high')],
            // stale, allowlisted and ok: no fix prediction
            'defuse/php-encryption' => [$full('defuse/php-encryption', 'PKSA-defuse-1', '>=2.0', 'high')],
            'psr/log' => [$full('psr/log', 'PKSA-log-1', '>=1.0', 'high')],
            'twig/twig' => [$full('twig/twig', 'PKSA-twig-1', '>=3.0', 'high')],
        ];
    }

    private static function analyzer(FixtureRepositoryServer $server): Analyzer
    {
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $metadata = new RepositoryMetadataLoader($server->repositories(), $clock);
        $failing = new class ($metadata, self::METADATA_FAILS) implements MetadataLoaderInterface {
            private MetadataLoaderInterface $loader;
            private string $name;

            public function __construct(MetadataLoaderInterface $loader, string $name)
            {
                $this->loader = $loader;
                $this->name = $name;
            }

            public function load(array $names): MetadataBatch
            {
                $batch = $this->loader->load($names);
                if (!\in_array($this->name, $names, true)) {
                    return $batch;
                }
                $metadata = $batch->metadata();
                unset($metadata[$this->name]);

                return new MetadataBatch($metadata, $batch->notFound(), array_merge($batch->failed(), [$this->name => 'HTTP 500']));
            }
        };

        return new Analyzer(
            $failing,
            new ActivityClient(new RecordedHttpClient(self::FIXTURES.'http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            false,
            new RepositoryAdvisoryLoader($server->repositories())
        );
    }
}
