<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Composer\Downloader\TransportException;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Repository\MetadataFailure;
use Lockrot\Data\Repository\RepositoryUrl;
use Lockrot\Signal\Rule\NotCheckedRule;

/**
 * Each code has one named constructor, which derives the sentence from the facts, so the text and
 * the `data` cannot disagree. The same constructor decides whether the note sets
 * `network_failures`. Docs: docs/notes.md.
 *
 * @internal
 */
final class RunNote
{
    public const OFFLINE = 'offline';
    public const METADATA_UNAVAILABLE = 'metadata_unavailable';
    public const MONOREPO_PARENT_UNAVAILABLE = 'monorepo_parent_unavailable';
    public const ADVISORY_IGNORE_UNREADABLE = 'advisory_ignore_unreadable';
    public const ADVISORIES_UNAVAILABLE = 'advisories_unavailable';
    public const ADVISORIES_NOT_CHECKED = 'advisories_not_checked';
    public const REPOSITORY_ACTIVITY_NOT_CHECKED = 'repository_activity_not_checked';
    public const REPOSITORY_ACTIVITY_ANONYMOUS_CAP = 'repository_activity_anonymous_cap';
    public const REPOSITORY_ACTIVITY_RATE_LIMITED = 'repository_activity_rate_limited';
    public const REPOSITORY_ACTIVITY_UNREACHABLE = 'repository_activity_unreachable';
    public const REPOSITORY_ACTIVITY_NOT_FOUND = 'repository_activity_not_found';
    public const NOT_FROM_COMPOSER_REPOSITORY = 'not_from_composer_repository';
    /**
     * The codes that lockrot writes, as the schemas list them in `x-known-values`, in the order of
     * the analyzer's passes. In a report, the notes of two repository hosts can interleave.
     */
    public const CODES = [
        self::OFFLINE,
        self::METADATA_UNAVAILABLE,
        self::MONOREPO_PARENT_UNAVAILABLE,
        self::ADVISORY_IGNORE_UNREADABLE,
        self::ADVISORIES_UNAVAILABLE,
        self::ADVISORIES_NOT_CHECKED,
        self::REPOSITORY_ACTIVITY_NOT_CHECKED,
        self::REPOSITORY_ACTIVITY_ANONYMOUS_CAP,
        self::REPOSITORY_ACTIVITY_RATE_LIMITED,
        self::REPOSITORY_ACTIVITY_UNREACHABLE,
        self::REPOSITORY_ACTIVITY_NOT_FOUND,
        self::NOT_FROM_COMPOSER_REPOSITORY,
    ];

    /** Each code has a section on the site at its own id. Never move one without a stub. */
    public const DOCS_URL = 'https://lockrot.dev/notes/#';

    public const ADVISORIES_OFFLINE = 'offline';
    public const ADVISORIES_COMPOSER_TOO_OLD = 'composer_too_old';
    public const INSTALL_TIME_BUDGET = 'install_time_budget';
    public const ADVISORIES_NOT_CHECKED_REASONS = [self::ADVISORIES_OFFLINE, self::ADVISORIES_COMPOSER_TOO_OLD, self::INSTALL_TIME_BUDGET];
    /** The same reason strings as S10 carries on the findings that the note concerns. */
    public const REPOSITORY_ACTIVITY_NOT_CHECKED_REASONS = [NotCheckedRule::BUDGET];

    /**
     * Each text states the cost of the missing check: a priority that S9 raises stays one
     * step lower, and `--fail-on` decides on it.
     */
    private const ADVISORIES_NOT_CHECKED_TEXT = [
        self::ADVISORIES_OFFLINE => 'offline: security advisories not checked; a priority they would raise stays one step lower',
        self::ADVISORIES_COMPOSER_TOO_OLD => 'security advisories not checked (needs Composer 2.4 or newer); a priority they would raise stays one step lower',
        self::INSTALL_TIME_BUDGET => 'security advisories not checked: install-time budget exhausted; a priority they would raise stays one step lower',
    ];

    /** GitLab's anonymous limit needs no cap, so it has no sentence. */
    private const ANONYMOUS_CAP_TEXT = [
        RepoRef::GITHUB => 'GitHub token not set: repository activity checked for %d candidate packages, %d packages skipped (set GITHUB_TOKEN to check all)',
        RepoRef::BITBUCKET => 'Bitbucket credentials not set: repository activity checked for %d candidate packages, %d packages skipped (add bitbucket.org credentials to auth.json to check all)',
    ];

