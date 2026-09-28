---
title: lockrot run notes — what a run could not see, and what each note means
description: Every note lockrot can add to a report, by code, with whether it fails --strict-network, the S10 reason the same condition puts on a finding, and the typed data the JSON report carries for it.
---

# Run notes

A run note says what a lockrot run could not see: metadata that did not come, a repository host that
did not answer, a check the install-time budget cut short. Every format prints the notes, and the
[JSON report](schema.md) carries each one twice: as a sentence in `notes`, and typed in
`note_details`, at the same index:

```json
{
    "code": "repository_activity_anonymous_cap",
    "text": "GitHub token not set: repository activity checked for 11 candidate packages, 89 packages skipped (set GITHUB_TOKEN to check all)",
    "docs_url": "https://lockrot.dev/notes/#repository_activity_anonymous_cap",
    "sets_network_failures": false,
    "data": {"forge_id": "github", "checked": 11, "skipped_no_token": 80, "skipped_budget": 9}
}
```

- `code` says what the note is about, and `data` the facts, typed per code. A code can appear more
  than once in a run (once per repository host, Composer repository or monorepo parent), so an entry
  is identified by its index, never by its code.
- `text` is exactly the sentence in `notes`. It is prose, not contract; so is every `message` in
  `data`, which is a repository's, a host's or Composer's own words.
