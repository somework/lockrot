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
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\TestCase;

/**
 * The `summary` of S1 to S6 and S8 says nothing that the signal's `data` does not hold: each one
 * rebuilds byte for byte from the `data` of the report-2 document, over every finding of every
 * fixture app and skeleton. The reference sentences below read the document only.
 */
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
                    $id = (string) $signal['id'];
                    if (!isset($rebuilt[$id])) {
                        continue;
                    }
                    $data = \is_array($signal['data']) ? $signal['data'] : [];
                    $sentence = self::sentence($id, $data);
                    if ($sentence !== $signal['summary']) {
                        $differ[] = basename($dir).' '.$package.' '.$id.': "'.$signal['summary'].'" rebuilt as "'.$sentence.'"';
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

    /** @return iterable<array{string, array<string, mixed>}> the package and each signal of the report-2 document */
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
        foreach ($document['findings'] as $finding) {
            foreach ($finding['signals'] as $signal) {
                yield [$finding['package'], $signal];
            }
        }
    }

    /** @param array<string, mixed> $d */
    private static function sentence(string $id, array $d): string
    {
        switch ($id) {
            case 'S1':
                $replacement = \is_string($d['replacement'] ?? null) && $d['replacement'] !== '' ? ', replacement: '.$d['replacement'] : '';

                return 'marked abandoned '.(($d['marked_by'] ?? null) === 'lock' ? 'in composer.lock' : 'by its repository').$replacement;
            case 'S2':
                return 'last release '.self::ago($d['last_release'], $d['years'], $d['dated_by'] ?? null);
            case 'S3':
                return 'repository archived on '.self::FORGE_LABELS[$d['forge']];
            case 'S4':
                return 'last '.$d['activity'].' '.self::ago($d['last_push'], $d['years'], null);
            case 'S5':
                $written = $d['written_for_php'] ?? null;

                return \sprintf(
                    'released %s %s, before PHP %s existed (%s GA %s); admits %s untested',
                    substr((string) $d['released'], 0, 10),
                    $written === null || $written === 0 ? 'with php "'.$d['php_constraint'].'"' : 'for PHP '.$written.' (php "'.$d['php_constraint'].'")',
                    explode('.', (string) $d['target_major'])[0],
                    $d['target_major'],
                    $d['ga_date'],
                    $d['target_php']
                );
            case 'S6':
                return $d['reason'] === 'branch_snapshot' ? 'pinned to branch snapshot '.$d['version'] : 'no tagged release in its repository';
            default:
                return self::s8($d);
        }
    }

    /** @param array<string, mixed> $d */
    private static function s8(array $d): string
    {
        $sentence = \sprintf('branch %s last released %s; %s released %s (%s)', $d['branch'], self::ago($d['branch_last_release'], $d['years'], $d['dated_by'] ?? null), $d['newest_branch'], $d['newest_version'], substr((string) $d['newest_release'], 0, 10));
        if ($d['newest_within_reach'] === true) {
            return $sentence;
        }
        $floor = $d['floor_source'] === 'project' ? "the project's php " : 'the target PHP ';
        $sentence .= ', needs php '.$d['newest_php'].' above '.$floor.$d['floor_php'];

        return $sentence.($d['reachable_branch'] === null ? '; no releasing branch within reach' : \sprintf('; %s released %s (%s)', $d['reachable_branch'], $d['reachable_version'], substr((string) $d['reachable_release'], 0, 10)));
    }

    /**
     * @param mixed $date
     * @param mixed $years
     * @param mixed $datedBy
     */
    private static function ago($date, $years, $datedBy): string
    {
        return \sprintf('%s (%.1f years ago%s)', substr((string) $date, 0, 10), $years, \is_string($datedBy) ? ', dated by '.$datedBy : '');
    }
}
