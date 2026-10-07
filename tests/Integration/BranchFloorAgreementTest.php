<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Explain\Explanation;
use Lockrot\Json\Schemas;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Signal\PhpFloor;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\MemoisingMetadataLoader;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * S8 says as `floor_source` whether the newest releasing branch above the installed one is within
 * reach. `--explain` and the page say it again for every branch row as `php_blocked_by`. The two
 * answers must agree. Every fixture lock is analysed with S8 and with the explanation, both built
 * from the target PHP and the project's `require.php`, and every S8 finding is read against its rows.
 *
 * Both sides get the same floors, so the agreement is tested where both sides see the same floor.
 * This test holds S8 to the project's php over the fixtures.
 */
final class BranchFloorAgreementTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const FIXTURES = __DIR__.'/../fixtures/';
    private const NOW = '2026-09-14T00:00:00+00:00';
    private const SIDES = [PhpFloor::NEEDS_NEWER, PhpFloor::STOPS_BEFORE, PhpFloor::SKIPS, PhpFloor::UNSATISFIABLE];

    /** @var array<string, true> every `<floor> <side>` the rows gave, so the check is seen to bite */
    private array $sides = [];

    /**
     * Every fixture lock served at once, so a package has every branch any of them locks. That much
     * metadata leaves composer's static constraint caches and the allocator well above where the
     * suite's other tests start, under the 128 MB memory_limit they share: the test runs in a
     * process of its own, which never calls setUpBeforeClass(), so it builds its own server.
     *
     * @runInSeparateProcess
     */
    #[RunInSeparateProcess]
    public function testEveryBranchRowAgreesWithS8OnEveryFixture(): void
    {
        $dirs = [];
        foreach (['apps', 'skeletons'] as $group) {
            foreach ((array) glob(self::FIXTURES.$group.'/*/composer.lock') as $lock) {
                $dirs[] = $group.'/'.basename(\dirname((string) $lock));
            }
        }
        sort($dirs);
        self::assertGreaterThanOrEqual(20, \count($dirs), 'the fixture locks');
        $server = FixtureRepositoryServer::fromLockFiles(array_map(static fn (string $dir): string => self::FIXTURES.$dir.'/composer.lock', $dirs));
        $server->start();
        try {
            // One loader for every analysis: the metadata does not depend on the floors, only S8 does.
            $loader = new MemoisingMetadataLoader(new RepositoryMetadataLoader($server->repositories(), Clock::fixed(self::NOW)));
            $sources = [];
            $validated = [];
            foreach ($dirs as $dir) {
                $project = ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json');
                // The command's two floors, and a run on an old target with no project php of its own.
                foreach ([['8.4', $project->requirePhp()], ['7.4', null]] as [$target, $projectPhp]) {
                    $what = $dir.' @'.$target.($projectPhp === null ? '' : ' php '.$projectPhp);
                    $analysis = $this->analysis($loader, $dir, $target, $projectPhp);
                    foreach ($analysis->report()->findings() as $finding) {
                        $facts = $analysis->facts($finding->package());
                        self::assertNotNull($facts, $finding->package());
                        $explanation = new Explanation($finding, $facts, new Thresholds(), $target, $analysis->report(), $projectPhp);
                        $rows = $this->rows($explanation, $what.' '.$finding->package());
                        if ($dir === 'apps/wallabag_wallabag' && $projectPhp !== null && $rows !== []) {
                            $validated += $this->validate($explanation, $finding->package());
                        }
                        foreach ($finding->signals() as $signal) {
                            if ($signal->id() === Signal::S8) {
                                $source = $this->agree($signal->data(), $rows, $target, $projectPhp, $what.' '.$finding->package());
                                $sources[$source ?? 'null'] = true;
                            }
                        }
                    }
                }
            }
        } finally {
            $server->stop();
        }

        ksort($sources);
        self::assertSame(['null' => true, 'project' => true, 'target' => true], $sources, 'every floor_source the fixtures can give, or the agreement proves little');
        // Rows answering both ways went through the schema, not only rows with nothing to say.
        self::assertArrayHasKey('NULL', $validated, 'a row within reach, validated');
        self::assertArrayHasKey("'project'", $validated, 'a row wallabag\'s own php >=8.2 holds back, validated');
        ksort($this->sides);
        self::assertSame(
            ['project needs_newer', 'project stops_before', 'target needs_newer', 'target stops_before'],
            array_keys(array_intersect_key($this->sides, array_flip(['project needs_newer', 'project stops_before', 'target needs_newer', 'target stops_before']))),
            'rows missing a floor from either side, or the side check proves little'
        );
    }

    /**
     * The document `--explain --format=json` prints, against the explain schema as published and
     * its strict twin, which rejects a row field the schema does not list.
     *
     * @return array<string, true> the php_blocked_by values its rows carry, exported
     */
    private function validate(Explanation $explanation, string $what): array
    {
        $json = (new ExplainFormatter())->json($explanation);
        $this->assertValid(Schemas::EXPLAIN, $json, $what);
        $this->assertValid(Schemas::EXPLAIN, $json, $what, true);
        $seen = [];
        $metadata = $explanation->toArray()['metadata'];
        self::assertIsArray($metadata);
        self::assertIsArray($metadata['branches']);
        foreach ($metadata['branches'] as $row) {
            self::assertIsArray($row);
            $seen[var_export($row['php_blocked_by'], true)] = true;
        }

        return $seen;
    }

    /**
     * @param array<string, mixed>                $data S8's data
     * @param array<string, array<mixed, mixed>> $rows the explanation's branch rows by label
     */
    private function agree(array $data, array $rows, string $target, ?string $projectPhp, string $what): ?string
    {
        $source = $data['floor_source'];
        self::assertTrue($source === null || \is_string($source), $what);
        $newest = $data['newest_branch'];
        self::assertIsString($newest, $what);
        self::assertArrayHasKey($newest, $rows, $what.': the newest branch has a row');
        $row = $rows[$newest];
        self::assertSame($source, $row['php_blocked_by'], $what.': the newest branch\'s row says what floor_source says');
        self::assertSame($data['newest_php'], $row['php'], $what.': the same requirement was tested');
        self::assertSame($data['newest_within_reach'], $row['php_blocked_by'] === null, $what);
        if ($source !== null) {
            self::assertSame($source === PhpFloor::PROJECT ? $projectPhp : $target, $data['floor_php'], $what.': the floor named is the one given');
        }
        $reachable = $data['reachable_branch'];
        if ($reachable !== null) {
            self::assertIsString($reachable, $what);
            self::assertArrayHasKey($reachable, $rows, $what.': the reachable branch has a row');
            self::assertNull($rows[$reachable]['php_blocked_by'], $what.': S8 suggested a branch its row holds back');
        }

        return $source;
    }

    /** @return array<string, array<mixed, mixed>> the branch rows `--explain --format=json` writes, by label */
    private function rows(Explanation $explanation, string $what): array
    {
        $metadata = $explanation->toArray()['metadata'];
        if (!\is_array($metadata)) {
            return [];
        }
        self::assertIsArray($metadata['branches'], $what);
        $rows = [];
        foreach ($metadata['branches'] as $row) {
            self::assertIsArray($row, $what);
            self::assertIsString($row['branch'], $what);
            $blockedBy = $row['php_blocked_by'];
            // The schema leaves the value open, so a floor source that lockrot adds must join this list.
            self::assertContains($blockedBy, [PhpFloor::PROJECT, PhpFloor::TARGET, null], $what);
            // The row's verdict is its two answers read in S8's order, and null is no answer.
            $composed = $row['admits_project_php'] === false ? PhpFloor::PROJECT : ($row['admits_target_php'] === false ? PhpFloor::TARGET : null);
            self::assertSame($composed, $blockedBy, $what.' '.$row['branch']);
            // Which side of each floor the row is on: given exactly where the floor is not
            // admitted, whichever floor php_blocked_by names.
            foreach ([PhpFloor::TARGET, PhpFloor::PROJECT] as $kind) {
                $side = $row['misses_'.$kind.'_php'];
                if ($row['admits_'.$kind.'_php'] === false) {
                    self::assertContains($side, self::SIDES, $what.' '.$row['branch'].' '.$kind);
                    self::assertIsString($side);
                    $this->sides[$kind.' '.$side] = true;
                } else {
                    self::assertNull($side, $what.' '.$row['branch'].' '.$kind.': admitted or no answer');
                }
            }
            if ($row['php'] === null) {
                self::assertNull($row['admits_target_php'], $what.': no requirement is no answer');
                self::assertNull($row['admits_project_php'], $what.': no requirement is no answer');
            }
            $rows[$row['branch']] = $row;
        }

        return $rows;
    }

    private function analysis(MetadataLoaderInterface $loader, string $dir, string $target, ?string $projectPhp): Analysis
    {
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        // Offline: S8 reads only the repository metadata, and a fixture without recorded forge
        // envelopes would only add S10 rows.
        $analyzer = new Analyzer(
            $loader,
            new ActivityClient(new RecordedHttpClient(self::FIXTURES.'http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), $target, PhpReleaseDates::load(), $projectPhp),
            new VerdictEngine(),
            $clock,
            true
        );
        $lock = LockFile::fromFile(self::FIXTURES.$dir.'/composer.lock');

        return $analyzer->analyzeWithFacts($lock->packages(false), $lock, ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json'), false);
    }
}
