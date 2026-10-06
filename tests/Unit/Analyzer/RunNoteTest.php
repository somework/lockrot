<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Composer\Downloader\TransportException;
use Lockrot\Analyzer\RunNote;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The strings below are literals, so a template that changes in RunNote fails here and not in the output of every format. */
final class RunNoteTest extends TestCase
{
    private const BUDGET = 'not checked: install-time budget exhausted';

    /**
     * Each note is built in the test, not here: a provider runs before coverage is recorded, and a
     * constructor that only a provider calls reads as untested.
     *
     * @return iterable<string, array{callable(): RunNote, string, string, bool, array<string, mixed>}>
     */
    public static function notes(): iterable
    {
        $github = new RepoRef(RepoRef::GITHUB, 'github.com', 'acme/lib');
        $private = new RepoRef(RepoRef::GITHUB, 'github.com', 'acme/private');
        $selfHosted = new RepoRef(RepoRef::GITLAB, 'gitlab.example.org', 'team/app');
        $gitlab = new RepoRef(RepoRef::GITLAB, 'gitlab.com', 'x/y');
        $bitbucket = new RepoRef(RepoRef::BITBUCKET, 'bitbucket.org', 'workspace/one');

        yield 'offline' => [static fn (): RunNote => RunNote::offline(), 'offline', "offline: repository metadata served from Composer's cache", false, []];
        yield 'metadata, one reason' => [
            static fn (): RunNote => RunNote::metadataUnavailable(['vendor/a' => 'HTTP 503']),
            'metadata_unavailable',
            'Repository metadata unavailable for 1 package: HTTP 503',
            true,
            ['package_count' => 1, 'reasons' => [['reason' => 'fetch_failed', 'message' => 'HTTP 503', 'package_count' => 1]]],
        ];
        yield 'metadata, one reason in the plural' => [
            static fn (): RunNote => RunNote::metadataUnavailable(['vendor/a' => MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON, 'vendor/b' => MetadataLoaderInterface::OFFLINE_NOT_FOUND_REASON]),
            'metadata_unavailable',
            "Repository metadata unavailable for 2 packages: offline: not present in Composer's cache",
            true,
            ['package_count' => 2, 'reasons' => [['reason' => 'offline', 'message' => "offline: not present in Composer's cache", 'package_count' => 2]]],
        ];
        yield 'metadata, several reasons' => [
            static fn (): RunNote => RunNote::metadataUnavailable(['vendor/a' => self::BUDGET, 'vendor/b' => self::BUDGET, 'vendor/c' => 'connection refused', 'vendor/d' => self::BUDGET]),
            'metadata_unavailable',
            'Repository metadata unavailable for 4 packages: not checked: install-time budget exhausted (3); connection refused (1)',
            true,
            ['package_count' => 4, 'reasons' => [
                ['reason' => 'install_time_budget', 'message' => self::BUDGET, 'package_count' => 3],
                ['reason' => 'fetch_failed', 'message' => 'connection refused', 'package_count' => 1],
            ]],
        ];
        yield 'metadata, a message PHP would read as a number' => [
            static fn (): RunNote => RunNote::metadataUnavailable(['vendor/a' => '503', 'vendor/b' => '503']),
            'metadata_unavailable',
            'Repository metadata unavailable for 2 packages: 503',
            true,
            ['package_count' => 2, 'reasons' => [['reason' => 'fetch_failed', 'message' => '503', 'package_count' => 2]]],
        ];
        yield 'metadata, no versions' => [
            static fn (): RunNote => RunNote::metadataUnavailable(['vendor/a' => MetadataLoaderInterface::NO_VERSIONS_REASON]),
            'metadata_unavailable',
            'Repository metadata unavailable for 1 package: repository listed the package but returned no versions',
            true,
            ['package_count' => 1, 'reasons' => [['reason' => 'no_versions', 'message' => 'repository listed the package but returned no versions', 'package_count' => 1]]],
        ];
        yield 'a monorepo parent' => [
            static fn (): RunNote => RunNote::monorepoParentUnavailable('laravel/framework', 'curl error 6: Could not resolve host'),
            'monorepo_parent_unavailable',
            'Repository metadata unavailable for laravel/framework, which dates the packages split out of it: curl error 6: Could not resolve host',
            true,
            ['parent' => 'laravel/framework', 'reason' => 'fetch_failed', 'message' => 'curl error 6: Could not resolve host'],
        ];
        yield 'the ignore list' => [
            static fn (): RunNote => RunNote::advisoryIgnoreUnreadable('Unknown key "licenses"'),
            'advisory_ignore_unreadable',
            'Composer\'s advisory ignore list not read (Unknown key "licenses"); every advisory counts',
            false,
            ['message' => 'Unknown key "licenses"'],
        ];
        yield 'the ignore list, no message' => [
            static fn (): RunNote => RunNote::advisoryIgnoreUnreadable(''),
            'advisory_ignore_unreadable',
            "Composer's advisory ignore list not read (); every advisory counts",
            false,
            ['message' => ''],
        ];
        yield 'advisories, a transport failure' => [
            static fn (): RunNote => RunNote::advisoriesUnavailable('composer repo (https://repo.example.com)', new TransportException("HTTP 503\nthe body")),
            'advisories_unavailable',
            'security advisories unavailable from composer repo (https://repo.example.com): HTTP 503',
            true,
            ['composer_repository' => 'composer repo (https://repo.example.com)', 'message' => 'HTTP 503'],
        ];
        yield 'advisories from a repository whose url carries a token' => [
            static fn (): RunNote => RunNote::advisoriesUnavailable(
                'composer repo (https://glpat-abcdefghij@repo.example.com/?token=t0k3n)',
                new TransportException('The "https://glpat-abcdefghij@repo.example.com/api/security-advisories/?token=t0k3n" file could not be downloaded (HTTP/2 401 )')
            ),
            'advisories_unavailable',
            'security advisories unavailable from composer repo (https://repo.example.com/): The "https://repo.example.com/api/security-advisories/" file could not be downloaded (HTTP/2 401 )',
            true,
            ['composer_repository' => 'composer repo (https://repo.example.com/)', 'message' => 'The "https://repo.example.com/api/security-advisories/" file could not be downloaded (HTTP/2 401 )'],
        ];
        yield 'advisories from a repository on the machine' => [
            static fn (): RunNote => RunNote::advisoriesUnavailable('composer repo (file:///Users/Igor Pinchuk/client-x/satis)', new \LogicException('boom')),
            'advisories_unavailable',
            'security advisories unavailable from composer repo (file://.../satis): boom',
            false,
            ['composer_repository' => 'composer repo (file://.../satis)', 'message' => 'boom'],
        ];
        yield 'advisories, another failure with no message' => [
            static fn (): RunNote => RunNote::advisoriesUnavailable('packagist.org', new \LogicException('')),
            'advisories_unavailable',
            'security advisories unavailable from packagist.org: LogicException',
            false,
            ['composer_repository' => 'packagist.org', 'message' => 'LogicException'],
        ];
        yield 'advisories offline' => [
            static fn (): RunNote => RunNote::advisoriesNotChecked(RunNote::ADVISORIES_OFFLINE, 0),
            'advisories_not_checked',
            'offline: security advisories not checked; a priority they would raise stays one step lower',
            false,
            ['reason' => 'offline', 'composer_repositories_checked' => 0],
        ];
        yield 'advisories on an old Composer' => [
            static fn (): RunNote => RunNote::advisoriesNotChecked(RunNote::ADVISORIES_COMPOSER_TOO_OLD, 0),
            'advisories_not_checked',
            'security advisories not checked (needs Composer 2.4 or newer); a priority they would raise stays one step lower',
            false,
            ['reason' => 'composer_too_old', 'composer_repositories_checked' => 0],
        ];
        yield 'advisories past the budget' => [
            static fn (): RunNote => RunNote::advisoriesNotChecked(RunNote::INSTALL_TIME_BUDGET, 2),
            'advisories_not_checked',
            'security advisories not checked: install-time budget exhausted; a priority they would raise stays one step lower',
            false,
            ['reason' => 'install_time_budget', 'composer_repositories_checked' => 2],
        ];
        yield 'activity past the budget' => [
            static fn (): RunNote => RunNote::repositoryActivityNotChecked(),
            'repository_activity_not_checked',
            'repository activity not checked: install-time budget exhausted',
            false,
            ['reason' => 'install_time_budget'],
        ];
        yield 'the GitHub cap' => [
            static fn (): RunNote => RunNote::repositoryActivityAnonymousCap(RepoRef::GITHUB, 11, 80, 9),
            'repository_activity_anonymous_cap',
            'GitHub token not set: repository activity checked for 11 candidate packages, 89 packages skipped (set GITHUB_TOKEN to check all)',
            false,
            ['forge_id' => 'github', 'checked' => 11, 'skipped_no_token' => 80, 'skipped_budget' => 9],
        ];
        yield 'the Bitbucket cap' => [
            static fn (): RunNote => RunNote::repositoryActivityAnonymousCap(RepoRef::BITBUCKET, 1, 0, 0),
            'repository_activity_anonymous_cap',
            'Bitbucket credentials not set: repository activity checked for 1 candidate packages, 0 packages skipped (add bitbucket.org credentials to auth.json to check all)',
            false,
            ['forge_id' => 'bitbucket', 'checked' => 1, 'skipped_no_token' => 0, 'skipped_budget' => 0],
        ];
        yield 'rate limited' => [
            static fn (): RunNote => RunNote::repositoryActivityRateLimited(RepoRef::GITHUB, [[$github, 'HTTP 403'], [$private, 'HTTP 429']]),
            'repository_activity_rate_limited',
            'GitHub API rate limit reached; repository activity missing for 2 repositories',
            true,
            ['forge_id' => 'github', 'repositories' => [
                ['host' => 'github.com', 'repo' => 'acme/lib', 'message' => 'HTTP 403'],
                ['host' => 'github.com', 'repo' => 'acme/private', 'message' => 'HTTP 429'],
            ]],
        ];
        yield 'unreachable, the first reason printed' => [
            static fn (): RunNote => RunNote::repositoryActivityUnreachable(RepoRef::GITLAB, [[$selfHosted, 'curl error 7: Failed to connect'], [$gitlab, 'HTTP 502']]),
            'repository_activity_unreachable',
            'GitLab unreachable for 2 repositories: curl error 7: Failed to connect',
            true,
            ['forge_id' => 'gitlab', 'repositories' => [
                ['host' => 'gitlab.example.org', 'repo' => 'team/app', 'message' => 'curl error 7: Failed to connect'],
                ['host' => 'gitlab.com', 'repo' => 'x/y', 'message' => 'HTTP 502'],
            ]],
        ];
        yield 'not found' => [
            static fn (): RunNote => RunNote::repositoryActivityNotFound(RepoRef::BITBUCKET, [$bitbucket, new RepoRef(RepoRef::BITBUCKET, 'bitbucket.org', 'workspace/two')]),
            'repository_activity_not_found',
            'Bitbucket did not answer for 2 repositories (private, renamed or removed); repository activity missing',
            false,
            ['forge_id' => 'bitbucket', 'repositories' => [['host' => 'bitbucket.org', 'repo' => 'workspace/one'], ['host' => 'bitbucket.org', 'repo' => 'workspace/two']]],
        ];
        yield 'one package not from a Composer repository' => [
            static fn (): RunNote => RunNote::notFromComposerRepository(1),
            'not_from_composer_repository',
            '1 package is not from a Composer repository and was not checked',
            false,
            ['package_count' => 1],
        ];
        yield 'several packages not from a Composer repository' => [
            static fn (): RunNote => RunNote::notFromComposerRepository(2),
            'not_from_composer_repository',
            '2 packages are not from a Composer repository and were not checked',
            false,
            ['package_count' => 2],
        ];
    }

