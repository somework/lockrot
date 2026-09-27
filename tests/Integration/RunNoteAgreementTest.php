<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Allowlist\BuiltinAllowlist;
use Lockrot\Analyzer\Analysis;
use Lockrot\Analyzer\Analyzer;
use Lockrot\Analyzer\RunNote;
use Lockrot\Clock;
use Lockrot\Data\Advisory\RepositoryAdvisoryLoader;
use Lockrot\Data\Forge\ActivityClient;
use Lockrot\Data\Forge\ActivityFetchPlanner;
use Lockrot\Data\Forge\ForgeAuth;
use Lockrot\Data\Forge\RepoLocator;
use Lockrot\Data\Forge\Tokens;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Data\Php\PhpReleaseDates;
use Lockrot\Data\Repository\RepositoryMetadataLoader;
use Lockrot\Deadline;
use Lockrot\Explain\Explanation;
use Lockrot\Json\Schemas;
use Lockrot\Lock\LockFile;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\JsonFormatter;
use Lockrot\Signal\Rule\NotCheckedRule;
use Lockrot\Signal\SignalSet;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\AssertsNoteDetails;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use Lockrot\Verdict\VerdictEngine;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The typed notes of real runs: every fixture lock, online with a token and without one, offline, and
 * with the install-time budget already spent. Each report and the explanations of its first findings
 * validate against both schemas, their `note_details` agree with `notes` and the root counts, and
 * the activity notes agree with the reason each package's own facts give for not reading its
 * repository (what S10 reports): the cap's two parts are the packages skipped for a token and for
 * the budget, and every package the forge failed for is a repository the notes name.
 *
 * @coversNothing
 */
#[CoversNothing]
final class RunNoteAgreementTest extends TestCase
{
    use AssertsNoteDetails;
    use ValidatesJsonSchemas;

    private const FIXTURES = __DIR__.'/../fixtures/';
    private const NOW = '2026-09-14T00:00:00+00:00';

    /** @var array<string, true> every code the corpus gave, so the check is seen to bite */
    private array $seen = [];

    /**
     * @runInSeparateProcess
     */
    #[RunInSeparateProcess]
    public function testEveryRunsNotesAgreeWithItsDocumentAndItsFindings(): void
    {
        $dirs = ['mini', 'mini-split'];
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
            foreach ($dirs as $dir) {
                foreach (['online-token' => [true, false, false], 'online-anonymous' => [false, false, false], 'offline' => [true, true, false], 'budget' => [false, false, true]] as $variant => [$token, $offline, $budget]) {
                    $this->agree($this->analyse($server, $dir, $token, $offline, $budget), $dir.' '.$variant);
                }
            }
        } finally {
            $server->stop();
        }

