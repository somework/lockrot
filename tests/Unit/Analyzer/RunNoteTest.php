<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Composer\Downloader\TransportException;
use Lockrot\Analyzer\RunNote;
use Lockrot\Analyzer\UncoveredAdvisories;
use Lockrot\Data\Forge\LocatedRepository;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Repository\MetadataLoaderInterface;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** This test's strings are literals, so a template that changes in RunNote fails here and not in the output of every format. */
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
            'security advisories not checked (offline): no finding is graded for an advisory, and each next step\'s score after the move assumes its versions carry none',
            false,
            ['reason' => 'offline', 'composer_repositories_checked' => 0],
        ];
        yield 'advisories on an old Composer' => [
            static fn (): RunNote => RunNote::advisoriesNotChecked(RunNote::ADVISORIES_COMPOSER_TOO_OLD, 0),
            'advisories_not_checked',
            'security advisories not checked (needs Composer 2.4 or newer): no finding is graded for an advisory, and each next step\'s score after the move assumes its versions carry none',
            false,
            ['reason' => 'composer_too_old', 'composer_repositories_checked' => 0],
        ];
        yield 'advisories past the budget' => [
            static fn (): RunNote => RunNote::advisoriesNotChecked(RunNote::INSTALL_TIME_BUDGET, 2),
            'advisories_not_checked',
            'security advisories not checked (install-time budget spent): no finding is graded for an advisory, and each next step\'s score after the move assumes its versions carry none',
            false,
            ['reason' => 'install_time_budget', 'composer_repositories_checked' => 2],
        ];
        yield 'advisories off by the audit mode' => [
            static fn (): RunNote => RunNote::advisoriesDisabledByPolicy('policy.advisories.audit', 'ignore'),
            'advisories_disabled_by_policy',
            "security advisories still checked: Composer's policy (policy.advisories.audit: ignore) stops Composer from warning about them",
            false,
            ['policy_key' => 'policy.advisories.audit', 'value' => 'ignore'],
        ];
        yield 'advisories off by the environment' => [
            static fn (): RunNote => RunNote::advisoriesDisabledByPolicy('COMPOSER_POLICY', false),
            'advisories_disabled_by_policy',
            "security advisories still checked: Composer's policy (COMPOSER_POLICY: false) stops Composer from warning about them",
            false,
            ['policy_key' => 'COMPOSER_POLICY', 'value' => false],
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
        yield 'the GitHub cap counts one of each in the singular' => [
            static fn (): RunNote => RunNote::repositoryActivityAnonymousCap(RepoRef::GITHUB, 1, 1, 0),
            'repository_activity_anonymous_cap',
            'GitHub token not set: repository activity checked for 1 candidate package, 1 package skipped (set GITHUB_TOKEN to check all)',
            false,
            ['forge_id' => 'github', 'checked' => 1, 'skipped_no_token' => 1, 'skipped_budget' => 0],
        ];
        yield 'the Bitbucket cap' => [
            static fn (): RunNote => RunNote::repositoryActivityAnonymousCap(RepoRef::BITBUCKET, 1, 0, 0),
            'repository_activity_anonymous_cap',
            'Bitbucket credentials not set: repository activity checked for 1 candidate package, 0 packages skipped (add bitbucket.org credentials to auth.json to check all)',
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
        yield 'one package not from a Composer repository, its advisories asked' => [
            static fn (): RunNote => RunNote::notFromComposerRepository(1, true),
            'not_from_composer_repository',
            '1 package is not from a Composer repository: its release metadata and repository activity were not read',
            false,
            ['package_count' => 1, 'advisories_asked' => true],
        ];
        yield 'several packages not from a Composer repository, their advisories asked' => [
            static fn (): RunNote => RunNote::notFromComposerRepository(4, true),
            'not_from_composer_repository',
            '4 packages are not from a Composer repository: their release metadata and repository activity were not read',
            false,
            ['package_count' => 4, 'advisories_asked' => true],
        ];
        yield 'several packages not from a Composer repository, their advisories not asked' => [
            static fn (): RunNote => RunNote::notFromComposerRepository(3, false),
            'not_from_composer_repository',
            '3 packages are not from a Composer repository: their release metadata, repository activity and advisories were not read',
            false,
            ['package_count' => 3, 'advisories_asked' => false],
        ];
        yield 'a baseline written for another target PHP' => [
            static fn (): RunNote => RunNote::baselineTargetDiffers('8.3', 'option', '8.4', 'runtime'),
            'baseline_target_differs',
            'the baseline was written for php 8.3 (--target-php) and this run targets php 8.4 (the PHP running lockrot): fix changes against the baseline are not compared',
            false,
            ['baseline_target_php' => '8.3', 'baseline_target_php_source' => 'option', 'run_target_php' => '8.4', 'run_target_php_source' => 'runtime'],
        ];
        yield 'a baseline written under another fix model' => [
            static fn (): RunNote => RunNote::baselineFixModelDiffers(2, 1),
            'baseline_fix_model_differs',
            'the baseline was written under fix model 2 and this run uses fix model 1: fix changes against the baseline are not compared',
            false,
            ['baseline_fix_model' => 2, 'run_fix_model' => 1],
        ];
        yield 'an ignore[] entry beside counted advisories' => [
            static fn (): RunNote => RunNote::ignoreDoesNotCoverAdvisories([new UncoveredAdvisories('symfony/yaml', 'symfony/*', ['A1', 'A2', 'A3'])]),
            'ignore_does_not_cover_advisories',
            "extra.lockrot.ignore[] accepts maintenance flags only, not advisories: symfony/yaml (ignore[] symfony/*): 3 counted advisories; accept them in Composer's audit policy if that is the decision",
            false,
            ['entries' => [['package' => 'symfony/yaml', 'pattern' => 'symfony/*', 'advisories' => ['A1', 'A2', 'A3']]]],
        ];
        yield 'an ignore[] pattern that is the package, beside one advisory' => [
            static fn (): RunNote => RunNote::ignoreDoesNotCoverAdvisories([new UncoveredAdvisories('acme/one', 'acme/one', ['A1'])]),
            'ignore_does_not_cover_advisories',
            "extra.lockrot.ignore[] accepts maintenance flags only, not advisories: acme/one: 1 counted advisory; accept them in Composer's audit policy if that is the decision",
            false,
            ['entries' => [['package' => 'acme/one', 'pattern' => 'acme/one', 'advisories' => ['A1']]]],
        ];
        yield 'markings that Composer\'s list ignores' => [
            static fn (): RunNote => RunNote::abandonedMarkingsIgnored('policy.abandoned', ['doctrine/cache' => ['doctrine/*']]),
            'abandoned_markings_ignored',
            "Composer's abandoned ignore list (config.policy.abandoned.ignore) covers the abandoned marking of 1 package: doctrine/cache. lockrot does not count the marking; an archived repository and the release and push dates still count.",
            false,
            ['by' => 'policy.abandoned', 'count' => 1, 'packages' => [['package' => 'doctrine/cache', 'patterns' => ['doctrine/*']]]],
        ];
        yield 'markings that the legacy list ignores' => [
            static fn (): RunNote => RunNote::abandonedMarkingsIgnored('audit.ignore-abandoned', ['a/one' => ['a/*'], 'b/two' => ['b/two', 'b/*']]),
            'abandoned_markings_ignored',
            "Composer's abandoned ignore list (config.audit.ignore-abandoned) covers the abandoned marking of 2 packages: a/one, b/two. lockrot does not count the marking; an archived repository and the release and push dates still count.",
            false,
            ['by' => 'audit.ignore-abandoned', 'count' => 2, 'packages' => [['package' => 'a/one', 'patterns' => ['a/*']], ['package' => 'b/two', 'patterns' => ['b/two', 'b/*']]]],
        ];
        yield 'an abandoned audit that Composer ignores' => [
            static fn (): RunNote => RunNote::abandonedNotAuditedByComposer('policy.abandoned.audit', 'ignore'),
            'abandoned_not_audited_by_composer',
            "Composer's audit is set not to report abandoned packages (policy.abandoned.audit: ignore); lockrot still counts the abandoned marking. To stop counting it for one package, list the package in config.policy.abandoned.ignore.",
            false,
            ['policy_key' => 'policy.abandoned.audit', 'value' => 'ignore'],
        ];
        yield 'an abandoned list that Composer disables' => [
            static fn (): RunNote => RunNote::abandonedNotAuditedByComposer('policy', false),
            'abandoned_not_audited_by_composer',
            "Composer's audit is set not to report abandoned packages (policy: false); lockrot still counts the abandoned marking. To stop counting it for one package, list the package in config.policy.abandoned.ignore.",
            false,
            ['policy_key' => 'policy', 'value' => false],
        ];
        yield 'an abandoned list that Composer rejects' => [
            static fn (): RunNote => RunNote::abandonedIgnoreUnreadable('Invalid constraint "nope"'),
            'abandoned_ignore_unreadable',
            "Composer rejected the project's abandoned ignore list (Invalid constraint \"nope\"), so every abandoned marking counts.",
            false,
            ['message' => 'Invalid constraint "nope"'],
        ];
        yield 'one lock entry whose name is no package name' => [
            static fn (): RunNote => RunNote::packageNameInvalid(['--with-all-dependencies']),
            'package_name_invalid',
            'lock: lockrot skips 1 entry whose name is no Composer package name',
            false,
            ['packages' => ['--with-all-dependencies']],
        ];
        yield 'two lock entries whose names are no package names' => [
            static fn (): RunNote => RunNote::packageNameInvalid(['-x', 'a b']),
            'package_name_invalid',
            'lock: lockrot skips 2 entries whose name is no Composer package name',
            false,
            ['packages' => ['-x', 'a b']],
        ];
        yield 'a lock that Composer refuses' => [
            static fn (): RunNote => RunNote::movesNotChecked('7.4.33', self::CONFLICT),
            'moves_not_checked',
            "moves not checked: Composer's solver refuses the lock itself at php 7.4.33, so it would refuse every move: acme/a 2.1.0 conflicts with acme/b <3.0.",
            false,
            ['reason' => 'baseline_refused', 'php' => '7.4.33', 'problem' => self::CONFLICT],
        ];
        yield 'update now without one package' => [
            static fn (): RunNote => RunNote::updateNowReduced(['acme/b'], self::CONFLICT),
            'update_now_reduced',
            "update now: the command leaves out acme/b, because Composer's solver refuses it together with the others: acme/a 2.1.0 conflicts with acme/b <3.0.",
            false,
            ['left_out' => ['acme/b'], 'problem' => self::CONFLICT],
        ];
        yield 'update now without two packages' => [
            static fn (): RunNote => RunNote::updateNowReduced(['acme/b', 'acme/c'], self::CONFLICT),
            'update_now_reduced',
            "update now: the command leaves out acme/b, acme/c, because Composer's solver refuses them together with the others: acme/a 2.1.0 conflicts with acme/b <3.0.",
            false,
            ['left_out' => ['acme/b', 'acme/c'], 'problem' => self::CONFLICT],
        ];
        yield 'update now refused' => [
            static fn (): RunNote => RunNote::updateNowRefused(self::CONFLICT),
            'update_now_refused',
            "update now: no command, because Composer's solver refuses these packages together; run each package's own command: acme/a 2.1.0 conflicts with acme/b <3.0.",
            false,
            ['problem' => self::CONFLICT],
        ];
        yield 'update now not checked' => [
            static fn (): RunNote => RunNote::updateNowNotChecked('install_time_budget', ['acme/a', 'acme/b']),
            'update_now_not_checked',
            "update now: not checked with Composer's solver (the install-time budget ran out), so lockrot writes no command for acme/a, acme/b",
            false,
            ['reason' => 'install_time_budget', 'packages' => ['acme/a', 'acme/b']],
        ];
        yield 'a failure of the solver' => [
            static fn (): RunNote => RunNote::moveCheckFailed('RuntimeException', 'boom'),
            'move_check_failed',
            "moves: Composer's solver failed on a request (RuntimeException: boom), so lockrot did not check that move",
            false,
            ['class' => 'RuntimeException', 'message' => 'boom'],
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
        self::assertSame($data, JsonPath::decoded($note->data()));
        self::assertContains($code, RunNote::CODES);

        $row = $note->toArray();
        self::assertSame(['code', 'text', 'docs_url', 'sets_network_failures', 'data'], array_keys($row));
        self::assertSame([$code, $text, 'https://lockrot.dev/notes/#'.$code, $setsNetworkFailures], [$row['code'], $row['text'], $row['docs_url'], $row['sets_network_failures']]);
        self::assertInstanceOf(\stdClass::class, $row['data'], 'an object in JSON, {} included, never []');
        self::assertSame(json_encode($data === [] ? new \stdClass() : $data), json_encode($row['data']));
    }

    public function testTheHostNoteCountsEachHostMostPackagesFirstAndGivesTheGitlabHint(): void
    {
        $note = RunNote::repositoryActivityHostNotSupported([
            LocatedRepository::unsupported('codeberg.org', null),
            LocatedRepository::unsupported('bitbucket.example.com', null),
            LocatedRepository::unsupported('git.drupalcode.org', null),
            LocatedRepository::unsupported('git.drupalcode.org', null),
        ]);

        self::assertSame(RunNote::REPOSITORY_ACTIVITY_HOST_NOT_SUPPORTED, $note->code());
        self::assertSame('https://lockrot.dev/notes/#repository_activity_host_not_supported', $note->docsUrl());
        self::assertFalse($note->setsNetworkFailures());
        self::assertSame(['hosts' => [
            ['host' => 'git.drupalcode.org', 'packages' => 2, 'listed_in' => null],
            ['host' => 'bitbucket.example.com', 'packages' => 1, 'listed_in' => null],
            ['host' => 'codeberg.org', 'packages' => 1, 'listed_in' => null],
        ]], $note->data(), 'most packages first, then by host');
        self::assertSame(
            "Repository activity not checked for 4 packages on hosts lockrot cannot ask: git.drupalcode.org (2), bitbucket.example.com (1), codeberg.org (1). A host that runs GitLab is asked once Composer's gitlab-domains lists it; lockrot cannot ask other forge software (Forgejo, Gitea) or a Subversion host.",
            $note->text()
        );
    }

    /** A URL can name its host as a decimal IPv4 address, which PHP would turn into an integer key. */
    public function testTheHostNoteKeepsAHostOfDigitsAString(): void
    {
        $note = RunNote::repositoryActivityHostNotSupported([LocatedRepository::unsupported('3232235777', null)]);

        self::assertSame([['host' => '3232235777', 'packages' => 1, 'listed_in' => null]], $note->data()['hosts']);
    }

    public function testTheHostNoteNamesAForgejoHostWithoutTheGitlabHint(): void
    {
        $forgejo = RunNote::repositoryActivityHostNotSupported([LocatedRepository::unsupported('codeberg.org', LocatedRepository::FORGEJO_DOMAINS)]);

        self::assertSame([['host' => 'codeberg.org', 'packages' => 1, 'listed_in' => 'forgejo-domains']], $forgejo->data()['hosts']);
        self::assertSame('Repository activity not checked for 1 package on hosts lockrot cannot ask: codeberg.org (1). lockrot does not ask Forgejo hosts (codeberg.org), and listing them in gitlab-domains does not help.', $forgejo->text());
    }

    public function testTheHostNoteNamesAGithubEnterpriseHostWithoutTheGitlabHint(): void
    {
        $enterprise = RunNote::repositoryActivityHostNotSupported([LocatedRepository::unsupported('ghe.example.com', LocatedRepository::GITHUB_DOMAINS)]);
        $both = RunNote::repositoryActivityHostNotSupported([LocatedRepository::unsupported('ghe.example.com', LocatedRepository::GITHUB_DOMAINS), LocatedRepository::unsupported('codeberg.org', null)]);

        self::assertSame([['host' => 'ghe.example.com', 'packages' => 1, 'listed_in' => 'github-domains']], $enterprise->data()['hosts']);
        self::assertSame('Repository activity not checked for 1 package on hosts lockrot cannot ask: ghe.example.com (1). lockrot does not ask GitHub Enterprise hosts (ghe.example.com).', $enterprise->text());
        self::assertStringContainsString("gitlab-domains lists it; lockrot cannot ask other forge software (Forgejo, Gitea) or a Subversion host. lockrot does not ask GitHub Enterprise hosts (ghe.example.com).", $both->text(), 'one hint per kind present');
    }

    public function testTheCodesAreListedInTheOrderTheAnalyzersPassesRun(): void
    {
        self::assertSame([
            'package_name_invalid',
            'offline',
            'metadata_unavailable',
            'monorepo_parent_unavailable',
            'advisory_ignore_unreadable',
            'advisories_disabled_by_policy',
            'advisories_unavailable',
            'advisories_not_checked',
            'repository_activity_not_checked',
            'repository_activity_anonymous_cap',
            'repository_activity_rate_limited',
            'repository_activity_unreachable',
            'repository_activity_not_found',
            'repository_activity_host_not_supported',
            'not_from_composer_repository',
            'baseline_target_differs',
            'baseline_fix_model_differs',
            'ignore_does_not_cover_advisories',
            'abandoned_markings_ignored',
            'abandoned_not_audited_by_composer',
            'abandoned_ignore_unreadable',
            'moves_not_checked',
            'update_now_reduced',
            'update_now_refused',
            'update_now_not_checked',
            'move_check_failed',
        ], RunNote::CODES);
        self::assertSame(['offline', 'composer_too_old', 'install_time_budget'], RunNote::ADVISORIES_NOT_CHECKED_REASONS);
        self::assertSame(['install_time_budget'], RunNote::REPOSITORY_ACTIVITY_NOT_CHECKED_REASONS);
    }

    private const CONFLICT = "Problem 1\n    - acme/a 2.1.0 conflicts with acme/b <3.0.";

    private const DRUPAL_PROBLEM = "Problem 1\n"
        ."    - drupal/core-recommended is locked to version 9.3.14 and an update of this package was not requested.\n"
        ."    - drupal/core-recommended 9.3.14 requires laminas/laminas-diactoros ~2.8.0 -> satisfiable by laminas/laminas-diactoros[2.8.0].\n"
        ."    - laminas/laminas-diactoros 2.8.0 requires php ^7.3 || ~8.0.0 || ~8.1.0 -> your php version (8.3.99; overridden via config.platform) does not satisfy that requirement.\n"
        ."  Problem 2\n"
        ."    - acme/other is locked to version 1.0.0 and an update of this package was not requested.";

    /** The drupal-site lock at --target-php=8.3: the rule that Composer cannot satisfy is the last line, and its reply after " -> " only repeats the php. */
    public function testARefusedSolveNamesTheUnsatisfiedRuleOfTheFirstProblemUpToItsArrow(): void
    {
        $note = RunNote::movesNotChecked('8.3.99', self::DRUPAL_PROBLEM);

        self::assertSame(
            "moves not checked: Composer's solver refuses the lock itself at php 8.3.99, so it would refuse every move: laminas/laminas-diactoros 2.8.0 requires php ^7.3 || ~8.0.0 || ~8.1.0.",
            $note->text()
        );
        self::assertSame(self::DRUPAL_PROBLEM, $note->data()['problem']);
    }

    private const WALLABAG_PROBLEM = "Problem 1\n"
        ."    - lcobucci/jwt is locked to version 4.3.0 and an update of this package was not requested.\n"
        ."    - lcobucci/clock 3.3.1 requires php ~8.2.0 || ~8.3.0 || ~8.4.0 -> your php version (8.5.99; overridden via config.platform) does not satisfy that requirement.\n"
        ."    - lcobucci/jwt 4.3.0 requires lcobucci/clock ^2.0 || ^3.0 -> satisfiable by lcobucci/clock[3.3.1].";

    /** The wallabag master lock at php 8.5: Composer lists the unsatisfied rule before a rule that it satisfies. */
    public function testARefusedSolveNamesTheUnsatisfiedRuleWhenASatisfiedRuleFollowsIt(): void
    {
        $note = RunNote::movesNotChecked('8.5.99', self::WALLABAG_PROBLEM);

        self::assertSame(
            "moves not checked: Composer's solver refuses the lock itself at php 8.5.99, so it would refuse every move: lcobucci/clock 3.3.1 requires php ~8.2.0 || ~8.3.0 || ~8.4.0.",
            $note->text()
        );
        self::assertSame(self::WALLABAG_PROBLEM, $note->data()['problem']);
    }

    /** With no unsatisfied " -> " rule, the cause is the last rule line, cut before its arrow. */
    public function testAProblemWithOnlySatisfiedRulesNamesItsLastRule(): void
    {
        $problem = "Problem 1\n"
            ."    - acme/a is locked to version 1.0.0 and an update of this package was not requested.\n"
            ."    - acme/a 1.0.0 requires acme/b ^2.0 -> satisfiable by acme/b[2.0.0].\n"
            ."    - acme/b 2.0.0 conflicts with acme/c 1.0.0.\n"
            ."    - acme/c 1.0.0 requires acme/d ^1.0 -> satisfiable by acme/d[1.0.0].";

        self::assertStringEndsWith('own command: acme/c 1.0.0 requires acme/d ^1.0.', RunNote::updateNowRefused($problem)->text());
    }

    public function testTheUpdateNowNotesQuoteTheSameCauseOfARefusal(): void
    {
        self::assertStringEndsWith(': laminas/laminas-diactoros 2.8.0 requires php ^7.3 || ~8.0.0 || ~8.1.0.', RunNote::updateNowRefused(self::DRUPAL_PROBLEM)->text());
        self::assertStringEndsWith(': laminas/laminas-diactoros 2.8.0 requires php ^7.3 || ~8.0.0 || ~8.1.0.', RunNote::updateNowReduced(['acme/b'], self::DRUPAL_PROBLEM)->text());
    }

    /** A problem of one line keeps that line whole, and a text with no list marker is quoted as Composer wrote it. */
    public function testAProblemWithoutAnArrowOrAMarkerKeepsItsLine(): void
    {
        self::assertStringEndsWith(': acme/a 2.1.0 conflicts with acme/b <3.0.', RunNote::updateNowRefused("Problem 1\n    - acme/a 2.1.0 conflicts with acme/b <3.0.")->text());
        self::assertStringEndsWith(': Your requirements could not be resolved.', RunNote::updateNowRefused('Your requirements could not be resolved.')->text());
    }

    /** Only a line that is `Problem <n>` whole ends the first problem, and only a line that starts with the list marker is a rule. */
    public function testTheCauseIsTheUnsatisfiedMarkedLineBeforeTheNextProblemLine(): void
    {
        $problem = "Problem 1\n"
            ."    - acme/a 2.1.0 requires acme/b ^3.0 -> satisfiable by acme/b[3.0.0].\n"
            ."    the rule below - and this line -> name no rule\n"
            ."    See Problem 9\n"
            ."    Problem 2 follows\n"
            ."    - acme/b 3.0.0 requires php >=8.2 -> your php version (7.4) does not satisfy that requirement.\n"
            ."    the rule above - and this line - name no rule\n"
            ."  Problem 2\n"
            ."    - acme/c 1.0.0 conflicts with acme/a.";

        self::assertStringEndsWith(': acme/b 3.0.0 requires php >=8.2.', RunNote::updateNowRefused($problem)->text());
    }

    public function testAProblemWithoutAMarkerIsQuotedWithoutItsOuterWhitespace(): void
    {
        self::assertStringEndsWith('own command: Your requirements could not be resolved.', RunNote::updateNowRefused("  Your requirements could not be resolved.\n")->text());
    }

    /** Every constructor refuses what no run can produce: a note about nothing, or a reason it does not know. */
    public function testANoteAboutNothingIsRefused(): void
    {
        $refused = [
            'no failed package' => static fn () => RunNote::metadataUnavailable([]),
            'no package outside a repository' => static fn () => RunNote::notFromComposerRepository(0, true),
            'no marking ignored' => static fn () => RunNote::abandonedMarkingsIgnored('policy.abandoned', []),
            'no unreachable repository' => static fn () => RunNote::repositoryActivityUnreachable(RepoRef::GITHUB, []),
            'no repository not found' => static fn () => RunNote::repositoryActivityNotFound(RepoRef::GITHUB, []),
            'no host lockrot cannot ask' => static fn () => RunNote::repositoryActivityHostNotSupported([]),
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