- `docs_url` links to the section of this page for the code. Read it; do not build it.
- `sets_network_failures` says whether the note is part of why the report's `network_failures` is
  true: the flag [`--strict-network`](ci.md#exit-codes) fails the run on, and what makes the
  [install-time summary](install-time.md#never-silent-about-a-package-it-could-not-check) print its
  "could not be checked" block. `network_failures` is true exactly when one entry's is.

The codes grow in minor releases. A code keeps its meaning — a new meaning gets a new code — and is
retired, never removed or reused, and every `docs_url` a release has written keeps landing on its
section. For a code you do not know, show `text`, link `docs_url` when it is not null, and still
honour `sets_network_failures`. A report written before 0.13.0 has no `note_details`; read its
`notes` as text. [compatibility.md](compatibility.md#run-notes) says what 1.0 freezes.

Several notes are the run-level side of a reason a finding carries in [S10](verdicts.md#the-signals),
the signal that says a check did not run. Each section below names it.

## Repository metadata

### Offline {#offline}

`--offline`: repository metadata came from Composer's cache, and nothing was fetched over the network.
`data` is `{}`. `sets_network_failures`: no. A finding whose repository activity was not in lockrot's
cache carries S10 `offline`.

### Metadata unavailable {#metadata_unavailable}

Metadata was asked for and did not come, for `package_count` packages. `reasons` groups them by
`message`, in the order the sentence lists them, each with a `reason` and its own `package_count`;
the counts sum to the note's. `reason` is one of:

- `offline` — `--offline`, and Composer's cache had no copy of the package;
- `install_time_budget` — the [install-time budget](install-time.md#time-budget) ran out before
  the package was asked for;
- `no_versions` — the repository lists the package, and no version it serves is left;
- `fetch_failed` — any failure not given a more specific reason: a transport error, or what a
  repository threw, which can happen under `--offline` too when a repository fails to read its
  cache. A later minor release may move cases out of it into reasons of their own.

`sets_network_failures`: yes, whatever the reasons, `offline` and `install_time_budget` included.
Each package is `unknown`, with `libyears_unmeasured` `metadata_unavailable`.

### Monorepo parent unavailable {#monorepo_parent_unavailable}

A package split out of a monorepo (`illuminate/*` out of laravel/framework) is dated by its parent's
tags; `parent` names the monorepo whose metadata did not come, with a `reason` and `message` as in
[metadata unavailable](#metadata_unavailable). Its split packages keep their own dates.
`sets_network_failures`: yes.

## Security advisories

### Advisory ignore list unreadable {#advisory_ignore_unreadable}

Composer rejected the project's advisory ignore configuration (`config.policy.advisories`, or
`config.audit.ignore` on an older Composer), so no advisory is ignored and every one counts. `message`
is the first line of what Composer said, and may be empty. `sets_network_failures`: no.

### Advisories unavailable {#advisories_unavailable}

One Composer repository (`composer_repository`, Composer's own name for it) did not return advisories;
the others were still asked. `message` is the first line of the error, or its class when it has none.
`sets_network_failures`: yes when the repository could not be reached, no when it answered with
something lockrot could not read.

### Advisories not checked {#advisories_not_checked}

No advisory was looked for past this point, so a priority an advisory would raise
([no fix expected](verdicts.md#security-advisories)) stays one step lower. `reason` is one of:

- `offline` — `--offline`: advisories are never served from a cache;
- `composer_too_old` — Composer below 2.4 has no advisory API;
- `install_time_budget` — the install-time budget ran out.

`composer_repositories_checked` counts the advisory-capable repositories lockrot asked before the
check stopped, whatever each gave: advisories, none at all, or a failure, which has its own
[`advisories_unavailable`](#advisories_unavailable) note. It is 0 for `offline` and
`composer_too_old`; above 0 under `install_time_budget`, the check was partial: the advisories those
repositories returned are in the findings, and the rest were never asked. `sets_network_failures`:
no.

## Repository activity

The activity round asks GitHub, GitLab and Bitbucket about the repository behind each package, for
S3 and S4. `forge_id` is `github`, `gitlab` (gitlab.com and every host in Composer's
`gitlab-domains`) or `bitbucket`; each repository is a `host` and a `repo`, the keys S3 and S4 use.

### Repository activity not checked {#repository_activity_not_checked}

No repository host was asked at all. `reason` is `install_time_budget`: the metadata pass used the
whole install-time budget. `sets_network_failures`: no. Findings carry S10 `install_time_budget`.

### Anonymous cap {#repository_activity_anonymous_cap}

Without a token for GitHub, or Bitbucket credentials, lockrot asks only about the packages whose
activity could change their verdict, and at most a budget of them. `checked` were asked;
`skipped_no_token` were not candidates, and their findings carry S10 `no_token`; `skipped_budget`
were candidates beyond the budget, and carry S10 `anonymous_budget`. The sentence prints the two
skipped counts as one number. GitLab has no cap. `sets_network_failures`: no. See
[configuration.md](configuration.md#environment-overrides) for the tokens.

### Rate limited {#repository_activity_rate_limited}

A host of this kind answered "too many requests". `repositories` lists every repository on that kind
of host that got no answer, on each of its hosts, each with its own `message`; the sentence's count
is its length. `sets_network_failures`: yes. Every package on that kind of host whose activity is
missing carries S10 `rate_limit` — a package whose repository answered 404 too, which is listed under
[not found](#repository_activity_not_found) as well.

### Unreachable {#repository_activity_unreachable}

The host did not answer for `repositories`, each with its own `message`; the sentence prints their
count and the first one's message. `sets_network_failures`: yes. Their findings carry S10
`fetch_failed`.

### Not found {#repository_activity_not_found}

The host answered 404 for `repositories`: private, renamed or removed. That is an answer, not a
network failure, so `sets_network_failures` is no — but a private repository would otherwise look
exactly like a healthy one. Where the host did not also rate-limit, this note is the only record of
them: their findings carry no S10, and their `--explain` document's `activity` is null.

## The lock

### Not from a Composer repository {#not_from_composer_repository}

`package_count` packages carry no Composer `notification-url` — a `path`, `vcs`, `artifact` or inline
`package` entry, or a package from a `type: composer` repository that advertises no notify URL — and
lockrot asked no repository about them. It equals the report's `not_from_composer_repository`, and
those findings' `from_composer_repository` is false; each one's `origin.kind` says what lockrot could
tell about where it came from ([schema.md](schema.md#where-a-package-came-from)).
`sets_network_failures`: no.

## Related

- [schema.md](schema.md) — the report schema, `note_details` among its fields
- [verdicts.md](verdicts.md) — the signals, S10 among them
- [install-time.md](install-time.md) — the notes in the install-time summary
- [compatibility.md](compatibility.md#run-notes) — what 1.0 freezes about the notes