    /**
     * @param callable(): RunNote   $build
     * @param array<string, mixed> $data
     *
     * @dataProvider notes
     */
    #[DataProvider('notes')]
    public function testANoteSaysInTextWhatItsFieldsSay(callable $build, string $code, string $text, bool $setsNetworkFailures, array $data): void
    {
        $note = $build();
        self::assertSame($code, $note->code());
        self::assertSame($text, $note->text());
        self::assertSame('https://lockrot.dev/notes/#'.$code, $note->docsUrl());
        self::assertSame($setsNetworkFailures, $note->setsNetworkFailures());
        self::assertSame($data, $note->data());
        self::assertContains($code, RunNote::CODES);

        $row = $note->toArray();
        self::assertSame(['code', 'text', 'docs_url', 'sets_network_failures', 'data'], array_keys($row));
        self::assertSame([$code, $text, 'https://lockrot.dev/notes/#'.$code, $setsNetworkFailures], [$row['code'], $row['text'], $row['docs_url'], $row['sets_network_failures']]);
        self::assertInstanceOf(\stdClass::class, $row['data'], 'an object in JSON, {} included, never []');
        self::assertSame(json_encode($data === [] ? new \stdClass() : $data), json_encode($row['data']));
    }

    public function testTheCodesAreListedInTheOrderTheAnalyzersPassesRun(): void
    {
        self::assertSame([
            'offline',
            'metadata_unavailable',
            'monorepo_parent_unavailable',
            'advisory_ignore_unreadable',
            'advisories_unavailable',
            'advisories_not_checked',
            'repository_activity_not_checked',
            'repository_activity_anonymous_cap',
            'repository_activity_rate_limited',
            'repository_activity_unreachable',
            'repository_activity_not_found',
            'not_from_composer_repository',
        ], RunNote::CODES);
        self::assertSame(['offline', 'composer_too_old', 'install_time_budget'], RunNote::ADVISORIES_NOT_CHECKED_REASONS);
        self::assertSame(['install_time_budget'], RunNote::REPOSITORY_ACTIVITY_NOT_CHECKED_REASONS);
    }

    /** Every constructor refuses what no run can produce: a note about nothing, or a reason it does not know. */
    public function testANoteAboutNothingIsRefused(): void
    {
        $refused = [
            'no failed package' => static fn () => RunNote::metadataUnavailable([]),
            'no package outside a repository' => static fn () => RunNote::notFromComposerRepository(0),
            'no unreachable repository' => static fn () => RunNote::repositoryActivityUnreachable(RepoRef::GITHUB, []),
            'no repository not found' => static fn () => RunNote::repositoryActivityNotFound(RepoRef::GITHUB, []),
            'a reason advisories are not checked for that is not one' => static fn () => RunNote::advisoriesNotChecked('tired', 0),
            'a checked count below zero' => static fn () => RunNote::advisoriesNotChecked(RunNote::INSTALL_TIME_BUDGET, -1),
            'a cap on GitLab, which has none' => static fn () => RunNote::repositoryActivityAnonymousCap(RepoRef::GITLAB, 1, 0, 0),
        ];
        foreach ($refused as $what => $build) {
            try {
                $build();
                self::fail($what.' was accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage(), $what);
            }
        }
    }
}
