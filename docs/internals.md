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
| GitHub | Archived state (S3) and last push (S4) | 24-hour cache |

Composer's metadata cache is reused and revalidated (`If-Modified-Since`) on every run, which is why
the repository side of "Data as of" tracks the run itself. GitHub repository-activity data keeps the
timestamp of its own 24-hour cache and can lag behind by up to a day.

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

GitHub repository-activity responses are cached separately under Composer's cache directory, in a
`lockrot/` subfolder, with a fixed 24-hour TTL. When Composer's cache is disabled
(`composer --no-cache`), GitHub responses are kept in memory for the run only and nothing is written
to disk.

## Working offline

`--offline` sets `COMPOSER_DISABLE_NETWORK=1` and rebuilds the configured repositories behind it. In
plugin mode Composer has already built its own, network-enabled repositories before any command runs,
so rebuilding them is what actually makes the flag effective. Repository metadata is then served from
Composer's own cache and GitHub activity from lockrot's cache.

A package missing from the cache is reported as unavailable, not as absent from the repository. With
`--strict-network` that becomes exit `1`; see [ci.md](ci.md).

## The GitHub token

Without a token, only packages that already look stale on release age — no stable release within
`release-warn-years` (default 3y) and not already `abandoned` — are checked against GitHub, capped at
50 packages per run. lockrot reports how many packages this affected.

Set `GITHUB_TOKEN` or `LOCKROT_GITHUB_TOKEN` to lift the cap. Composer's own
`github-oauth.github.com` authentication is used as a fallback if neither is set.

Repository activity is checked on GitHub only. GitLab and Bitbucket are not queried.

## Related

- [verdicts.md](verdicts.md) — which signal each source feeds
- [configuration.md](configuration.md) — thresholds, tokens and `--offline`
- [example-run.md](example-run.md) — what the footer looks like in a real run
