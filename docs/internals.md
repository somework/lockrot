---
title: Data sources — repositories, host APIs, credentials and cache
description: What lockrot asks your Composer repositories and the GitHub, GitLab and Bitbucket APIs, which credentials go where, the anonymous caps, and the 24-hour cache.
---

# Data sources {#how-lockrot-fetches-metadata}

lockrot asks the Composer repositories `composer.json` configures, and the API of the repository
host a package's source lives on. Credentials for GitHub or Bitbucket lift the anonymous cap of
50 repositories per host per run
([Repository hosts and credentials](#repository-hosts-and-credentials)).
For a missing or wrong date, start at
[When a date is missing or wrong](#when-a-date-is-missing-or-wrong).

## Repository hosts and credentials

| Source | What lockrot asks | Credentials | Without credentials | Cache |
|---|---|---|---|---|
| Composer repositories: Packagist, Private Packagist, Satis, mirrors | Metadata: abandoned flag, versions, release dates (S1, S2, S6, S8). The releases S9 looks in for a fix. Advisories on the installed version (S9) | Composer's own, from `auth.json` or `COMPOSER_AUTH` | What the repository serves anonymously | Composer's |
| GitHub, `api.github.com` | Archived flag (S3), last push to any branch (S4) | `LOCKROT_GITHUB_TOKEN`, else `GITHUB_TOKEN`, else Composer's `github-oauth`. Any other Composer credential for github.com counts too | Candidates only, at most 50 repositories a run | lockrot's, 24 h |
| GitLab: gitlab.com and every host in Composer's `gitlab-domains` | Newest commit on any branch (S4). Archived flag (S3) with credentials only | `LOCKROT_GITLAB_TOKEN`, else `GITLAB_TOKEN`, for gitlab.com only. Any Composer credential for the host, such as `gitlab-token` | Every package, but the archived flag is hidden, so S3 cannot fire | lockrot's, 24 h |
| Bitbucket Cloud, `api.bitbucket.org` | Newest commit on any branch (S4). Bitbucket has no archived state | Composer's `http-basic` (an Atlassian API token), `bearer` or `bitbucket-oauth` | Candidates only, at most 50 repositories a run | lockrot's, 24 h |

- A candidate has [S2](verdicts.md#the-signals) and no S1. A package with no dated release is
  not a candidate.

- A package the cap skips carries S10, except one marked abandoned
  ([What was not checked](verdicts.md#what-was-not-checked)).

- The [Anonymous cap](notes.md#repository_activity_anonymous_cap) note counts the skipped packages
  per host.

The lock needs no request: S5 reads the lock entry's `time` and `require.php`, and S6 a
branch-snapshot version.

Every request, to a repository or a host, goes through Composer's HTTP layer, so Composer's proxy
and TLS settings apply to both.

### Which host is asked {#which-host}

lockrot reads the host from the first of these URLs that is set:

1. the `source` URL of the package's highest tagged release (a pre-release counts), in the
   repository metadata.
2. that release's `support.source`, with a `/tree/<ref>`, `/-/tree/<ref>` or `/src/<ref>` page
   suffix cut off.
3. the lock entry's `source` URL, then its `support.source`.

lockrot never uses an older release's URL. It asks no host about a package that:

- has no such URL, or one that names none of github.com, bitbucket.org or a host in
  `gitlab-domains`, so lockrot does not query GitHub Enterprise (`github-domains`) or Bitbucket
  Server
- is allowlisted
- is not from a Composer repository

Such a package gets no S3 or S4, and no S10 for `repository_activity`.

### What is sent

lockrot sends one set of requests per repository, however many packages share it. Each request
carries `User-Agent: lockrot`.

| Host | Requests | lockrot's own credential header |
|---|---|---|
| GitHub | `GET https://api.github.com/repos/{owner}/{repo}` | `Authorization: token …` |
| GitLab | `GET https://{host}/api/v4/projects/{path}/repository/commits?all=true&per_page=1`. With credentials, also `GET https://{host}/api/v4/projects/{path}`. `{path}` is the project path URL-encoded as one segment, for example `group%2Fproject` | `PRIVATE-TOKEN: …`, to gitlab.com only |
| Bitbucket | `GET https://api.bitbucket.org/2.0/repositories/{workspace}/{slug}/commits?pagelen=1` | None: Composer's credentials only |

### Which credentials

- A host counts as authenticated, with no cap, when lockrot has a token for it or Composer holds
  credentials for it (in `auth.json`, `COMPOSER_AUTH`, or what a CI setup step writes there). On
  GitLab, credentials also unlock the archived flag.

- When both exist, lockrot sends only Composer's header.

- Exception: Composer's entry for the host can be a client certificate, or custom headers without a
  credential header. Then Composer adds no credential header, and lockrot sends its own.

- lockrot exchanges a `bitbucket-oauth` consumer for a bearer token, once per run, the first time
  it asks about a Bitbucket repository: a `client_credentials` request to
  `https://bitbucket.org/site/oauth2/access_token`. lockrot writes nothing to `auth.json`. When the
  exchange fails, `-v` prints why, the run counts as anonymous on Bitbucket, and the refused
  requests appear in the [Unreachable](notes.md#repository_activity_unreachable) note.

`self-update` sends the same GitHub token to GitHub's release API
([Keeping it updated](phar.md#keeping-it-updated)).

## Which Composer repository answers {#two-passes}

- lockrot looks up only lock entries that carry a `notification-url`, which Composer records for a
  package from a Composer repository. A Satis build without
  [`notify-batch`](https://getcomposer.org/doc/articles/handling-private-packages.md#other-options)
  gives its packages none, so they read as not from a Composer repository
  (`from_composer_repository` false, [note](notes.md#not_from_composer_repository)).

- lockrot asks Composer repositories in Composer's lookup order, and the first that lists a
  package answers for it. A repository that fails or does not list the package passes it to the
  next.

- lockrot asks each repository for tagged releases first. It fetches a package's `~dev` branch
  file only when the package has no tagged release.

- Dating a split package by its monorepo can cost one more request
  ([Dates from the monorepo](verdicts.md#dates-from-the-monorepo)).

- Advisories come from every repository that publishes them, not only the first
  ([Advisories not checked](notes.md#advisories_not_checked)). A repository with an advisory API,
  such as Packagist, receives the names of the checked packages, not their versions.
  [Security advisories](verdicts.md#security-advisories) says how lockrot matches and ignores
  advisories.

## Caching

| Data | Where | How long | When it is refetched |
|---|---|---|---|
| Repository metadata | Composer's cache | Revalidated each run (`If-Modified-Since`) | Every run |
| Advisories from an advisory API, such as Packagist's | Not cached | None | Every run |
| Advisories a repository carries in its package files | Composer's cache, inside the metadata | Revalidated with the metadata | Every run |
| Repository activity | `lockrot/` under Composer's `cache-dir` | 24 hours, fixed | When the answer is 24 hours old. A failed refetch keeps the older answer. `--offline` never refetches |

- Each cached answer holds the response's status, body and fetch time. lockrot stores no request
  header, so it stores no token.

- lockrot does not cache a failed request (a transport error, 401, 403, 429 or 5xx). When an older
  answer for the URL is cached, lockrot uses it, and the footer shows its age. A 404 is an answer,
  and lockrot caches it.

-  With Composer's cache disabled (`composer --no-cache`), lockrot keeps answers in memory for the
  run and writes nothing.

- `composer clear-cache` clears lockrot's cache with Composer's. To refetch repository activity
  alone, delete the `lockrot` directory under the path `composer config cache-dir` prints.

### How fresh the data is {#two-sources-two-clocks}

The footer states the report's date, in UTC, and its sources
([Reading the table](example-run.md#the-table-format)).

When any activity answer came from the cache, the footer's parenthesis reads
`package repositories; repository activity from lockrot's cache, up to N h old`. N is the age of
the oldest cached answer in whole hours, rounded up, at least 1. `--format=json` carries that
answer's fetch time as `activity_cache_oldest_at`, null when every answer was fetched during the
run.

## When a date is missing or wrong

`--explain=vendor/package` prints every date a finding was decided on and where it came from
([Explaining one package](configuration.md#explaining-one-package)).

| Symptom | Cause | Fix or reference |
|---|---|---|
| No S2 or S8, and S10 names `release_dates` | The Composer repository dates the newest tags only by a commit they share (a subtree split), and no monorepo parent dates them | [Left behind](verdicts.md#left-behind), [Dates from the monorepo](verdicts.md#dates-from-the-monorepo) |
| A release date older than the release | The Composer repository dates a tag by its commit, so a tag cut on an old commit carries that commit's date | [Left behind](verdicts.md#left-behind) |
| S5's release date is wrong | S5 reads the lock entry's `time`, the release date the Composer repository reported when the lock was last updated | Check the lock entry's `time` |
| S10 names `repository_activity` with `no_token` or `anonymous_budget` | An anonymous run on GitHub or Bitbucket | Set `GITHUB_TOKEN`, or add bitbucket.org credentials to `auth.json` |
| S10 names `repository_activity` with `install_time_budget` or `offline` | The install-time budget ran out, or `--offline` found no cached answer | [install-time.md](install-time.md), [Working offline](#working-offline) |
| S10 with `rate_limit` or `fetch_failed`, or the [Rate limited](notes.md#repository_activity_rate_limited) or [Unreachable](notes.md#repository_activity_unreachable) note | The repository host refused or did not answer | Rerun. For a rate limit, add credentials for the repository host |
| The [Not found](notes.md#repository_activity_not_found) note | The repository host answered 404: the repository is private, renamed or removed, or the credentials cannot see it | Credentials with read access to the repository, then delete lockrot's cache ([Caching](#caching)): the 404 is cached for 24 hours |
| No S3 or S4, and no S10 | No URL names a repository host lockrot reads, or the package is allowlisted or not from a Composer repository | [Which host is asked](#which-host) |
| No S3 or S4, and no S10, on a package marked abandoned (S1) | An anonymous GitHub or Bitbucket run asks only about candidates, and an abandoned package is not one | Set a token ([Which credentials](#which-credentials)) |
| No S3 on an archived GitLab repository | Anonymous GitLab hides the archived flag | `GITLAB_TOKEN` for gitlab.com, Composer's `gitlab-token` for other GitLab hosts |
| S4 on GitLab older than the project's "last activity" | S4 is the newest commit date, not GitLab's "last activity" | None: S4 is the commit date |
| Activity up to a day behind the repository host | lockrot's 24-hour cache | Delete the cache ([Caching](#caching)) |

## Working offline

`--offline` makes no network request. It sets `COMPOSER_DISABLE_NETWORK=1` for the run.

- Repository metadata comes from Composer's cache. lockrot reports a package that is missing from
  it as unavailable ([Metadata unavailable](notes.md#metadata_unavailable)), not as absent from the
  repository.

- Repository activity comes from lockrot's cache, however old. A package whose repository is
  missing from it carries S10 with reason `offline`
  ([What was not checked](verdicts.md#what-was-not-checked)).

- lockrot does not check advisories ([Advisories not checked](notes.md#advisories_not_checked)).

- The report carries the [Offline](notes.md#offline) note. Activity missing from lockrot's cache
  also shows in the [Unreachable](notes.md#repository_activity_unreachable) note. With
  `--strict-network`, metadata or activity missing from the caches makes the run exit `1`
  ([exit codes](ci.md#exit-codes)).

## Memory on a large lock {#memory-and-the-p2-protocol}

lockrot reads a repository that publishes a `metadata-url` (Composer's v2 "p2" protocol) one
package file at a time, so memory stays flat on a large lock.

Composer loads a repository without one (a Composer v1-style or static repository, including
`packages.json`-only Satis output) whole before lockrot can find any name in it. Its full package
list stays in memory for the run.

## Related

- [verdicts.md](verdicts.md) — the signals each source feeds, and what an unchecked one means
- [notes.md](notes.md) — every note a failed or capped source leaves in the report
- [configuration.md](configuration.md#environment-overrides) — the token variables and `--offline`
- [SECURITY.md](https://github.com/somework/lockrot/blob/main/SECURITY.md#what-lockrot-does-and-does-not-do) — everything lockrot reads, writes and contacts
- [example-run.md](example-run.md) — a footer from a real run
