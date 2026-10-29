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
| [`metadata_unavailable`](#metadata_unavailable) | Metadata did not arrive for some packages | yes | Those packages carry `libyears_unmeasured` `unavailable`, and read `unknown` when nothing else counts | Depends on the [`reason`](#metadata_unavailable) |
| [`monorepo_parent_unavailable`](#monorepo_parent_unavailable) | A monorepo's metadata did not arrive | yes | The split packages whose dates need that parent stay unmeasured, with S10 `undated_releases` | Rerun |
| [`advisory_ignore_unreadable`](#advisory_ignore_unreadable) | Composer rejected the advisory ignore settings | no | Every advisory counts, ignored ones included | Fix `config.policy` or `config.audit`, as the note's `message` says |
| [`advisories_disabled_by_policy`](#advisories_disabled_by_policy) | Composer's policy turns off Composer's advisory blocking | no | None: lockrot still checks advisories | Nothing for lockrot. Composer does not block an affected version in an update (`composer update`, `require` or `remove`) of this project |
| [`advisories_unavailable`](#advisories_unavailable) | A Composer repository's advisory request failed | yes when unreachable, no for any other error the repository raised, such as an answer lockrot could not read | That repository's advisories are missing | Rerun. For any other error, check the repository that `composer_repository` names |
| [`advisories_not_checked`](#advisories_not_checked) | Advisories were not looked up, or only partly | no | The packages that the lookup missed carry S10 `advisories`, and their advisories add no points | `offline`: run online. `install_time_budget`: raise the [budget](install-time.md#time-budget) |
| [`repository_activity_not_checked`](#repository_activity_not_checked) | No repository host was asked | no | S10 `install_time_budget` | Raise the [budget](install-time.md#time-budget), or run `composer lockrot` |
| [`repository_activity_anonymous_cap`](#repository_activity_anonymous_cap) | Without credentials, only some packages were asked about | no | S10 `no_token`, `anonymous_budget` | Set a token ([credentials](internals.md#repository-hosts-and-credentials)) |
| [`repository_activity_rate_limited`](#repository_activity_rate_limited) | A host answered "too many requests" | yes | S10 `rate_limit` | Rerun, or add credentials for the host ([credentials](internals.md#repository-hosts-and-credentials)) |
| [`repository_activity_unreachable`](#repository_activity_unreachable) | A host did not answer | yes | S10 `fetch_failed` | Rerun |
| [`repository_activity_not_found`](#repository_activity_not_found) | A host answered 404: private, renamed or removed | no | `activity.status` `not_found` and a `checks_skipped` entry, no S10 | For a private repository, credentials that can read it ([fixes](internals.md#when-a-date-is-missing-or-wrong)) |
| [`repository_activity_host_not_supported`](#repository_activity_host_not_supported) | Some repositories are on a host that lockrot cannot ask | no | S10 `host_not_supported` | For a host that runs GitLab, list it in Composer's `gitlab-domains` |
| [`abandoned_markings_ignored`](#abandoned_markings_ignored) | Composer's abandoned ignore list matched some packages | no | Their abandoned marking (S1) does not count. Each carries `abandoned_ignored` | Nothing, unless the list matches a package by mistake |
| [`abandoned_not_audited_by_composer`](#abandoned_not_audited_by_composer) | A Composer setting stops `composer audit` from reporting abandoned packages | no | None: lockrot still counts the marking | To stop counting it for one package, list the package in `config.policy.abandoned.ignore` |
| [`abandoned_ignore_unreadable`](#abandoned_ignore_unreadable) | Composer rejected the abandoned ignore list | no | Every abandoned marking counts | Fix the list, as the note's `message` says |
| [`moves_not_checked`](#moves_not_checked) | The lock does not install at the target PHP, so Composer's solver refuses every move | no | Each move's `check.reason` reads `baseline_refused` | Read `run.php_window.target_fix` ([the update that admits the target](verdicts.md#the-update-that-admits-the-target)) |
| [`update_now_reduced`](#update_now_reduced) | Composer's solver refuses some members of `update now:` together | no | The command leaves those members out | Update each member that it leaves out with its own move |
| [`update_now_refused`](#update_now_refused) | Composer's solver refuses the members of `update now:` together | no | No `update now:` command | Update each member with its own move |
| [`update_now_not_checked`](#update_now_not_checked) | Composer's solver did not check `update now:`, such as when the budget ran out | no | No `update now:` command, and `security.update_now` is empty | Update each candidate with its own move |
| [`move_check_failed`](#move_check_failed) | Composer's solver failed on a request with an error other than a refusal | no | That move's `check.reason` reads `solver_error` | Read the note's `message`. Check the repository that it names |
| [`baseline_target_differs`](#baseline_target_differs) | The baseline was written for another target PHP | no | No advisory reads `fix_lost` | Regenerate the baseline at the run's target PHP, or run at the baseline's |
| [`baseline_fix_model_differs`](#baseline_fix_model_differs) | The baseline was written under another fix model | no | No advisory reads `fix_lost` | Regenerate the baseline ([`--generate-baseline`](baseline.md)) |
| [`ignore_does_not_cover_advisories`](#ignore_does_not_cover_advisories) | An `ignore[]` entry matches a package with a counted advisory | no | None: the advisories count | Accept the advisory in Composer's audit policy, if that is the decision ([accepting advisories](verdicts.md#accepting-advisories)) |
| [`not_from_composer_repository`](#not_from_composer_repository) | Some packages come from no Composer repository, and lockrot does not check them | no | `from_composer_repository` is false. S10 `not_from_composer_repository` on a vcs or unknown origin | Nothing on the project's side. A `type: composer` repository's operator can make it advertise a notify URL |
| [`package_name_invalid`](#package_name_invalid) | Some lock entries carry a name that is no Composer package name | no | Those entries get no finding | Check the lock entries that the note's `packages` names |

## <a name="reading-a-note"></a>Reading a note {#reading-a-note}

Every output format prints each note as a sentence. The [JSON report](schema.md) and the
`--explain` document also carry it typed in `note_details`, at the same index as its sentence in
`notes`:

```json
{
    "code": "repository_activity_anonymous_cap",
    "text": "GitHub token not set: repository activity checked for 11 candidate packages, 89 packages skipped (set LOCKROT_GITHUB_TOKEN or GITHUB_TOKEN to a github.com token to check all)",
    "docs_url": "https://lockrot.dev/notes/#repository_activity_anonymous_cap",
    "sets_network_failures": false,
    "data": {"forge_id": "github", "checked": 11, "skipped_no_token": 80, "skipped_budget": 9}
}
```

[The schema](schema.md#run-notes) defines each key, and what to do with a code you do not know. Each
code's section types its `data`. Every `message` in it is a repository's, a host's or Composer's own
words, not contract. [compatibility.md](compatibility.md#run-notes) lists what stays stable for 1.x,
and which `reason` values a minor release can split.

!!! note "Older releases"
    A report written before 0.13.0 has no `note_details`. Read its `notes` as text.

## Repository metadata

### Offline {#offline}

The run was `--offline`: repository metadata came from Composer's cache, and lockrot fetched
nothing over the network.

- `data`: `{}`.
- Findings: a finding whose repository activity is not in lockrot's cache carries S10 `offline`
  ([working offline](internals.md#working-offline)).
- `sets_network_failures`: no.

### Metadata unavailable {#metadata_unavailable}

lockrot asked for the metadata of `package_count` packages, and it did not arrive.

- `data`: `package_count`, and `reasons`, one entry per distinct `message` in the order the
  sentence lists them. Each entry has a `reason`, the `message` and its own `package_count`. The
  counts sum to the note's.
- Findings: each such package has `libyears_unmeasured` `unavailable`. Its verdict comes from what
  lockrot could still read, so a counted advisory still grades it.
- `sets_network_failures`: yes, whatever the reasons, `offline` and `install_time_budget` included.

| `reason` | Cause | What to do |
|---|---|---|
| `offline` | `--offline`, and Composer's cache had no copy of the package | Run once online to fill Composer's cache |
| `install_time_budget` | The [install-time budget](install-time.md#time-budget) ran out before lockrot asked for the package | Raise the [budget](install-time.md#time-budget) |
| `no_versions` | The repository lists the package but returned no versions of it | Check the repository that lists the package |
| `fetch_failed` | Any other failure: a transport error, or an error a repository raised, which can happen under `--offline` too | Rerun |

### Monorepo parent unavailable {#monorepo_parent_unavailable}

A package split out of a monorepo (`illuminate/*` out of `laravel/framework`) is dated by its
parent's tags ([dates from the monorepo](verdicts.md#dates-from-the-monorepo)). The parent's
metadata did not arrive.

- `data`: `parent`, the monorepo, with a `reason` and a `message` as in
  [metadata unavailable](#metadata_unavailable). `reason` is never `install_time_budget`: when the
  install-time budget runs out first, lockrot does not ask the parent and writes no note.
- Findings: the split packages whose dates need that parent stay unmeasured. S2 and S8 cannot read their
  undated branches, and those findings carry S10 `undated_releases`
  ([what was not checked](verdicts.md#what-was-not-checked)).
- `sets_network_failures`: yes.

## Security advisories

Composer, in these notes, is the one lockrot runs in: the project's for the plugin, the bundled
one for the PHAR, the image and the Action.

### Advisory ignore list unreadable {#advisory_ignore_unreadable}

Composer rejected the project's advisory ignore settings (`config.policy`, with `config.audit`
as its fallback), so lockrot ignores no advisory.

- `data`: `message`, the first line of what Composer said. It can be empty.
- Findings: advisories that the policy would ignore also count: they flag `vulnerable` and add to the
  score.
- `sets_network_failures`: no.

### <a name="advisories_disabled_by_policy"></a>Advisories disabled by policy {#advisories_disabled_by_policy}

The project's Composer policy turns off Composer's advisory blocking: an update (`composer update`,
`require` or `remove`) can take a version that a security advisory affects. `composer audit` still
reports the advisories. lockrot asks its repositories as usual, and the advisories they return
count. lockrot writes the note only when the lookup ran.

- `data`: `policy_key`, the setting, and `value`, its value.
- Findings: no change.
- `sets_network_failures`: no.

| `policy_key` | `value` |
|---|---|
| `policy` | `false` |
| `policy.advisories` | `false` |
| `COMPOSER_POLICY` | `false`: the variable is `0`, `false` or `off` |

### Advisories unavailable {#advisories_unavailable}

One Composer repository did not return advisories. lockrot still asked the other repositories.

- `data`: `composer_repository`, Composer's name for the repository, and `message`, the first line
  of the error, or the error's class when it has no message.
- Findings: advisories from that repository are missing.
- `sets_network_failures`: yes when lockrot could not reach the repository. No for any other error
  that the repository raised, such as an answer lockrot could not read.

### <a name="advisories_not_checked"></a>Advisories not checked {#advisories_not_checked}

The advisory lookup did not run, or stopped before it asked every repository.

- `data`: `reason`, and `composer_repositories_checked`: how many advisory-capable repositories
  lockrot asked before the check stopped, whatever each returned. A repository that failed has its
  own [`advisories_unavailable`](#advisories_unavailable) note.
- Findings: the packages that the lookup missed carry S10 `{check: advisories}`, and their
  advisories add no points ([what was not checked](verdicts.md#what-was-not-checked)).
- `sets_network_failures`: no.

| `reason` | Cause | `composer_repositories_checked` |
|---|---|---|
| `offline` | `--offline`: advisories are never served from a cache | 0 |
| `install_time_budget` | The install-time budget ran out | Above 0 when the check was partial: advisories from the repositories asked are in the findings, and lockrot never asked the rest |

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

lockrot asked no repository host: the metadata, monorepo-parent and advisory lookups used the
whole install-time budget.

- `data`: `reason`, which is `install_time_budget`.
- Findings: S10 `install_time_budget`.
- `sets_network_failures`: no.

### Anonymous cap {#repository_activity_anonymous_cap}

Without a GitHub token or Bitbucket credentials, lockrot asks only about packages already stale on
release age (S2 and no S1), and only up to a budget of them
([credentials](internals.md#repository-hosts-and-credentials)). GitLab has no cap.

- `data`: `forge_id`, `checked`, `skipped_no_token` and `skipped_budget`. `checked` counts the
  packages that lockrot asked about. `skipped_no_token` counts the packages that were not
  candidates, and `skipped_budget` the candidates beyond the budget. The sentence prints the two
  skipped counts as one number.
- Findings: S10 `no_token` on the non-candidates, `anonymous_budget` on the candidates beyond the
  budget.
- `sets_network_failures`: no.

### Rate limited {#repository_activity_rate_limited}

A repository host answered "too many requests".

- `data`: `forge_id`, and `repositories`: every repository on a host that answered "too many
  requests" and that got no answer, each with its `message`. The count in `text` is the number of
  entries in `repositories`.
- Findings: every package on such a host that got no answer carries S10 `rate_limit`. A failure
  on another host of the same `forge_id` is in the [unreachable](#repository_activity_unreachable)
  note.
  A 404 is an answer, so its package carries `activity.status` `not_found`, and the
  [not found](#repository_activity_not_found) note lists it.
- When lockrot refetches an expired cached answer and the refetch fails, a 401, 403 or 429
  included, the repository reads the cached answer. The run then writes no rate-limit note and no
  [unreachable](#repository_activity_unreachable) note for that repository.
- `sets_network_failures`: yes.

### Unreachable {#repository_activity_unreachable}

The host did not answer for some repositories.

- `data`: `forge_id`, and `repositories`, each with its own `message`. The sentence prints their
  count and the first one's message.
- Findings: S10 `fetch_failed`.
- `sets_network_failures`: yes.

### <a name="repository_activity_not_found"></a>Not found {#repository_activity_not_found}

The host answered 404 for some repositories: private, renamed or removed.

- `data`: `forge_id`, and `repositories`, each a `host` and a `repo`.
- Findings: `activity.status` `not_found`, and the `checks_skipped` entry
  `{repository_activity, repository_not_found, [S3, S4]}`. They carry no S10, also when the same
  host rate-limited other repositories.
- `sets_network_failures`: no.

### <a name="repository_activity_host_not_supported"></a>Host not supported {#repository_activity_host_not_supported}

Some repository URLs are on a host that lockrot cannot ask. lockrot asks github.com, bitbucket.org
and every host in Composer's `gitlab-domains`, and no other host.

- `data`: `hosts`, one entry per host, the most packages first, then by host name. Each entry has
  `host`, with its port and without userinfo, `packages` and `listed_in`.
- `packages` counts the findings whose `activity.reason` is `host_not_supported`.
- `listed_in` names the Composer setting that lists the host, else it is null.
  `github-domains` lists a GitHub Enterprise host. `forgejo-domains` lists a Forgejo host, which
  `gitlab-domains` cannot make askable.
- Findings: S10 `host_not_supported`, whose summary names the host. On a package that raised S1,
  the gap is a `checks_skipped` entry instead.
- `sets_network_failures`: no.

Except for those two settings, lockrot cannot tell from a URL which software a host runs. If the
host runs GitLab, list it in
Composer's `gitlab-domains`:

```sh
composer config gitlab-domains <host>
```

lockrot cannot ask other repository host software, such as Forgejo or Gitea, or a Subversion host.

## Composer's abandoned ignore list

### <a name="abandoned_markings_ignored"></a>Abandoned markings ignored {#abandoned_markings_ignored}

Composer's abandoned ignore list matched these packages, so lockrot did not count their abandoned
marking (S1). An archived repository and the release and push dates still count.

- `data`: `by`, the Composer setting that holds the list (`policy.abandoned` or
  `audit.ignore-abandoned`), `count`, and `packages`, each with the `patterns` that matched it.
- Findings: each one carries `abandoned_ignored`.
- `sets_network_failures`: no.

### Abandoned not audited by Composer {#abandoned_not_audited_by_composer}

A Composer setting stops `composer audit` from reporting abandoned packages, and some finding raised
S1. The setting changes what `composer audit` prints, not the ignore list, so lockrot still counts
the marking. To stop counting it for one package, list the package in
`config.policy.abandoned.ignore`.

- `data`: `policy_key`, the setting, and `value`, its value.
- Findings: no change.
- `sets_network_failures`: no.

| `policy_key` | `value` |
|---|---|
| `policy` | `false` |
| `policy.abandoned` | `false` |
| `policy.abandoned.audit` | `ignore` |
| `audit.abandoned` | `ignore` |
| `COMPOSER_AUDIT_ABANDONED` | `ignore` |
| `COMPOSER_POLICY` | `false`: the variable is `0`, `false` or `off` |

### Abandoned ignore list unreadable {#abandoned_ignore_unreadable}

Composer rejected the project's abandoned ignore list, so every abandoned marking counts.

- `data`: `message`, the first line of what Composer said. It can be empty.
- Findings: no change.
- `sets_network_failures`: no.

## Moves

### <a name="moves_not_checked"></a>Moves not checked {#moves_not_checked}

Composer's solver refuses a partial update of a name outside the lock: the lock itself does not
install at the target PHP. Every move would be refused for the same reason, so lockrot checks none,
and each move's `check.reason` reads `baseline_refused`. `run.php_window.target_fix` names the
packages that exclude the target PHP and, when Composer's solver resolves it, the update that admits
it ([the update that admits the target](verdicts.md#the-update-that-admits-the-target)).

The text quotes the first rule line of Composer's first problem whose reply after ` -> ` is not
"satisfiable by", cut before ` -> `. Composer does not always list that rule last. If no rule line
has such a reply, the text quotes the last rule line. The notes
[update now reduced](#update_now_reduced) and [update now refused](#update_now_refused) quote a
refusal in the same way.

- `data`: `reason` (`baseline_refused`), `php` (the version that the solve ran at), `problem`
  (Composer's problem text).
- `sets_network_failures`: no.

### Update now reduced {#update_now_reduced}

Composer's solver refuses the members of `update now:` together. The command leaves out the members
that the refusal names, and Composer resolves the others together. Update each member that it
leaves out with its own move.

- `data`: `left_out` (the members that the command leaves out), `problem` (Composer's problem text).
- `sets_network_failures`: no.

### Update now refused {#update_now_refused}

Composer's solver refuses the members of `update now:` together, even without the members that the
refusal names. The report writes no `update now:` command. Update each member with its own move.

- `data`: `problem` (Composer's problem text).
- `sets_network_failures`: no.

### Update now not checked {#update_now_not_checked}

Composer's solver did not check the candidates of `update now:` together, for example because the
install-time budget ran out. The report writes no `update now:` command, and
`security.update_now` is empty. Update each candidate with its own move.

- `data`: `reason` (a value of `check.reason`), `packages` (the candidates).
- `sets_network_failures`: no.

### Move check failed {#move_check_failed}

Composer's solver failed on a request with an error other than a refusal, such as an answer of a
repository that does not parse. That move's `check.reason` reads `solver_error`, and the run goes
on. The note names the first such failure of the run.

- `data`: `class` and `message` of the failure, Composer's own words.
- `sets_network_failures`: no.

## Gate and baseline

### <a name="baseline_target_differs"></a>Baseline target differs {#baseline_target_differs}

The baseline file was written for another target PHP. lockrot does not compare fix changes against
it. It still compares flags, advisory ids and severities.

- `data`: `baseline_target_php`, `baseline_target_php_source`, `run_target_php`,
  `run_target_php_source`.
- Findings: no advisory reads `fix_lost`.
- `sets_network_failures`: no.

### Baseline fix model differs {#baseline_fix_model_differs}

The baseline file was written under another fix model. lockrot does not compare fix changes
against it, so a release that classifies fixes again never reads as a lost fix.

- `data`: `baseline_fix_model`, `run_fix_model`.
- Findings: no advisory reads `fix_lost`.
- `sets_network_failures`: no.

### Ignore does not cover advisories {#ignore_does_not_cover_advisories}

An `extra.lockrot.ignore[]` entry matches a package that carries a counted advisory. The entry
accepts maintenance flags only, so the advisories count. Accept an advisory in Composer's audit
policy if that is the decision.

- `data`: `entries`, one per matched package: `package`, `pattern`, `advisories` (the counted ids).
- Findings: no change.
- `sets_network_failures`: no.

## The lock

### <a name="not_from_composer_repository"></a>Not from a Composer repository {#not_from_composer_repository}

Some lock entries carry no Composer `notification-url`, and lockrot asked no repository about them.
Such an entry is a `path`, `vcs`, `artifact` or inline `package` one, or comes from a
`type: composer` repository that advertises no notify URL. The project cannot add the URL. The
operator of such a repository can make it advertise one.

- `data`: `package_count`, equal to the report's `not_from_composer_repository`, and
  `advisories_asked`: true when `run.advisory_lookup.scope` is `all`. The text names the advisories
  only when lockrot did not ask them.
- Findings: `from_composer_repository` is false, and `origin.kind` says what lockrot could tell
  about where the package came from
  ([where a package came from](schema.md#where-a-package-came-from)).
- A `vcs` or `unknown` origin carries S10 `not_from_composer_repository` for its release metadata
  and its repository activity. A `path`, `artifact` or `package` origin carries both as
  `checks_skipped` entries ([what was not checked](verdicts.md#what-was-not-checked)).
- `sets_network_failures`: no.

### Package name invalid {#package_name_invalid}

Some lock entries carry a name that is no Composer package name, such as a name that reads as a
command option. In an install-time run, a package of Composer's transaction can carry one too.
lockrot skips them: they get no finding, and no printed command names them. The text names none of
them.

- `data`: `packages`, the names as the lock or the transaction writes them.
- Findings: the entries have none.
- `sets_network_failures`: no.

## Related

- [schema.md](schema.md) — the report schema, where `note_details` is typed
- [verdicts.md](verdicts.md#what-was-not-checked) — S10, the per-finding side of these gaps
- [ci.md](ci.md#exit-codes) — how `--strict-network` sets the exit code
- [install-time.md](install-time.md) — how the install-time summary shows notes, and which gaps it
  prints nothing for
- [compatibility.md](compatibility.md#run-notes) — what 1.0 freezes about the notes