    private string $code;
    private string $text;
    private ?string $docsUrl;
    private bool $setsNetworkFailures;
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    private function __construct(string $code, string $text, ?string $docsUrl, bool $setsNetworkFailures, array $data)
    {
        $this->code = $code;
        $this->text = $text;
        $this->docsUrl = $docsUrl;
        $this->setsNetworkFailures = $setsNetworkFailures;
        $this->data = $data;
    }

    /** @param array<string, mixed> $data */
    private static function of(string $code, string $text, bool $setsNetworkFailures, array $data = []): self
    {
        return new self($code, $text, self::DOCS_URL.$code, $setsNetworkFailures, $data);
    }

    public static function offline(): self
    {
        return self::of(self::OFFLINE, "offline: repository metadata served from Composer's cache", false);
    }

    /**
     * One count per distinct message, in package order. With a single message, the text omits the
     * per-message count.
     *
     * @param array<string, string> $failed package name => the message {@see \Lockrot\Data\Repository\MetadataBatch::failed()} gives
     */
    public static function metadataUnavailable(array $failed): self
    {
        if ($failed === []) {
            throw new \InvalidArgumentException('A metadata note names at least one package.');
        }
        $countByMessage = [];
        foreach ($failed as $message) {
            $countByMessage[$message] = ($countByMessage[$message] ?? 0) + 1;
        }
        $reasons = [];
        $parts = [];
        foreach ($countByMessage as $message => $count) {
            $message = (string) $message;
            $reasons[] = ['reason' => MetadataFailure::reason($message), 'message' => $message, 'package_count' => $count];
            $parts[] = \sprintf('%s (%d)', $message, $count);
        }
        $label = \count($failed) === 1 ? '1 package' : \count($failed).' packages';
        $text = \sprintf('Repository metadata unavailable for %s: %s', $label, \count($reasons) === 1 ? $reasons[0]['message'] : implode('; ', $parts));

        return self::of(self::METADATA_UNAVAILABLE, $text, true, ['package_count' => \count($failed), 'reasons' => $reasons]);
    }

    /** @param string $message what the repositories said about the parent, as {@see metadataUnavailable()} takes it */
    public static function monorepoParentUnavailable(string $parent, string $message): self
    {
        return self::of(
            self::MONOREPO_PARENT_UNAVAILABLE,
            \sprintf('Repository metadata unavailable for %s, which dates the packages split out of it: %s', $parent, $message),
            true,
            ['parent' => $parent, 'reason' => MetadataFailure::reason($message), 'message' => $message]
        );
    }

    /** @param string $message the first line of what Composer rejected the policy with, possibly empty */
    public static function advisoryIgnoreUnreadable(string $message): self
    {
        return self::of(self::ADVISORY_IGNORE_UNREADABLE, \sprintf("Composer's advisory ignore list not read (%s); every advisory counts", $message), false, ['message' => $message]);
    }

    /**
     * One line of the message, or the class when there is none: Composer's message for a partial
     * record runs to a `var_export()` dump of it. Only an unreachable repository is a network
     * failure, not one that answered with something unreadable. Composer's repository URL can keep
     * a token, a login and a `?token=` on Composer 2.4 to 2.9: {@see RepositoryUrl::inText()}.
     */
    public static function advisoriesUnavailable(string $composerRepository, \Throwable $e): self
    {
        $composerRepository = RepositoryUrl::inText($composerRepository);
        $message = RepositoryUrl::inText((string) strtok($e->getMessage(), "\r\n"));
        $message = $message === '' ? \get_class($e) : $message;

        return self::of(
            self::ADVISORIES_UNAVAILABLE,
            \sprintf('security advisories unavailable from %s: %s', $composerRepository, $message),
            $e instanceof TransportException,
            ['composer_repository' => $composerRepository, 'message' => $message]
        );
    }

    /**
     * @param string $reason  one of {@see ADVISORIES_NOT_CHECKED_REASONS}
     * @param int    $checked the advisory-capable repositories asked before the check stopped,
     *                        whatever each gave: advisories, none, or a failure noted on its own
     */
    public static function advisoriesNotChecked(string $reason, int $checked): self
    {
        if (!isset(self::ADVISORIES_NOT_CHECKED_TEXT[$reason]) || $checked < 0) {
            throw new \InvalidArgumentException(\sprintf('Advisories are not checked for "%s" after %d repositories; the reasons are %s.', $reason, $checked, implode(', ', self::ADVISORIES_NOT_CHECKED_REASONS)));
        }

        return self::of(self::ADVISORIES_NOT_CHECKED, self::ADVISORIES_NOT_CHECKED_TEXT[$reason], false, ['reason' => $reason, 'composer_repositories_checked' => $checked]);
    }

