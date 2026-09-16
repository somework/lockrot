# How lockrot fetches metadata

You do not have to configure anything for this. lockrot reads the repositories already configured in
the project's `composer.json`, through Composer's own repository layer, so Private Packagist, Satis
instances and mirrors are honoured the same way Packagist is, along with Composer's own
authentication and proxy settings.

This page explains what that means for freshness, memory and the "Data as of" line.

## Two sources, two clocks

| Source | What it provides | Freshness |
|---|---|---|
| The configured Composer repositories | The abandoned flag (S1), release dates and versions (S2, S5, S6) | Revalidated on every run |
| GitHub, GitLab, Bitbucket | The last push or commit (S4); the archived state (S3) on GitHub, and on GitLab with credentials | 24-hour cache |

Composer's metadata cache is reused and revalidated (`If-Modified-Since`) on every run, which is why
the repository side of "Data as of" tracks the run itself. Repository-activity data keeps the
timestamp of its own 24-hour cache and can lag behind by up to a day — and when it does, the report
says so: the footer's source clause becomes `repository activity from lockrot's cache, up to 23 h
old` (the oldest cached answer, in whole hours rounded up), and `--format=json` carries that
answer's fetch time as `activity_cache_oldest_at`, null when everything was fetched in this run.

"Data as of" in the report footer is the date the report was generated, in UTC.

## Two passes

Metadata is fetched in two passes — tagged releases first, then the `~dev` branch file only for
packages with no tagged release at all. That roughly halves the number of requests on a cold cache.

## Memory and the p2 protocol

Only repositories that publish a `metadata-url` — the Composer v2 "p2" protocol — are read one
package file at a time, which is what keeps memory flat on a large lock file.

A repository without one — a Composer v1-style or static repository, including `packages.json`-only
Satis output — is loaded whole by Composer before any name can be looked up, so its full package list
is held in memory for the run.

## Caching

Repository metadata is cached and revalidated by Composer itself, under Composer's own cache
directory. lockrot adds no cache of its own for it, and there is no `--refresh` or `cache-ttl` knob to
bypass or resize it.

Repository-activity responses — GitHub, GitLab and Bitbucket alike — are cached separately under
Composer's cache directory, in a `lockrot/` subfolder, with a fixed 24-hour TTL. When Composer's
cache is disabled (`composer --no-cache`), they are kept in memory for the run only and nothing is
written to disk.

## Working offline

`--offline` sets `COMPOSER_DISABLE_NETWORK=1` and rebuilds the configured repositories behind it. In
plugin mode Composer has already built its own, network-enabled repositories before any command runs,
so rebuilding them is what actually makes the flag effective. Repository metadata is then served from
Composer's own cache and repository activity from lockrot's cache.

A package missing from the cache is reported as unavailable, not as absent from the repository. With
`--strict-network` that becomes exit `1`; see [ci.md](ci.md).

## Repository hosts and credentials

A package's source URL decides which host is asked: `github.com`, `bitbucket.org`, or a GitLab
instance — `gitlab.com` and every entry of Composer's own `gitlab-domains` setting, so a self-hosted
GitLab the project already installs from needs no lockrot configuration of its own. GitHub
Enterprise (`github-domains`) and Bitbucket Server are not queried.

| Host | What is read | Anonymously | With credentials |
|---|---|---|---|
| GitHub | `archived`; the last push to any branch | 60 requests an hour, so only packages that already look stale on release age — no stable release within `release-warn-years` (default 3y), not already `abandoned` — are checked, capped at 50 per host per run | Everything |
| GitLab | The newest commit on any branch; `archived` from the project document | 500 requests a minute: every package is checked, but the API hides `archived` from anonymous callers, so a package can be `silent` and is never `abandoned` for being archived | Everything, `archived` included |
| Bitbucket Cloud | The newest commit on any branch; there is no archived state | 60 requests an hour: candidates only, capped at 50 per host per run, like GitHub | Everything |

lockrot reports how many packages a cap affected. GitLab's `last_activity_at` is deliberately not
what S4 reads: it moves on stars, forks and issue traffic, so a repository untouched since 2012 can
show activity from 2022. The newest commit is.

Credentials come from two places. lockrot's own: `LOCKROT_GITHUB_TOKEN`, else `GITHUB_TOKEN`, else
Composer's `github-oauth.github.com` for github.com, and `LOCKROT_GITLAB_TOKEN`, else `GITLAB_TOKEN`
for gitlab.com only — a token issued by one instance is never sent to another. And Composer's own, from `auth.json` or `COMPOSER_AUTH`: `gitlab-token` or `gitlab-oauth`
for any GitLab domain, `http-basic` (an Atlassian API token) or a `bitbucket-oauth` consumer for
`bitbucket.org`, `github-oauth` or `http-basic` for github.com. Composer's HTTP layer adds those
headers itself, so lockrot only needs to know they are there: they lift the caps and, on GitLab,
unlock the project document. A `bitbucket-oauth` consumer is exchanged for a bearer token the first
time a Bitbucket repository is asked about — the same `client_credentials` request Composer makes,
minus the `auth.json` write Composer's helper does on the way, so nothing on disk changes. A
repository the host does not answer for (a private one, or a renamed or removed one) is counted in a
note of its own rather than passing for a healthy one.

When Composer has credentials for a host — `github-oauth` in `auth.json` or `COMPOSER_AUTH`, or
what setup-php configures on a CI runner — its header is the one on the wire and lockrot sends none
of its own, because a request carrying two is refused (GitHub answers 401 "Bad credentials",
whatever the tokens are). The environment token still lifts the cap.

## Related

- [verdicts.md](verdicts.md) — which signal each source feeds
- [configuration.md](configuration.md) — thresholds, tokens and `--offline`
- [example-run.md](example-run.md) — what the footer looks like in a real run