        foreach ([RunNote::OFFLINE, RunNote::METADATA_UNAVAILABLE, RunNote::ADVISORIES_NOT_CHECKED, RunNote::REPOSITORY_ACTIVITY_NOT_CHECKED, RunNote::REPOSITORY_ACTIVITY_ANONYMOUS_CAP, RunNote::REPOSITORY_ACTIVITY_UNREACHABLE, RunNote::NOT_FROM_COMPOSER_REPOSITORY] as $code) {
            self::assertArrayHasKey($code, $this->seen, 'the fixtures give '.$code.', or the agreement proves little');
        }
    }

    private function agree(Analysis $analysis, string $what): void
    {
        $report = $analysis->report();
        $json = (new JsonFormatter())->format($report);
        $this->assertValid(Schemas::REPORT, $json, $what);
        $this->assertValid(Schemas::REPORT, $json, $what, true);
        $document = json_decode($json, true);
        self::assertIsArray($document);
        self::assertNoteDetailsAgree($document, $what);
        foreach (\array_slice($report->findings(), 0, 2) as $finding) {
            $facts = $analysis->facts($finding->package());
            self::assertNotNull($facts);
            $explained = (new ExplainFormatter())->json(new Explanation($finding, $facts, new Thresholds(), '8.4', $report));
            $this->assertValid(Schemas::EXPLAIN, $explained, $what.' '.$finding->package(), true);
            $decoded = json_decode($explained, true);
            self::assertIsArray($decoded);
            self::assertNoteDetailsAgree($decoded, $what.' '.$finding->package());
            self::assertSame($document['note_details'], $decoded['note_details'], $what.': the explanation carries the run\'s notes');
        }

        $byCode = [];
        foreach ($report->runNotes() as $note) {
            $this->seen[$note->code()] = true;
            $byCode[$note->code()][] = $note;
        }
        $this->activityAgrees($analysis, $byCode, $what);
    }

    /** @param array<string, list<RunNote>> $byCode */
    private function activityAgrees(Analysis $analysis, array $byCode, string $what): void
    {
        $locator = new RepoLocator();
        $reasonsByForge = [];
        $failedKeys = [];
        foreach ($analysis->report()->findings() as $finding) {
            $facts = $analysis->facts($finding->package());
            self::assertNotNull($facts);
            $reason = $facts->activityNotChecked();
            if ($reason === null) {
                continue;
            }
            $meta = $facts->metadata();
            $repo = $locator->locate(($meta !== null ? $meta->repositoryUrl() : null) ?? $facts->package()->repositoryUrl());
            self::assertNotNull($repo, $what.' '.$finding->package());
            $reasonsByForge[$repo->forge()][$reason] = ($reasonsByForge[$repo->forge()][$reason] ?? 0) + 1;
            if (\in_array($reason, [NotCheckedRule::FETCH_FAILED, NotCheckedRule::RATE_LIMIT], true)) {
                $failedKeys[$reason][] = $repo->key();
            }
            if ($reason === NotCheckedRule::BUDGET) {
                self::assertArrayHasKey(RunNote::REPOSITORY_ACTIVITY_NOT_CHECKED, $byCode, $what);
            }
            if ($reason === NotCheckedRule::OFFLINE) {
                self::assertArrayHasKey(RunNote::OFFLINE, $byCode, $what);
            }
        }
        foreach ($byCode[RunNote::REPOSITORY_ACTIVITY_ANONYMOUS_CAP] ?? [] as $cap) {
            $data = $cap->data();
            $reasons = $reasonsByForge[JsonPath::stringAt($data, ['forge_id'])] ?? [];
            self::assertSame($reasons[NotCheckedRule::NO_TOKEN] ?? 0, $data['skipped_no_token'], $what.' '.$cap->text());
            self::assertSame($reasons[NotCheckedRule::RATE_BUDGET] ?? 0, $data['skipped_budget'], $what.' '.$cap->text());
        }
        $unreachable = self::named($byCode[RunNote::REPOSITORY_ACTIVITY_UNREACHABLE] ?? []);
        foreach ($failedKeys[NotCheckedRule::FETCH_FAILED] ?? [] as $key) {
            self::assertContains($key, $unreachable, $what.': a package whose fetch failed is a repository the unreachable note names');
        }
        $limited = array_merge(self::named($byCode[RunNote::REPOSITORY_ACTIVITY_RATE_LIMITED] ?? []), self::named($byCode[RunNote::REPOSITORY_ACTIVITY_NOT_FOUND] ?? []));
        foreach ($failedKeys[NotCheckedRule::RATE_LIMIT] ?? [] as $key) {
            self::assertContains($key, $limited, $what.': a rate-limited package is named by the rate-limit or the not-found note');
        }
    }

    /**
     * Every repository the notes name, as `host/repo`, the key a package's repository has.
     *
     * @param list<RunNote> $notes
     *
     * @return list<string>
     */
    private static function named(array $notes): array
    {
        $keys = [];
        foreach ($notes as $note) {
            foreach (array_keys(JsonPath::arrayAt($note->data(), ['repositories'])) as $at) {
                $keys[] = JsonPath::stringAt($note->data(), ['repositories', $at, 'host']).'/'.JsonPath::stringAt($note->data(), ['repositories', $at, 'repo']);
            }
        }

        return $keys;
    }

    private function analyse(FixtureRepositoryServer $server, string $dir, bool $token, bool $offline, bool $budget): Analysis
    {
        $clock = Clock::fixed(self::NOW);
        $auth = ForgeAuth::withTokens(new Tokens($token ? 'recorded' : null, null));
        $deadline = $budget ? Deadline::inSeconds(0.0, static fn (): float => 0.0) : null;
        $analyzer = new Analyzer(
            new RepositoryMetadataLoader($server->repositories(), $clock, $offline, $deadline),
            new ActivityClient(new RecordedHttpClient(self::FIXTURES.'http/github'), $auth),
            new ActivityFetchPlanner($auth, 3),
            new RepoLocator(),
            BuiltinAllowlist::load(),
            SignalSet::default($clock, new Thresholds(), '8.4', PhpReleaseDates::load()),
            new VerdictEngine(),
            $clock,
            $offline,
            new RepositoryAdvisoryLoader($server->repositories(), $offline, $deadline)
        );
        if ($deadline !== null) {
            $analyzer = $analyzer->withDeadline($deadline);
        }
        $lock = LockFile::fromFile(self::FIXTURES.$dir.'/composer.lock');

        return $analyzer->analyzeWithFacts($lock->packages(false), $lock, ProjectConfig::fromFile(self::FIXTURES.$dir.'/composer.json'), false);
    }
}
