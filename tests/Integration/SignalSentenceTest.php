<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Clock;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The `summary` of S1 to S6 and S8 says nothing that the signal's `data` does not hold: each one
 * rebuilds byte for byte from the `data` of the report-2 document, over every finding of every
 * fixture app and skeleton. The reference sentences below read the document only.
 *
 * It covers nothing: it rebuilds every summary of every fixture app, which pushes the signal rules
 * past the timeout of a mutation run. The unit tests of each rule and FlagSentenceTest kill those
 * mutants.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class SignalSentenceTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const NOW = '2026-09-14T00:00:00+00:00';
    private const IDS = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S8'];
    private const FORGE_LABELS = ['github' => 'GitHub', 'gitlab' => 'GitLab', 'bitbucket' => 'Bitbucket'];

    public function testEverySummaryRebuildsFromItsData(): void
    {
        $dirs = array_merge(glob(self::FIXTURES.'apps/*', \GLOB_ONLYDIR) ?: [], glob(self::FIXTURES.'skeletons/*', \GLOB_ONLYDIR) ?: []);
        sort($dirs);
        self::assertNotSame([], $dirs);
        $server = FixtureRepositoryServer::fromLockFiles(array_map(static fn (string $dir): string => $dir.'/composer.lock', $dirs));
        $server->start();
        $rebuilt = array_fill_keys(self::IDS, 0);
        $differ = [];
        try {
            foreach ($dirs as $dir) {
                foreach (self::signals($server, $dir) as [$package, $signal]) {
                    $id = JsonPath::stringAt($signal, ['id']);
                    if (!isset($rebuilt[$id])) {
                        continue;
                    }
                    $data = JsonPath::arrayAt($signal, ['data']);
                    $sentence = self::sentence($id, $data);
                    $summary = JsonPath::stringAt($signal, ['summary']);
                    if ($sentence !== $summary) {
                        $differ[] = basename($dir).' '.$package.' '.$id.': "'.$summary.'" rebuilt as "'.$sentence.'"';
                        continue;
                    }
                    ++$rebuilt[$id];
                }
            }
        } finally {
            $server->stop();
        }

        self::assertSame([], $differ);
        self::assertSame([], array_keys(array_filter($rebuilt, static fn (int $count): bool => $count === 0)), 'every signal occurs in the fixtures');
    }

    /** @return iterable<array{string, array<mixed, mixed>}> the package and each signal of the report-2 document */
    private static function signals(FixtureRepositoryServer $server, string $dir): iterable
    {
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens('recorded', null));
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock),
            new ActivityClient(new RecordedHttpClient(self::FIXTURES.'http/github'), $auth),
            new ActivityFetchPlanner($auth),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            false
        );
        $report = $analyzer->analyze(LockFile::fromFile($dir.'/composer.lock'), ProjectConfig::fromFile($dir.'/composer.json'), false);
        $document = json_decode((string) json_encode($report->toArray()), true);
        self::assertIsArray($document);
        foreach (JsonPath::arrayAt($document, ['findings']) as $finding) {
            self::assertIsArray($finding);
            foreach (JsonPath::arrayAt($finding, ['signals']) as $signal) {
                self::assertIsArray($signal);
                yield [JsonPath::stringAt($finding, ['package']), $signal];
            }
        }
    }

    /** @param array<mixed, mixed> $d */
    private static function sentence(string $id, array $d): string
    {
        switch ($id) {
            case 'S1':
                $replacement = \is_string($d['replacement'] ?? null) && $d['replacement'] !== '' ? ', replacement: '.$d['replacement'] : '';

                return 'marked abandoned '.(($d['marked_by'] ?? null) === 'lock' ? 'in composer.lock' : 'by its repository').$replacement;
            case 'S2':
                return 'last release '.self::ago($d, 'last_release', $d['dated_by'] ?? null);
            case 'S3':
                return 'repository archived on '.self::FORGE_LABELS[self::text($d, 'forge')];
            case 'S4':
                return 'last '.self::text($d, 'activity').' '.self::ago($d, 'last_push', null);
            case 'S5':
                $written = $d['written_for_php'] ?? null;
                $constraint = self::text($d, 'php_constraint');

                return \sprintf(
                    'released %s %s, before PHP %s existed (%s GA %s); admits %s untested',
                    substr(self::text($d, 'released'), 0, 10),
                    $written === null || $written === 0 || !\is_int($written) ? 'with php "'.$constraint.'"' : 'for PHP '.$written.' (php "'.$constraint.'")',
                    explode('.', self::text($d, 'target_major'))[0],
                    self::text($d, 'target_major'),
                    self::text($d, 'ga_date'),
                    self::text($d, 'target_php')
                );
            case 'S6':
                return self::text($d, 'reason') === 'branch_snapshot' ? 'pinned to branch snapshot '.self::text($d, 'version') : 'no tagged release in its repository';
            default:
                return self::s8($d);
        }
    }

    /** @param array<mixed, mixed> $d */
    private static function s8(array $d): string
    {
        $sentence = \sprintf('branch %s last released %s; %s released %s (%s)', self::text($d, 'branch'), self::ago($d, 'branch_last_release', $d['dated_by'] ?? null), self::text($d, 'newest_branch'), self::text($d, 'newest_version'), substr(self::text($d, 'newest_release'), 0, 10));
        if (($d['newest_within_reach'] ?? null) === true) {
            return $sentence;
        }
        $floor = self::text($d, 'floor_source') === 'project' ? "the project's php " : 'the target PHP ';
        $sentence .= ', needs php '.self::text($d, 'newest_php').' above '.$floor.self::text($d, 'floor_php');
        if (($d['reachable_branch'] ?? null) === null) {
            return $sentence.'; no releasing branch within reach';
        }

        return $sentence.\sprintf('; %s released %s (%s)', self::text($d, 'reachable_branch'), self::text($d, 'reachable_version'), substr(self::text($d, 'reachable_release'), 0, 10));
    }

    /**
     * @param array<mixed, mixed> $d
     * @param mixed               $datedBy
     */
    private static function ago(array $d, string $dateKey, $datedBy): string
    {
        $years = $d['years'] ?? null;
        if (!\is_int($years) && !\is_float($years)) {
            self::fail('years is no number');
        }

        return \sprintf('%s (%.1f years ago%s)', substr(self::text($d, $dateKey), 0, 10), $years, \is_string($datedBy) ? ', dated by '.$datedBy : '');
    }

    /** @param array<mixed, mixed> $d */
    private static function text(array $d, string $key): string
    {
        return JsonPath::stringAt($d, [$key]);
    }
}
