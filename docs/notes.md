---
title: Run notes — every code, its data, and whether it fails --strict-network
description: "Look up a lockrot run note by its code: what the run could not see, whether it fails --strict-network, its effect on findings, its typed data and what to do."
---

# Run notes

A run note names something a run could not see or check. `--strict-network` fails the run when
one of its notes counts as a network failure ([exit codes](ci.md#exit-codes)). The table says which
do, what each gap does to findings, and what to do about it.

| Code | What happened | Network failure | Effect on findings | What to do |
|---|---|---|---|---|
| [`offline`](#offline) | `--offline`: metadata came from Composer's cache | no | S10 `offline` where activity is not cached | Run once online to fill the caches ([working offline](internals.md#working-offline)) |
| [`metadata_unavailable`](#metadata_unavailable) | Metadata did not arrive for some packages | yes | Those packages are `unknown` | Depends on the [`reason`](#metadata_unavailable) |
| [`monorepo_parent_unavailable`](#monorepo_parent_unavailable) | A monorepo's metadata did not arrive | yes | Split packages it would date stay unmeasured, with S10 `undated_releases` | Rerun |
| [`advisory_ignore_unreadable`](#advisory_ignore_unreadable) | Composer rejected the advisory ignore settings | no | Every advisory counts, ignored ones included | Fix `config.policy` or `config.audit`, as the note's `message` says |
| [`advisories_unavailable`](#advisories_unavailable) | A Composer repository's advisory request failed | yes when unreachable, no for any other error the repository raised, such as an answer lockrot could not read | That repository's advisories are missing | Rerun; for any other error, check the repository `composer_repository` names |
| [`advisories_not_checked`](#advisories_not_checked) | Advisories were not looked up, or only partly | no | A priority an advisory would raise stays one step lower | `offline`: run online; `composer_too_old`: use Composer 2.4 or newer; `install_time_budget`: raise the [budget](install-time.md#time-budget) |
| [`repository_activity_not_checked`](#repository_activity_not_checked) | No repository host was asked | no | S10 `install_time_budget` | Raise the [budget](install-time.md#time-budget), or run `composer lockrot` |
| [`repository_activity_anonymous_cap`](#repository_activity_anonymous_cap) | Without credentials, only some packages were asked about | no | S10 `no_token`, `anonymous_budget` | Set a token ([credentials](internals.md#repository-hosts-and-credentials)) |
| [`repository_activity_rate_limited`](#repository_activity_rate_limited) | A host answered "too many requests" | yes | S10 `rate_limit` | Rerun, or add credentials for the host ([credentials](internals.md#repository-hosts-and-credentials)) |
| [`repository_activity_unreachable`](#repository_activity_unreachable) | A host did not answer | yes | S10 `fetch_failed` | Rerun |
| [`repository_activity_not_found`](#repository_activity_not_found) | A host answered 404: private, renamed or removed | no | None, so the note is the only record; S10 `rate_limit` when the host also rate-limited | For a private repository, credentials that can read it ([fixes](internals.md#when-a-date-is-missing-or-wrong)) |
| [`not_from_composer_repository`](#not_from_composer_repository) | Some packages come from no Composer repository and were not checked | no | `from_composer_repository` is false | Nothing on the project's side; a `type: composer` repository's operator can make it advertise a notify URL |

## Reading a note {#reading-a-note}

Every output format prints each note as a sentence. The [JSON report](schema.md) and the
`--explain` document also carry it typed in `note_details`, at the same index as its sentence in
`notes`:

```json
{
    "code": "repository_activity_anonymous_cap",
    "text": "GitHub token not set: repository activity checked for 11 candidate packages, 89 packages skipped (set GITHUB_TOKEN to check all)",
    "docs_url": "https://lockrot.dev/notes/#repository_activity_anonymous_cap",
    "sets_network_failures": false,
    "data": {"forge_id": "github", "checked": 11, "skipped_no_token": 80, "skipped_budget": 9}
}
```

[The schema](schema.md#run-notes) defines each key, and what to do with a code you do not know.
The sections below type each code's `data`; every `message` in it is a repository's, a host's or
Composer's own words, not contract. [compatibility.md](compatibility.md#run-notes) lists what stays
stable for 1.x, and which `reason` values a minor release may split.

!!! note "Older releases"
    A report written before 0.13.0 has no `note_details`. Read its `notes` as text.

## Repository metadata

### Offline {#offline}

The run was `--offline`: repository metadata came from Composer's cache, and nothing was fetched
over the network.

- `data`: `{}`.
- Findings: a finding whose repository activity is not in lockrot's cache carries S10 `offline`
  ([working offline](internals.md#working-offline)).
- `sets_network_failures`: no.

### Metadata unavailable {#metadata_unavailable}

Metadata was asked for and did not arrive for `package_count` packages.

- `data`: `package_count`, and `reasons`, one entry per distinct `message` in the order the
  sentence lists them. Each entry has a `reason`, the `message` and its own `package_count`; the
  counts sum to the note's.
- Findings: each such package is `unknown`, with `libyears_unmeasured` `metadata_unavailable`.
- `sets_network_failures`: yes, whatever the reasons, `offline` and `install_time_budget` included.

| `reason` | Cause | What to do |
|---|---|---|
| `offline` | `--offline`, and Composer's cache had no copy of the package | Run once online to fill Composer's cache |
| `install_time_budget` | The [install-time budget](install-time.md#time-budget) ran out before the package was asked for | Raise the [budget](install-time.md#time-budget) |
| `no_versions` | The repository lists the package but returned no versions of it | Check the repository that lists the package |
| `fetch_failed` | Any other failure: a transport error, or an error a repository raised, which can happen under `--offline` too | Rerun |

### Monorepo parent unavailable {#monorepo_parent_unavailable}

A package split out of a monorepo (`illuminate/*` out of `laravel/framework`) is dated by its
parent's tags ([dates from the monorepo](verdicts.md#dates-from-the-monorepo)). The parent's
metadata did not arrive.

- `data`: `parent`, the monorepo, with a `reason` and a `message` as in
  [metadata unavailable](#metadata_unavailable). `reason` is never `install_time_budget`: when the
  install-time budget runs out first, the parent is not asked, and no note is written.
- Findings: the split packages the parent would date stay unmeasured. S2 and S8 cannot read their
  undated branches, and those findings carry S10 `undated_releases`
  ([what was not checked](verdicts.md#what-was-not-checked)).
- `sets_network_failures`: yes.

## Security advisories

Composer, in these notes, is the one lockrot runs in: the project's for the plugin, the bundled
one for the PHAR, the image and the Action.

### Advisory ignore list unreadable {#advisory_ignore_unreadable}

Composer 2.10 or newer rejected the project's advisory policy (`config.policy`, or `config.audit`
as its fallback), so lockrot ignores no advisory.

- `data`: `message`, the first line of what Composer said; it can be empty.
- Findings: advisories the policy would ignore raise priority too.
- `sets_network_failures`: no.

### Advisories unavailable {#advisories_unavailable}

One Composer repository did not return advisories. The other repositories were still asked.

- `data`: `composer_repository`, Composer's name for the repository, and `message`, the first line
  of the error, or the error's class when it has no message.
- Findings: advisories from that repository are missing.
- `sets_network_failures`: yes when the repository could not be reached; no for any other error it
  raised, such as an answer lockrot could not read.

### Advisories not checked {#advisories_not_checked}

The advisory lookup did not run, or stopped before every repository was asked.

- `data`: `reason`, and `composer_repositories_checked`: how many advisory-capable repositories
  lockrot asked before the check stopped, whatever each returned. A repository that failed has its
  own [`advisories_unavailable`](#advisories_unavailable) note.
- Findings: a priority an advisory would raise ([no fix expected](verdicts.md#security-advisories))
  stays one step lower.
- `sets_network_failures`: no.

| `reason` | Cause | `composer_repositories_checked` |
|---|---|---|
| `offline` | `--offline`: advisories are never served from a cache | 0 |
| `composer_too_old` | Composer below 2.4 has no advisory API | 0 |
| `install_time_budget` | The install-time budget ran out | Above 0 when the check was partial: advisories from the repositories asked are in the findings, and the rest were never asked |

## Repository activity

lockrot asks GitHub, GitLab and Bitbucket about the repository behind each package, for signals
S3 and S4 ([the signals](verdicts.md#the-signals)). In these notes:

- `forge_id` is `github`, `gitlab` (gitlab.com and every host in Composer's `gitlab-domains`) or
  `bitbucket`.
- Each entry of `repositories` is a `host` and a `repo`, plus a `message` where the host gave no
  answer.

Tokens and credentials for each host are in
[internals.md](internals.md#repository-hosts-and-credentials).

### Repository activity not checked {#repository_activity_not_checked}

No repository host was asked at all: the metadata, monorepo-parent and advisory lookups used the
whole install-time budget.

- `data`: `reason`, which is `install_time_budget`.
- Findings: S10 `install_time_budget`.
- `sets_network_failures`: no.

### Anonymous cap {#repository_activity_anonymous_cap}

Without a GitHub token or Bitbucket credentials, lockrot asks only about packages whose activity
could change their verdict, and only up to a budget of them
([credentials](internals.md#repository-hosts-and-credentials)). GitLab has no cap.

- `data`: `forge_id`; `checked`, the packages asked about; `skipped_no_token`, packages that were
  not candidates; `skipped_budget`, candidates beyond the budget. The sentence prints the two
  skipped counts as one number.
- Findings: S10 `no_token` on the non-candidates, `anonymous_budget` on the candidates beyond the
  budget.
- `sets_network_failures`: no.

### Rate limited {#repository_activity_rate_limited}

A repository host answered "too many requests".

- `data`: `forge_id`, and `repositories`: every repository on any host of that `forge_id` that got
  no answer, each with its `message`. The count in `text` is the number of entries in
  `repositories`.
- Findings: every package on a host of that `forge_id` whose activity is missing carries S10
  `rate_limit`. That includes a package whose repository answered 404, which is also listed under
  [not found](#repository_activity_not_found).
- `sets_network_failures`: yes.

### Unreachable {#repository_activity_unreachable}

The host did not answer for some repositories.

- `data`: `forge_id`, and `repositories`, each with its own `message`. The sentence prints their
  count and the first one's message.
- Findings: S10 `fetch_failed`.
- `sets_network_failures`: yes.

### Not found {#repository_activity_not_found}

The host answered 404 for some repositories: private, renamed or removed.

- `data`: `forge_id`, and `repositories`, each a `host` and a `repo`.
- Findings: where the host did not also rate-limit, this note is the only record. The findings carry
  no S10, and their `--explain` document's `activity` is null.
- `sets_network_failures`: no.

## The lock

### Not from a Composer repository {#not_from_composer_repository}

Some lock entries carry no Composer `notification-url`, and lockrot asked no repository about them.
Such an entry is a `path`, `vcs`, `artifact` or inline `package` one, or comes from a
`type: composer` repository that advertises no notify URL. Nothing on the project's side changes
this; the operator of such a repository can make it advertise one.

- `data`: `package_count`, equal to the report's `not_from_composer_repository`.
- Findings: `from_composer_repository` is false, and `origin.kind` says what lockrot could tell
  about where the package came from
  ([where a package came from](schema.md#where-a-package-came-from)).
- `sets_network_failures`: no.

## Related

- [schema.md](schema.md) — the report schema, where `note_details` is typed
- [verdicts.md](verdicts.md#what-was-not-checked) — S10, the per-finding side of these gaps
- [ci.md](ci.md#exit-codes) — how `--strict-network` sets the exit code
- [install-time.md](install-time.md) — how the install-time summary shows notes, and which gaps it
  prints nothing for
- [compatibility.md](compatibility.md#run-notes) — what 1.0 freezes about the notes
