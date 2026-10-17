<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Composer\Downloader\TransportException;
use Lockrot\Data\Forge\LocatedRepository;
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
    public const ADVISORIES_DISABLED_BY_POLICY = 'advisories_disabled_by_policy';
    public const ADVISORIES_UNAVAILABLE = 'advisories_unavailable';
    public const ADVISORIES_NOT_CHECKED = 'advisories_not_checked';
    public const REPOSITORY_ACTIVITY_NOT_CHECKED = 'repository_activity_not_checked';
    public const REPOSITORY_ACTIVITY_ANONYMOUS_CAP = 'repository_activity_anonymous_cap';
    public const REPOSITORY_ACTIVITY_RATE_LIMITED = 'repository_activity_rate_limited';
    public const REPOSITORY_ACTIVITY_UNREACHABLE = 'repository_activity_unreachable';
    public const REPOSITORY_ACTIVITY_NOT_FOUND = 'repository_activity_not_found';
    public const REPOSITORY_ACTIVITY_HOST_NOT_SUPPORTED = 'repository_activity_host_not_supported';
    public const NOT_FROM_COMPOSER_REPOSITORY = 'not_from_composer_repository';
    public const ABANDONED_MARKINGS_IGNORED = 'abandoned_markings_ignored';
    public const ABANDONED_NOT_AUDITED_BY_COMPOSER = 'abandoned_not_audited_by_composer';
    public const ABANDONED_IGNORE_UNREADABLE = 'abandoned_ignore_unreadable';
    public const BASELINE_TARGET_DIFFERS = 'baseline_target_differs';
    public const BASELINE_FIX_MODEL_DIFFERS = 'baseline_fix_model_differs';
    public const IGNORE_DOES_NOT_COVER_ADVISORIES = 'ignore_does_not_cover_advisories';
    public const MOVES_NOT_CHECKED = 'moves_not_checked';
    public const UPDATE_NOW_REDUCED = 'update_now_reduced';
    public const UPDATE_NOW_REFUSED = 'update_now_refused';
    /**
     * The codes that lockrot writes, as the schemas list them in `x-known-values`, in the order of
     * the analyzer's passes. In a report, the notes of two repository hosts can interleave.
     */
    public const CODES = [
        self::OFFLINE,
        self::METADATA_UNAVAILABLE,
        self::MONOREPO_PARENT_UNAVAILABLE,
        self::ADVISORY_IGNORE_UNREADABLE,
        self::ADVISORIES_DISABLED_BY_POLICY,
        self::ADVISORIES_UNAVAILABLE,
        self::ADVISORIES_NOT_CHECKED,
        self::REPOSITORY_ACTIVITY_NOT_CHECKED,
        self::REPOSITORY_ACTIVITY_ANONYMOUS_CAP,
        self::REPOSITORY_ACTIVITY_RATE_LIMITED,
        self::REPOSITORY_ACTIVITY_UNREACHABLE,
        self::REPOSITORY_ACTIVITY_NOT_FOUND,
        self::REPOSITORY_ACTIVITY_HOST_NOT_SUPPORTED,
        self::NOT_FROM_COMPOSER_REPOSITORY,
        self::BASELINE_TARGET_DIFFERS,
        self::BASELINE_FIX_MODEL_DIFFERS,
        self::IGNORE_DOES_NOT_COVER_ADVISORIES,
        self::ABANDONED_MARKINGS_IGNORED,
        self::ABANDONED_NOT_AUDITED_BY_COMPOSER,
        self::ABANDONED_IGNORE_UNREADABLE,
        self::MOVES_NOT_CHECKED,
        self::UPDATE_NOW_REDUCED,
        self::UPDATE_NOW_REFUSED,
    ];

    /** Each code has a section on the site at its own id. Never move one without a stub. */
    public const DOCS_URL = 'https://lockrot.dev/notes/#';

    public const ADVISORIES_OFFLINE = 'offline';
    public const ADVISORIES_COMPOSER_TOO_OLD = 'composer_too_old';
    public const INSTALL_TIME_BUDGET = 'install_time_budget';
    public const ADVISORIES_NOT_CHECKED_REASONS = [self::ADVISORIES_OFFLINE, self::ADVISORIES_COMPOSER_TOO_OLD, self::INSTALL_TIME_BUDGET];
    /** The same reason strings as S10 carries on the findings that the note concerns. */
    public const REPOSITORY_ACTIVITY_NOT_CHECKED_REASONS = [NotCheckedRule::BUDGET];

    /** Each text states the cost of the missing check: no advisory adds points, and `if_applied` assumes none on the move's versions. */
    private const ADVISORIES_NOT_CHECKED_TEXT = [
        self::ADVISORIES_OFFLINE => 'security advisories not checked (offline)'.self::ADVISORIES_NOT_CHECKED_COST,
        self::ADVISORIES_COMPOSER_TOO_OLD => 'security advisories not checked (needs Composer 2.4 or newer)'.self::ADVISORIES_NOT_CHECKED_COST,
        self::INSTALL_TIME_BUDGET => 'security advisories not checked (install-time budget spent)'.self::ADVISORIES_NOT_CHECKED_COST,
    ];

    private const ADVISORIES_NOT_CHECKED_COST = ': no finding is graded for an advisory, and each next step\'s score after the move assumes its versions carry none';

    /** The words of each `target_php_source`, as the baseline notes print them. */
    private const TARGET_SOURCE_WORDS = [
        RunSettings::SOURCE_OPTION => '--target-php',
        RunSettings::SOURCE_ENV => 'LOCKROT_TARGET_PHP',
        RunSettings::SOURCE_CONFIG => 'extra.lockrot.target-php',
        RunSettings::SOURCE_PLATFORM => 'config.platform.php',
        RunSettings::SOURCE_RUNTIME => 'the PHP running lockrot',
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
     * Information only: the setting stops Composer's own advisory warnings, and lockrot still asks.
     *
     * @param string      $policyKey the setting: `policy`, `policy.advisories`, `policy.advisories.audit` or `COMPOSER_POLICY`
     * @param bool|string $value     its value
     */
    public static function advisoriesDisabledByPolicy(string $policyKey, $value): self
    {
        return self::of(
            self::ADVISORIES_DISABLED_BY_POLICY,
            \sprintf("security advisories still checked: Composer's policy (%s: %s) stops Composer from warning about them", $policyKey, \is_bool($value) ? var_export($value, true) : $value),
            false,
            ['policy_key' => $policyKey, 'value' => $value]
        );
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

    /**
     * One entry per host, most packages first, then by host. lockrot cannot tell from a URL which
     * software a host runs, so the hint names both cases and never claims that listing helps.
     *
     * @param list<LocatedRepository> $unsupported one per package whose host lockrot cannot ask
     */
    public static function repositoryActivityHostNotSupported(array $unsupported): self
    {
        if ($unsupported === []) {
            throw new \InvalidArgumentException('A host note names at least one host.');
        }
        $count = [];
        $listedIn = [];
        foreach ($unsupported as $where) {
            $host = (string) $where->host();
            $count[$host] = ($count[$host] ?? 0) + 1;
            $listedIn[$host] = $where->listedIn();
        }
        uksort($count, static fn (string $a, string $b): int => [$count[$b], $a] <=> [$count[$a], $b]);
        $hosts = [];
        $named = [];
        $listed = [LocatedRepository::GITHUB_DOMAINS => [], LocatedRepository::FORGEJO_DOMAINS => []];
        foreach ($count as $host => $packages) {
            $host = (string) $host;
            $hosts[] = ['host' => $host, 'packages' => $packages, 'listed_in' => $listedIn[$host]];
            $named[] = \sprintf('%s (%d)', $host, $packages);
            if ($listedIn[$host] !== null) {
                $listed[$listedIn[$host]][] = $host;
            }
        }
        $total = \count($unsupported);
        $text = \sprintf('Repository activity not checked for %d %s on hosts lockrot cannot ask: %s.', $total, $total === 1 ? 'package' : 'packages', implode(', ', $named));
        if (\in_array(null, $listedIn, true)) {
            $text .= " A host that runs GitLab is asked once Composer's gitlab-domains lists it; lockrot cannot ask other forge software (Forgejo, Gitea) or a Subversion host.";
        }
        if ($listed[LocatedRepository::GITHUB_DOMAINS] !== []) {
            $text .= \sprintf(' lockrot does not ask GitHub Enterprise hosts (%s).', implode(', ', $listed[LocatedRepository::GITHUB_DOMAINS]));
        }
        if ($listed[LocatedRepository::FORGEJO_DOMAINS] !== []) {
            $text .= \sprintf(' lockrot does not ask Forgejo hosts (%s), and listing them in gitlab-domains does not help.', implode(', ', $listed[LocatedRepository::FORGEJO_DOMAINS]));
        }

        return self::of(self::REPOSITORY_ACTIVITY_HOST_NOT_SUPPORTED, $text, false, ['hosts' => $hosts]);
    }

    /** @param bool $advisoriesAsked the run's advisory lookup asked their names (`run.advisory_lookup.scope` is `all`) */
    public static function notFromComposerRepository(int $count, bool $advisoriesAsked): self
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('A note about packages outside every Composer repository counts at least one.');
        }
        $what = $advisoriesAsked ? 'release metadata and repository activity' : 'release metadata, repository activity and advisories';
        $text = $count === 1
            ? \sprintf('1 package is not from a Composer repository: its %s were not read', $what)
            : \sprintf('%d packages are not from a Composer repository: their %s were not read', $count, $what);

        return self::of(self::NOT_FROM_COMPOSER_REPOSITORY, $text, false, ['package_count' => $count, 'advisories_asked' => $advisoriesAsked]);
    }

    /**
     * @param string                      $by       the Composer setting that holds the list, {@see \Lockrot\Data\Abandoned\AbandonedIgnoreMatch::BY_POLICY} or `BY_AUDIT`
     * @param array<string, list<string>> $patterns each package whose marking the list removed, in report order, with the patterns that matched it
     */
    public static function abandonedMarkingsIgnored(string $by, array $patterns): self
    {
        if ($patterns === []) {
            throw new \InvalidArgumentException('A note about ignored markings names at least one package.');
        }
        $packages = [];
        foreach ($patterns as $package => $matched) {
            $packages[] = ['package' => $package, 'patterns' => $matched];
        }
        $count = \count($packages);
        $text = \sprintf(
            "Composer's abandoned ignore list (config.%s) covers the abandoned marking of %d %s: %s. lockrot does not count the marking; an archived repository and the release and push dates still count.",
            $by === 'policy.abandoned' ? 'policy.abandoned.ignore' : $by,
            $count,
            $count === 1 ? 'package' : 'packages',
            implode(', ', array_keys($patterns))
        );

        return self::of(self::ABANDONED_MARKINGS_IGNORED, $text, false, ['by' => $by, 'count' => $count, 'packages' => $packages]);
    }

    /**
     * Information only: the setting changes what `composer audit` prints, and lockrot still counts the marking.
     *
     * @param string      $policyKey the setting: a config key, or the environment variable `COMPOSER_AUDIT_ABANDONED`
     * @param bool|string $value     its value
     */
    public static function abandonedNotAuditedByComposer(string $policyKey, $value): self
    {
        return self::of(
            self::ABANDONED_NOT_AUDITED_BY_COMPOSER,
            \sprintf("Composer's audit is set not to report abandoned packages (%s: %s); lockrot still counts the abandoned marking. To stop counting it for one package, list the package in config.policy.abandoned.ignore.", $policyKey, \is_bool($value) ? var_export($value, true) : $value),
            false,
            ['policy_key' => $policyKey, 'value' => $value]
        );
    }

    /** @param string $message the first line of what Composer rejected the list with, possibly empty */
    public static function abandonedIgnoreUnreadable(string $message): self
    {
        return self::of(self::ABANDONED_IGNORE_UNREADABLE, \sprintf("Composer rejected the project's abandoned ignore list (%s), so every abandoned marking counts.", $message), false, ['message' => $message]);
    }

    /** The baseline file was written for another target PHP: fix changes against it are not compared. */
    public static function baselineTargetDiffers(string $baselineTargetPhp, string $baselineSource, string $runTargetPhp, string $runSource): self
    {
        return self::of(self::BASELINE_TARGET_DIFFERS, \sprintf(
            'the baseline was written for php %s (%s) and this run targets php %s (%s): fix changes against the baseline are not compared',
            $baselineTargetPhp,
            self::TARGET_SOURCE_WORDS[$baselineSource] ?? $baselineSource,
            $runTargetPhp,
            self::TARGET_SOURCE_WORDS[$runSource] ?? $runSource
        ), false, ['baseline_target_php' => $baselineTargetPhp, 'baseline_target_php_source' => $baselineSource, 'run_target_php' => $runTargetPhp, 'run_target_php_source' => $runSource]);
    }

    /** The baseline file was written under another fix model: fix changes against it are not compared. */
    public static function baselineFixModelDiffers(int $baselineFixModel, int $runFixModel): self
    {
        return self::of(self::BASELINE_FIX_MODEL_DIFFERS, \sprintf(
            'the baseline was written under fix model %d and this run uses fix model %d: fix changes against the baseline are not compared',
            $baselineFixModel,
            $runFixModel
        ), false, ['baseline_fix_model' => $baselineFixModel, 'run_fix_model' => $runFixModel]);
    }

    /**
     * Project `ignore[]` entries that match packages with counted advisories: an entry accepts
     * maintenance flags only.
     *
     * @param list<UncoveredAdvisories> $entries in report order
     */
    public static function ignoreDoesNotCoverAdvisories(array $entries): self
    {
        if ($entries === []) {
            throw new \InvalidArgumentException('A note about ignore[] entries names at least one entry.');
        }
        $items = [];
        foreach ($entries as $entry) {
            $count = \count($entry->advisories());
            $items[] = $entry->package().($entry->pattern() !== $entry->package() ? ' (ignore[] '.$entry->pattern().')' : '').\sprintf(': %d counted %s', $count, $count === 1 ? 'advisory' : 'advisories');
        }

        return self::of(self::IGNORE_DOES_NOT_COVER_ADVISORIES, "extra.lockrot.ignore[] accepts maintenance flags only, not advisories: ".implode('; ', $items)."; accept them in Composer's audit policy if that is the decision", false, ['entries' => $entries]);
    }

    /**
     * D5: Composer refuses a partial update of a name outside the lock, so it would refuse every
     * move for the same reason. The text names the first problem line, and `data.problem` holds
     * Composer's whole problem text.
     *
     * @param string $targetPhp the php version that the solve ran at
     */
    public static function movesNotChecked(string $targetPhp, string $problem): self
    {
        return self::of(self::MOVES_NOT_CHECKED, \sprintf(
            "moves not checked: Composer's solver refuses the lock itself at php %s, so it would refuse every move: %s",
            $targetPhp,
            self::firstProblem($problem)
        ), false, ['reason' => 'baseline_refused', 'php' => $targetPhp, 'problem' => $problem]);
    }

    /**
     * D9: the members of `security.update_now` that the combined command leaves out, because
     * Composer refuses them together with the others.
     *
     * @param list<string> $leftOut in report order
     */
    public static function updateNowReduced(array $leftOut, string $problem): self
    {
        if ($leftOut === []) {
            throw new \InvalidArgumentException('A note about the update now command names at least one package that it leaves out.');
        }

        return self::of(self::UPDATE_NOW_REDUCED, \sprintf(
            "update now: the command leaves out %s, because Composer's solver refuses %s together with the others: %s",
            implode(', ', $leftOut),
            \count($leftOut) === 1 ? 'it' : 'them',
            self::firstProblem($problem)
        ), false, ['left_out' => $leftOut, 'problem' => $problem]);
    }

    /** D9: Composer refuses the members of `security.update_now` together, so the report writes no command. */
    public static function updateNowRefused(string $problem): self
    {
        return self::of(self::UPDATE_NOW_REFUSED, \sprintf(
            "update now: no command, because Composer's solver refuses these packages together; run each package's own command: %s",
            self::firstProblem($problem)
        ), false, ['problem' => $problem]);
    }

    /** The first line of Composer's problem text that names a rule, without its list marker. */
    private static function firstProblem(string $problem): string
    {
        foreach (preg_split('/\R/', $problem) ?: [] as $line) {
            $line = trim($line);
            if (strpos($line, '- ') === 0) {
                return substr($line, 2);
            }
        }

        return trim($problem);
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