    public static function repositoryActivityNotChecked(): self
    {
        return self::of(self::REPOSITORY_ACTIVITY_NOT_CHECKED, 'repository activity not checked: install-time budget exhausted', false, ['reason' => NotCheckedRule::BUDGET]);
    }

    /** The text counts both kinds of skipped package together. The data keeps them apart, as the two S10 reasons do. */
    public static function repositoryActivityAnonymousCap(string $forge, int $checked, int $skippedNoToken, int $skippedBudget): self
    {
        if (!isset(self::ANONYMOUS_CAP_TEXT[$forge])) {
            throw new \InvalidArgumentException(\sprintf('%s has no anonymous cap.', $forge));
        }

        return self::of(
            self::REPOSITORY_ACTIVITY_ANONYMOUS_CAP,
            \sprintf(self::ANONYMOUS_CAP_TEXT[$forge], $checked, $skippedNoToken + $skippedBudget),
            false,
            ['forge_id' => $forge, 'checked' => $checked, 'skipped_no_token' => $skippedNoToken, 'skipped_budget' => $skippedBudget]
        );
    }

    /**
     * The data lists each repository of the repository host that got no answer, whatever its
     * domain and cause. The sentence says only that the host rate-limited.
     *
     * @param list<array{0: RepoRef, 1: string}> $failed each repository and its message
     */
    public static function repositoryActivityRateLimited(string $forge, array $failed): self
    {
        return self::of(
            self::REPOSITORY_ACTIVITY_RATE_LIMITED,
            \sprintf('%s API rate limit reached; repository activity missing for %d repositories', RepoRef::label($forge), \count($failed)),
            true,
            ['forge_id' => $forge, 'repositories' => self::failures($failed)]
        );
    }

    /**
     * The sentence names the first message, and the data keeps every one.
     *
     * @param list<array{0: RepoRef, 1: string}> $failed each repository and its message
     */
    public static function repositoryActivityUnreachable(string $forge, array $failed): self
    {
        if ($failed === []) {
            throw new \InvalidArgumentException('An unreachable note names at least one repository.');
        }

        return self::of(
            self::REPOSITORY_ACTIVITY_UNREACHABLE,
            \sprintf('%s unreachable for %d repositories: %s', RepoRef::label($forge), \count($failed), $failed[0][1]),
            true,
            ['forge_id' => $forge, 'repositories' => self::failures($failed)]
        );
    }

    /**
     * A 404 is an answer, not a failure, but without this note a private repository looks like a
     * healthy one.
     *
     * @param list<RepoRef> $repositories
     */
    public static function repositoryActivityNotFound(string $forge, array $repositories): self
    {
        if ($repositories === []) {
            throw new \InvalidArgumentException('A not-found note names at least one repository.');
        }

        return self::of(
            self::REPOSITORY_ACTIVITY_NOT_FOUND,
            \sprintf('%s did not answer for %d repositories (private, renamed or removed); repository activity missing', RepoRef::label($forge), \count($repositories)),
            false,
            ['forge_id' => $forge, 'repositories' => array_map(static fn (RepoRef $repo): array => ['host' => $repo->host(), 'repo' => $repo->path()], $repositories)]
        );
    }

    public static function notFromComposerRepository(int $count): self
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('A note about packages outside every Composer repository counts at least one.');
        }
        $text = $count === 1
            ? '1 package is not from a Composer repository and was not checked'
            : \sprintf('%d packages are not from a Composer repository and were not checked', $count);

        return self::of(self::NOT_FROM_COMPOSER_REPOSITORY, $text, false, ['package_count' => $count]);
    }

    /**
     * @param list<array{0: RepoRef, 1: string}> $failed
     *
     * @return list<array{host: string, repo: string, message: string}>
     */
    private static function failures(array $failed): array
    {
        return array_map(static fn (array $failure): array => ['host' => $failure[0]->host(), 'repo' => $failure[0]->path(), 'message' => $failure[1]], $failed);
    }

    public function code(): string
    {
        return $this->code;
    }

    /** The sentence that every format prints: prose, not contract. */
    public function text(): string
    {
        return $this->text;
    }

    public function docsUrl(): ?string
    {
        return $this->docsUrl;
    }

    public function setsNetworkFailures(): bool
    {
        return $this->setsNetworkFailures;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * The document's `note_details` entry. `data` is an object even when empty: PHP's `[]`
     * encodes as a JSON list.
     *
     * @return array{code: string, text: string, docs_url: ?string, sets_network_failures: bool, data: \stdClass}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'text' => $this->text,
            'docs_url' => $this->docsUrl,
            'sets_network_failures' => $this->setsNetworkFailures,
            'data' => (object) $this->data,
        ];
    }
}
