# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Verdict changes

- **Breaking:** **Baseline:** a finding is worsened by a flag above the recorded one, a new
  advisory, or a known one re-rated or no longer fixable. The gate reads `min(score, 2 × what the
  new facts add)`. Action: run once, review, then `--generate-baseline`.
  ([Baseline](docs/baseline.md))
- **Breaking:** **All formats:** a finding lists every flag that fired (`abandoned`, `silent`,
  `pinned`, `left-behind`, `old-promise`, `stale`, and the new `capped` and `vulnerable`). Accepted flags stay
  listed, muted. Action: read a finding's `flags` where you read the verdict, or `lead` for 0.13's
  word.
  ([Flags](docs/verdicts.md#flags))
- **Breaking:** **All formats:** the verdict is a grade (`critical` … `low`; `ok`, `finished` or
  `unknown` at 0) scored from the flags, printed as a sum (`36 = left-behind 16 + old-promise 4 [¼
  of 16] + vulnerable 16 [high advisory]`). `priority` stays as an alias. Action: for `abandoned` to
  `stale`, replace `.verdict == "<word>"` with `.lead == "<word>"`. `unknown`, `finished` and `ok`
  stay in `verdict`. ([The score](docs/verdicts.md#score))
- **Breaking:** **All formats:** a counted security advisory flags the package `vulnerable` and adds
  its severity's points, doubled when no release fixes it or none runs on the target PHP.
  `ignore[]` entries no longer hide advisories. `vulnerable` decides at once, an exception to the
  evidence-first rule for a new signal, because 0.14 changes every verdict anyway. Action: run once
  without a gate. ([Verdict changes](docs/compatibility.md#verdict-changes))
- **Breaking:** **All formats:** the raise for advisories not fixed on the installed branch is gone,
  so a left-behind package with only medium or low advisories can drop a grade. Action: if you gate
  at `critical` or `high`, run once without the gate and compare each finding's verdict.
  ([The score](docs/verdicts.md#score))
- **Breaking:** **CLI:** `--fail-on=unchecked` also fails when advisories or an advisory's release
  data were not read, when a repository host cannot be asked, and on a package from vcs or an
  unknown origin. A path package and a package outside the `composer-repositories` scope are skips.
  Action: list a GitLab host in `gitlab-domains`, or accept a lasting gap in `accept-unchecked`. On
  Composer below 2.4, upgrade Composer or run the PHAR. ([What was not checked](docs/verdicts.md#what-was-not-checked))
- **All formats:** a new flag `capped` (S11, 8 points) marks an installed release whose
  `require.php` stops below the newest PHP minor that the project's root php allows. It comes after
  `stale` in flag order, so `--fail-on=stale` and a baseline entry that records `stale` keep their
  reach. Its move goes to the newest release of the lowest branch whose newest release admits that
  PHP or states no php. A grade gate at `medium` or `low` can fail on a package that 0.13 passed.
  Action: run once without a gate. ([Capped](docs/verdicts.md#capped))
- **All formats:** a package installed from vcs or a path can now be `vulnerable`, because its name
  is asked for advisories (see Security), so `--fail-on` can fail where 0.13 passed. Action: set
  `extra.lockrot.advisory-lookup` to `composer-repositories` to keep 0.13's boundary.
  ([Which advisories count](docs/verdicts.md#which-advisories-count))
- **All formats:** lockrot reads Composer's abandoned ignore list (`config.policy.abandoned.ignore`
  on Composer 2.10+, else `config.audit.ignore-abandoned` from 2.9), as `composer audit` does: a
  listed package's abandoned marking no longer counts, and the finding carries `abandoned_ignored`.
  An archived repository still counts. `--fail-on=abandoned` and a grade gate can pass on it.
  ([Abandoned](docs/verdicts.md#abandoned))
- **All formats:** a package marked abandoned only in `composer.lock` no longer carries S10 for its
  repository activity: S1 decides it, as for a package that its repository marks. So
  `--fail-on=unchecked` can pass where 0.13 failed.
  ([What was not checked](docs/verdicts.md#what-was-not-checked))
- **All formats:** `direct`, `chain` and `direct_dependents` follow `replace` and `provide`. A
  package that replaces a required name is reached through it (`friendsofphp/proxy-manager-lts`
  for `ocramius/proxy-manager`), so its maintenance is no longer halved as `unreached`, and its
  grade can rise one band. ([The score](docs/verdicts.md#score))
- **All formats:** S8 reads the project's lowest PHP as a full version: an inclusive bound gives
  `X.Y.Z` (`>=8.2` now admits a branch that needs `8.2.0`), an exclusive bound the next patch
  (`>7.1` no longer admits a branch that needs exactly `7.1.0`). S8 can then name another branch
  to follow. ([Within reach](docs/verdicts.md#within-reach))
- **All formats:** a repository that answers 404 no longer carries S10 `rate_limit` when the same
  host also rate-limits: the 404 is an answer.
  ([Not found](docs/notes.md#repository_activity_not_found))

### Security

- **All formats:** advisories are looked up by name for every package the run checks (packages-dev
  with `--dev`), so the names of packages from vcs, a path, an archive or an origin lockrot cannot
  tell now reach each Composer repository that publishes advisories. Action: set
  `extra.lockrot.advisory-lookup` to `composer-repositories` to keep 0.13's boundary.
  ([What lockrot contacts](https://github.com/somework/lockrot/blob/v0.14.0/SECURITY.md#what-lockrot-does-and-does-not-do))
- **All formats:** `next_step.commands`, `security.update_now_command`, the `update now:` line and
  the command in the `do:` line hold only arguments from one set that every shell quotes safely. A
  step with a version, branch or name from the lock or a repository outside it is left out, so a
  hostile lock cannot inject into a pasted command. This affects a reader who copies these commands. Action: if a step is missing,
  check that lock entry before you write the command by hand. ([The move](docs/schema.md#the-move))

### Added

- **All formats:** Composer's own solver checks every proposed move against the lock, offline, and
  each move carries `check` (`resolves`, `refused` or `not_checked`). A refused move names its holds
  and prints no `update` or `require` step. An `update` step names its constraint inline, and a move
  that needs its dependencies adds `--with-dependencies` or `--with-all-dependencies`. `held_by[]`
  gains the links `root-require`, `platform` and `replace` and the field `target`, and
  `run.move_check` names the Composer that solved. Under `--offline`, a package that composer.json
  admits at `dev` needs its `~dev` file in Composer's cache, else the move is `not_checked` with
  `offline`. The check offers a package that no Composer repository serves at its locked
  version only. A hold of the package's own release names it as the release that the move
  installs.
  ([The move check](docs/verdicts.md#the-move-check))
- **json, table, markdown, github:** `run.php_window` gives the PHP minors on which the lock
  installs, with require-dev and without, and the links that set each edge. The minors and edges
  assume no override, and `platform_override` names the lock's `platform-overrides.php`. The text
  formats print it as a `php:` line. ([The PHP window](docs/verdicts.md#the-php-window))
- **json, table, markdown, github:** `run.php_window.target_fix` names the packages that exclude
  the target PHP, and the answer of Composer's solver to their update at the target: the command,
  the holds of a refusal, or the root links that no update can change. The `php:` line prints it,
  and marks a root hold on a packages-dev package "(require-dev)".
  ([The update that admits the target](docs/verdicts.md#the-update-that-admits-the-target))
- **All formats:** the run notes `update_now_not_checked`, `move_check_failed` and
  `package_name_invalid`. lockrot skips a lock entry whose name is no Composer package name.
  ([Run notes](docs/notes.md#package_name_invalid))
- **CLI, Config:** `--fail-on` takes a list, one value per kind: a grade, a flag (at or above in
  flag order), `vulnerable` or `vulnerable-medium|high|critical`, and `unchecked`. ([In
  CI](docs/ci.md))
- **Config:** `ignore[].flags` accepts named maintenance flags. A liveness word also accepts the
  words below it. Without it an entry accepts them all.
  ([The allowlist](docs/configuration.md#the-allowlist))
- **All formats:** a `gate:` line says whether the run fails, which `--fail-on` value and source
  decided it, and how many findings fail each value. json writes the `gate` keys `text`,
  `failing_by`, `standing`, `failing_packages` and `not_applied`. A failing run that writes json,
  sarif, gitlab or html to stdout also prints `lockrot: gate: …` on stderr. Read stdout alone,
  without `2>&1`.
  ([Reading the gate line](docs/ci.md#reading-the-gate-line))
- **Config, json:** `extra.lockrot.accept-unchecked` accepts a check that lockrot did not run (a
  host it cannot ask, no advisory feed, a package from vcs or an unknown origin) by host,
  repository or package, with a reason and an optional `expires`. It hides no flag. json writes
  `unchecked_accepted` and `packages_unchecked_accepted`.
  ([Configuration](docs/configuration.md#accept-unchecked))
- **json:** a graded finding's `next_step` names one move with its kind, constraint and commands.
  It says what the move clears, what holds it, and the score after it (`if_applied`).
  Each branch row's `fixes.if_applied` scores a move to that branch. For a package that comes in
  through a dependency, `through[]` says whether that dependency's newest release requires it.
  `--fail-on` and the exit code do not read `next_step`. ([The move](docs/verdicts.md#move))
- **All formats:** when composer.json pins `config.platform.php` outside the minor of the target
  PHP, the line of each move with commands first says to set it to a target PHP release.
  ([The move](docs/verdicts.md#move))
- **json:** each finding writes its repository activity lookup (`activity`: read, not found, or
  not read and why), `age` (the installed release, the newest release, the newest release of the
  installed branch and the last push, on the run clock), `depth` and `named_in`. The root
  `rot_depth` counts graded findings by depth. No score, gate or sort reads them. ([Schema](docs/schema.md))
- **All formats:** the run note `repository_activity_host_not_supported` names each repository host
  that lockrot cannot ask. ([Run notes](docs/notes.md#repository_activity_host_not_supported))
- **json:** `libyears_at_least` marks a lower bound, and the root `libyears.at_least_count` counts
  them. `security.records` and `security.feeds[]` say what each advisory repository holds and did
  for the name. `run.advisory_lookup` says what the one advisory lookup of the run did.
  ([Schema](docs/schema.md))
- **All formats:** the run notes `abandoned_markings_ignored`, `abandoned_not_audited_by_composer`
  and `abandoned_ignore_unreadable` say what Composer's abandoned ignore list and its audit setting
  did. ([Run notes](docs/notes.md#abandoned_markings_ignored))
- **All formats:** the run note `advisories_disabled_by_policy` names the Composer setting
  (`config.policy: false`, `policy.advisories: false`, `policy.advisories.audit: ignore` or
  `COMPOSER_POLICY=0`) that stops Composer from warning about advisories. lockrot still asks, and
  every advisory still counts. ([Run notes](docs/notes.md#advisories_disabled_by_policy))
- **markdown:** `--markdown-max-bytes` keeps a report written through `--output` within a byte
  budget. The report ends at a whole row, and a last row counts the rows it left out.
  ([In CI](docs/ci.md))
- **sarif:** `invocations[0]` carries `exitCode` and `exitCodeDescription`.
  ([SARIF exit code](docs/ci.md#sarif-exit-code))
- **json:** each `--explain` branch row and each branch row of the html page's details block write
  `highest_php`, the php of the branch's highest tag, beside `php`, with its own floor answers
  (`highest_admits_target_php`, `highest_admits_project_php`, `highest_misses_project_php`).
  ([The PHP test](docs/verdicts.md#the-php-test-in-explain))
- **json:** each S9 row's `fix` writes `admits_target_php` and `admits_project_php`, the floor
  answers of a branch row asked of `fix.php`. ([Fix](docs/verdicts.md#fix))

### Changed

- **Breaking:** **gitlab, sarif:** one record per flag, plus one per advisory in SARIF. 0.13
  identities stay. SARIF `properties.verdict` is the grade, `signals` is `signal_ids`, `baseline` is
  `baseline_status`. A baseline-covered result carries `suppressions`. Action: guard on
  `run.properties.lockrot_schema == 2`, then read `properties.lead`.
  ([Marks](docs/ci.md#how-each-format-marks-a-finding))
- **Breaking:** **github:** the title reads `lockrot: <verdict> — <flags>`, with `(<severity>
  advisory)` after `vulnerable`. Action: key on the annotation's file and line, not its title.
  ([Compatibility](docs/compatibility.md#finding-identity))
- **Breaking:** **Self-update, Baseline, Config:** `self-update` from 0.13 lands on 0.14 at once.
  lockrot 0.13 cannot read baseline-2 or config-2. 0.13 reads an `ignore[]` entry with `flags` as an
  entry for the whole package, and warns. Action: upgrade every job, then regenerate the baseline.
  ([Self-update](docs/phar.md#keeping-it-updated))
- **Breaking:** **json, html (the embedded report):** report-2: `verdict` is a grade, and `lead`,
  `flags`, `score`, `next_step`, `security`, `checks_missing` and `run.score_model` join it.
  `run.gates[]` carry a kind, S4's `data` gains `forge` and `event`, and `evidence` holds the
  counted flags' summaries only. Action: assert `."$schema" == "https://lockrot.dev/schema/report-2.json"`
  first. ([Schema](docs/schema.md#migrating-from-report-1))
- **Breaking:** **Distribution:** `docs/compatibility.md` no longer promises which lockrot-action
  major line follows lockrot 1.x. `@v1` runs 0.14 from lockrot-action v1.2.0 on, and with `version:
  latest` from this tag on. The Docker image's `latest` tag moves with v1.2.0. Action: to stay on
  0.13, set the action's `version: 0.13.0`, or use the image tag `0.13.0`.
  ([Compatibility](docs/compatibility.md#distribution))
- **Breaking:** **Config:** an `ignore[]` entry whose `flags` key has another shape (`"stale"`,
  `["vulnerable"]`) now exits 2. Action: list maintenance flag ids, or drop the key.
  ([The allowlist](docs/configuration.md#the-allowlist))
- **CLI:** every 0.14.x run that gates, or writes json, sarif, gitlab or a baseline, prints one
  upgrade notice on stderr that links the upgrade page. Read stdout alone (do not merge `2>&1`).
  `-q` also silences the report. ([Upgrading to 0.14](docs/changes/0.14.md))
- **table, markdown, github, install-time, `--explain`:** each finding prints its flags, its score
  line, its move and one evidence line per counted flag, under its grade. The footer adds the
  `flags:`, `depth:`, `security:`, `fix, by package:`, `update now:` and `advisories:` lines.
  ([In CI](docs/ci.md))
- **All formats:** findings sort by verdict, then the points of the deciding advisory, then score.
- **json:** `libyears_unmeasured` and the keys of `libyears.unmeasured` follow `metadata.status`
  and `age`. `metadata_unavailable` is `unavailable` or `not_found`. `no_stable_release_date` is
  `shared_commit` or `undated` when the installed release has no trusted date. It is
  `no_stable_release` or `undated_releases` when no dated stable release exists to measure to.
  Action: match the new ids in jq. ([Libyears](docs/verdicts.md#libyears))
- **All formats:** the run note `not_from_composer_repository` says whether the run asked those
  packages' advisories (`data.advisories_asked`). ([Run notes](docs/notes.md#not_from_composer_repository))
- **html:** the page is lockrot-report 0.14.0, which reads report-2 only.
  ([changelog](https://github.com/somework/lockrot-report/blob/v0.14.0/CHANGELOG.md))
- **html:** the page title and description count with one singular rule ("1 package") and name an
  empty lock. The description lists every grade in grade order.
- **All formats:** the run note `advisories_not_checked` says that no finding is graded for an
  advisory, and that each next step's score after the move assumes none on its versions.
  ([Run notes](docs/notes.md#advisories_not_checked))

### Removed

- **Breaking:** **Baseline:** 0.14 reads baseline-2 only, and a baseline-1 file exits 2. Action:
  review the findings, then run `--generate-baseline`, which overwrites the file. `known_since`
  starts again on that day. ([Baseline](docs/baseline.md))
- **Breaking:** **json:** `priority_basis`, `no_fix_expected`, `run.flagged_verdicts`,
  `run.fail_on_kind`, S9's `fixed_by` and `fixed_on_branch`, `baseline.previous_verdict`, the
  cause keys of `counts` and the html details block's `activity`. Action: read `score`, `security`,
  `run.flag_ids`, `run.gates[].kind`, `baseline.recorded.lead`, the root `flags.<flag>.leading`
  and the finding's `activity`. ([Migrating](docs/schema.md#migrating-from-report-1))
- **Breaking:** **json (explain):** the explanation's top-level `target_php`, `project_php`,
  `thresholds` and `activity` move to `run` and `finding`. Action: read `.run.target_php`,
  `.run.project_php`, `.run.thresholds` and `.finding.activity`.
  ([Migrating](docs/schema.md#migrating-from-report-1))

### Fixed

- **All formats:** lockrot no longer proposes an `update` move that Composer refuses. On Composer
  2.9 and later, the plain `composer update <package>` of such a move installed a dev snapshot, and
  `update now:` held it beside the moves that resolve. ([The move check](docs/verdicts.md#the-move-check))
- **All formats:** Symfony's extension polyfills keep their place on the built-in finished list
  under their own reason, because "intentionally frozen" was false for them.
- **CLI:** `--explain` prints "archived flag not read" for a repository whose host did not send the
  flag, such as gitlab.com without a token. It printed "not archived".
  ([Explaining one package](docs/configuration.md#explaining-one-package))
- **CLI:** the `--explain` branch table prints the php of each branch's highest tag. It printed
  the php of the branch's newest dated release, such as an LTS backport, beside the highest tag.
  ([Explaining one package](docs/configuration.md#explaining-one-package))

## [0.13.0] - 2026-10-01

Act if you publish reports, set `COMPOSER` or an invalid `LOCKROT_FAIL_ON` or `LOCKROT_TARGET_PHP`,
use lockrot's PHP classes, validate with Ajv strict or a vendored schema, run `self-update` in CI or
set an egress allowlist from 0.12.0's `SECURITY.md`. A bad command line now exits `2`, not `1`. No
verdict or priority rule changed, but `COMPOSER` can change verdicts; old documents still validate.

### Verdict changes

- **Breaking:** **Config:** with `COMPOSER=alt.json`, lockrot reads `extra.lockrot` from `alt.json`,
  keeps the default baseline beside it and analyses `alt.lock`, so its thresholds, `ignore` and
  `fail-on` apply and verdicts can differ. `github`, `gitlab` and `sarif` name `alt.lock`, with
  unchanged fingerprints. Action: keep `extra.lockrot` and the baseline with that manifest.
  ([Environment overrides](docs/configuration.md#environment-overrides))

### Security

- **Breaking:** **json, html:** reports gain `run.root_package`, the `name` of the manifest
  Composer reads, to match a report to its repository. `extra.lockrot.project` renames the project
  and hides nothing, and no option hides `root_package`. Action: if you set `project` to keep a
  client's name out of published reports, remove the name from each report before publishing it.
  ([What the run was told](docs/schema.md#what-the-run-was-told))

- **All formats:** no report and no `--explain` output quotes a credential or a machine path
  (SARIF's `%SRCROOT%` aside): a URL loses userinfo, query and fragment, a path keeps its last
  segment, `baseline.path` keeps no absolute path. Earlier releases printed logins and URL tokens
  whole. Action: check older published reports; rotate any token.
  ([What a report says about your repositories](docs/schema.md#what-a-report-says-about-your-repositories))

- **CLI:** text from outside lockrot (a package name, version or constraint, a note, an error
  message, an `--explain` argument, a `self-update` tag or URL) prints as written, never as console
  markup: it can no longer restyle a line, open a terminal link or end the run with exit `2`. The
  install-time block and the `-v` Bitbucket token warning show a control character as an escape
  (`\x1B`, `\n`).

- **Baseline, CLI:** the baseline and each `--output` file are written through a temporary file
  created exclusively beside the target: a file or symlink already at that temporary name fails the
  write instead of being followed, and a replaced file keeps its permission bits. Action: give
  `--output` a directory the job can write to; an interrupted run can leave a `*.tmp` there.
  ([What lockrot does and does not do](https://github.com/somework/lockrot/blob/v0.13.0/SECURITY.md#what-lockrot-does-and-does-not-do))

- **PHAR:** each release publishes `lockrot.phar.meta.json` (its PHP floor and signing key), which
  `self-update` and `--check` read from `github.com`; checksum and signature still decide what
  installs. A release ships only if it verifies against the previous release's key, so a rotation
  ships a release signed with the old key that carries the new one.
  ([Which release it installs](docs/phar.md#which-release-it-installs), [Key custody and rotation](https://github.com/somework/lockrot/blob/v0.13.0/SECURITY.md#key-custody-and-rotation))

- **Docs:** `SECURITY.md` lists every host lockrot contacts, every credential it reads and every
  file it writes. Earlier releases also contacted GitLab, Bitbucket and GitHub's release downloads
  and read the GitLab token variables and Composer's credentials. Action: recheck a review or an
  egress allowlist based on the 0.12.0 page against this list.
  ([What lockrot does and does not do](https://github.com/somework/lockrot/blob/v0.13.0/SECURITY.md#what-lockrot-does-and-does-not-do))

### Added

- **CLI:** `--output=<format>:<path>`, repeatable, writes several reports from one run, each as its
  `--format` prints it (a `table` file has no colours and is 120 columns wide). A relative path is
  relative to the directory lockrot runs in (`-d` changes it); the directory must exist. A path to
  `composer.json`, the lock or a baseline, and `--output` with `--explain`, are refused (exit `2`).
  ([Writing reports to files](docs/configuration.md#writing-reports-to-files))

- **Config:** an `extra.lockrot` key lockrot does not read gets a line on stderr, above the
  install-time block too, naming the nearest known key when one is close (`did you mean
  install-time?`). When the schema rejects `extra.lockrot`, the error lists the unknown keys too.
  `extensions` and keys starting with `x-` are never warned about.
  ([Unknown keys](docs/configuration.md#unknown-keys))

- **json:** `gate` says whether the run fails and why: `fails`, `tripped_by` (`strict_network`,
  `fail_on`) and `fail_on_applied`. Each finding's `gate` gives `reaches_fail_on`, `exempt_by`
  (`baseline`) and `fails`, and `run` gains the inputs: `strict_network`, `mode` and
  `fail_on_kind`. ([The gate](docs/schema.md#the-gate))

- **json:** `note_details` types each entry of `notes`, at the same index: `code`, `text`,
  `docs_url` (the code's section of [Run notes](docs/notes.md#reading-a-note)),
  `sets_network_failures` and `data`, typed per code. The `--explain` document carries it too.
  ([`note_details`](docs/schema.md#run-notes))

- **json:** each finding says where its lock entry came from: `origin` (`kind`, `registry`,
  `package_url`, `local`), `from_composer_repository` (whether lockrot asks a repository about it)
  and `replacement_url` (the replacement's packagist.org page, where packagist.org named it).
  `origin` copies nothing from the lock's or the manifest's URLs.
  ([Where a package came from](docs/schema.md#where-a-package-came-from))

- **json:** each finding shows how its priority was reached. `priority_basis` gives the `base`
  level and every step that applied as `{reason, from, to}`; `no_fix_expected` lists the advisories
  no fix is expected for, as `{id, reason}`; S9's `data` gains `releases_read`.
  ([Priority](docs/verdicts.md#priority))

- **json:** `libyears_unmeasured` says why a finding's `libyears` is null, as one of the keys of
  `libyears.unmeasured`. `libyears.unmeasured` accepts keys it does not list, each a count
  ([Open sets](docs/schema.md#open-sets)). ([Libyears](docs/verdicts.md#libyears))

- **json:** `exposure_rule.max_fan_in`: a flagged transitive package reached from more direct
  requirements than this is attributed to none of them; `unattributed` lists those packages
  (`package`, `verdict`, `fan_in`).
  ([Shared packages](docs/verdicts.md#shared-packages-and-unattributed))

- **json:** `run.project_php` is the project's own `require.php`. In `--explain --format=json` each
  release branch says whether the project can move onto it: `admits_target_php`,
  `admits_project_php`, `php_blocked_by`, and `misses_target_php` and `misses_project_php` (which
  side of each floor it misses); the document gains `project_php`.
  ([Within reach](docs/verdicts.md#within-reach))

- **json:** S6's `data` says whether a pinned package ever released: `reason`,
  `has_stable_release`, `last_stable_release`, `last_stable_version`, `last_stable_dated_by` and
  `snapshot_time`. The `--explain` text prints those that have a value.
  ([Pinned: what S6 carries](docs/verdicts.md#what-s6-carries))

- **Docs:** [Compatibility](docs/compatibility.md#what-10-freezes) drafts what 1.0 freezes, the
  verdict-change policy and deprecation.

### Changed

- **Breaking:** **json, Config:** the value sets that grow in minor releases (signal ids and others,
  see [Open sets](docs/schema.md#open-sets)) are open strings in the published schemas: a `pattern`
  plus `x-known-values`. The schema URLs serve the newest release's files, so a validator that
  fetches them is affected even if you stay on 0.12. Action: with Ajv strict, declare
  `x-known-values` or set `strict: false`; refresh an earlier vendored copy.

- **Breaking:** **Config:** an invalid `LOCKROT_FAIL_ON` or `LOCKROT_TARGET_PHP` is a configuration
  error (exit `2`) naming the variable, even when `--fail-on` or `--target-php` overrides it.
  Action: fix or unset the variable.
  ([Environment overrides](docs/configuration.md#environment-overrides))

- **Breaking:** **self-update:** `--check` exits `1` only when an update would install. With or
  without `--check`, it exits `2` when it cannot read the chosen release's `lockrot.phar.meta.json`,
  or when a newer release it would take is signed with a key this archive lacks and none installs.
  Action: where only `api.github.com` is reachable, allow `github.com` and the host it redirects to.
  ([`self-update` exit codes](docs/phar.md#self-update-exit-codes))

- **Breaking:** **self-update:** from a 0.13 archive on, installs only within the running major
  (all of 0.x is one; `--allow-major` moves one) and skips, saying why, a release needing a newer
  PHP or a key it lacks. With nothing newer to install, `--force` reinstalls the newest release at
  or below the running one in its major, else exits `2`. Action: a job running `--force` should
  allow exit `2`. ([Which release it installs](docs/phar.md#which-release-it-installs))

- **Breaking:** **PHP API:** every class, interface and trait under `src/`, the plugin class
  included, is marked `@internal`, so PHPStan and IDEs can flag its use from code outside
  `Lockrot\`; the namespace `Lockrot\Extension\` is reserved. Action: depend on the CLI and the
  `json` report, not on lockrot's classes.
  ([Names reserved for extensions](docs/compatibility.md#names-reserved-for-extensions))

- **html:** the page is lockrot-report 0.13.0
  ([changelog](https://github.com/somework/lockrot-report/blob/v0.13.0/CHANGELOG.md#0130)).
  It reads the fields this release adds, among them `gate`, `priority_basis` and `run.root_package`.

- **Docs:** the semver promise, drafted for 1.0, covers the command line, `extra.lockrot` keys and
  environment variables (not the testing hooks), `json`, `sarif`, `gitlab`, `github`, the report
  `html` embeds, the baseline and `lockrot-action@v1`'s inputs; not `table`, `markdown`, the look of
  `html`, the `--explain` text, the install-time summary or `src/` classes: parse `json`.
  ([What 1.0 freezes](docs/compatibility.md#what-10-freezes))

- **Docs:** a change that can move a verdict, a priority or what `--fail-on=unchecked` matches
  ships only in a minor release, under **Verdict changes**; the one patch exception is a
  curated-data fix that moves a package to `finished` or `ok`.
  ([Verdict changes](docs/compatibility.md#verdict-changes))

- **Config:** `LOCKROT_RELEASE_URL`, the testing hook that points `self-update` away from GitHub,
  names a release list in the shape of GitHub's `releases` API instead of one release document.
  ([Testing hooks](docs/configuration.md#testing-hooks))

### Fixed

- **Breaking:** **CLI:** a command line lockrot cannot read (an unknown option, a missing value, a
  value given to a flag, an extra argument) exits `2` with one `lockrot:` line, from
  `composer lockrot` and `self-update` alike, instead of Composer's error box and exit `1`. An
  unknown command name is still exit `1`. Action: a job that allows exit `1` now fails on a bad
  option; fix the command line. ([Exit codes](docs/ci.md#exit-codes))

- **Config:** `LOCKROT_DISABLE=1` skips `composer lockrot` before anything is read, so a broken
  configuration or a bad option no longer exits `2` under it.
  ([Environment overrides](docs/configuration.md#environment-overrides))

- **Config, Baseline:** a key starting with a NUL byte is a configuration error (exit `2`) instead
  of switching `extra.lockrot` validation off, and a number too large for a float (`1e400`) under a
  key that wants an integer is exit `2` instead of a crash with exit `1`. In the baseline file both
  give a `baseline file is invalid:` message naming the key or field.
  ([`extra.lockrot` keys](docs/configuration.md#extralockrot-keys))

- **Docs:** a GitLab merge request shows only the `--format=gitlab` findings that are new or fixed
  compared with the target branch, not every finding in the `composer.lock` diff.
  ([`--format=gitlab`](docs/ci.md#-formatgitlab))

- **Docs:** `--fail-on=unchecked` reads S10, not the verdict, so an `ok` package carrying S10 fails
  the run. `finished` ranks equal to `ok`, lowest in the severity order.
  ([What was not checked](docs/verdicts.md#what-was-not-checked))

- **Docs:** an advisory with no fix expected raises the priority reached after the transitive and
  dev steps, so a transitive production `left-behind` package with one is `high`, not `critical`.
  ([Priority](docs/verdicts.md#priority))

- **Docs:** `not_from_composer_repository` counts every lock entry without a Composer
  `notification-url`, which includes a Satis build without `notify-batch`.
  ([Not from a Composer repository](docs/notes.md#not_from_composer_repository))

- **Docs:** the `pulled in by:` line appears only when `exposure` is not empty, and `exposure` lists
  a direct requirement only when it pulls in an attributable flagged package.
  ([Transitive exposure](docs/verdicts.md#transitive-exposure))

- **Docs:** to keep the JSON report beside an `html` page, write both from one run with
  `--output=json:lockrot.json`. The earlier recipe that cut the report out of the page wrote an
  empty file when the page did not match. Action: if you copied it, replace it with `--output`.
  ([The JSON beside the page](docs/ci.md#the-json-beside-the-page))

## [0.12.0] - 2026-09-24

### Changed

- The `--format=html` page is built in its own repository now,
  [somework/lockrot-report](https://github.com/somework/lockrot-report) (0.12.0), in TypeScript with tests
  in real browsers, and vendored here as one file with the manifest of its release. The report and
  its address format are the same; the page carries its own Content-Security-Policy, which pins its
  one script and one stylesheet by hash and allows no connection, so a host serving it no longer
  needs `'unsafe-inline'` for styles. On a phone the findings come first. The fixes that came with
  the rewrite — a bad escape in the address no longer blanks the page, the theme button is right the
  first time under a dark OS setting, `j`/`k` walk the rows on screen, a release-branch label in the
  timeline wraps instead of being cut short, and a dozen more — are in the renderer's changelog. `tools/report/update-renderer` moves the pin after checking the release's
  build provenance.

### Added

- `tools/corpus/`, the corpus checks, which read lockrot's output against the data lockrot read
  rather than against lockrot. Two of them had been ad-hoc scripts living in a scratchpad: one
  audits every finding's libyears and signal claims against the raw Packagist documents in the run's
  own cache, re-deriving Composer's version semantics and lockrot's date-trust rules independently;
  the other reads every `--explain` page against the JSON it was rendered from. On the 0.11.0 branch
  they found 31 and 6 real problems respectively, over 4,124 findings and 283 pages, and nothing
  after the fixes. Nothing in the tool may import or shell out to lockrot's PHP: a checker that asks
  the subject what it is about agrees with it by construction.
- The corpus is now pinned. `tools/corpus/corpus.lock.json` names 39 projects — 21 already pinned by
  git as recorded fixtures, 18 by commit with a digest per file — so a run is reproducible instead
  of being whatever those projects' default branches held that day, and `--today` pins the clock so
  the calendar cannot be filed as a code change.
- An offline `corpus-selftest` job on every pull request and every push to main, which is the part
  that proves the checks have not quietly stopped checking: every rendered sentence they read is
  registered with a declared minimum or a dated note that it is unexercised, every problem key a
  check can emit has a recorded document mutated to make exactly that key come out, and a sentence
  that no longer parses is reported rather than skipped. It does not watch lockrot's wording — a
  frozen page cannot — which a re-record or a corpus run does. Recording the Composer version
  oracle found three faults in the checker itself, affecting 3,346 of 96,599 recorded versions.

## [0.11.0] - 2026-09-22

### Added

- **S10: what the run could not check.** `ok` meant two things — every check ran and found nothing,
  or a check never ran — and the difference was invisible on the finding. Anonymously the activity
  round asks only about packages already stale on release age, so a repository archived a week
  after its last release cannot be seen, and the only trace was a note counting how many packages
  were skipped without naming one. A package whose newest releases are dated by a commit their tags
  share has the same shape: S2 has nothing to measure, and the finding read as `ok`. S10 carries
  the missing check on the finding, with the reason and the signals it blocked (`repository_activity`
  → S3, S4; `release_dates` → S2, S8). It is informational, like S7 and S9: it never decides a
  verdict, and it is raised only where the missing check could have changed one — never on a package
  the repository already marks abandoned, nor on an allowlisted one. Credentials for every host take
  away the `repository_activity` reasons and only those — `release_dates` asks no forge — and a
  branch snapshot, which is on no release branch, is told about S2 alone.
  `--fail-on=unchecked` fails a run that could not check everything,
  which is how a pipeline catches the workflow that never passed `GITHUB_TOKEN` through. See
  [What was not checked](docs/verdicts.md#what-was-not-checked).

- **One number for how far behind the lock is: libyears.** For each package, the years between
  the release installed and the package's newest stable release, summed over the analysed lock —
  171.3 on the wallabag fixture. Every finding carries its `libyears` in `--format=json` (`null`
  when not measured: a branch snapshot, no dated stable release, not from a Composer repository,
  metadata unavailable), and the document a `libyears` block that is the arithmetic over them —
  `total`, `direct_requirements` (the same sum over the direct requirements, the nearest number
  to php-libyear's, which reads `composer.json`), `measured`, `unmeasured` by reason and
  `furthest_behind`. `total` and `direct_requirements` are null where nothing could be measured, so
  that a reader adding the field up over several projects never counts an unmeasurable lock as a
  lock with nothing to fix; a measured lock with nothing behind reports `0`. The table and markdown footers print one line (`libyears: 171.3 behind
  across 195 of 200 packages · 111.7 from direct requirements · furthest behind smalot/pdfparser
  v1.1.0 at 4.7`); the HTML page shows the total in its ledger, a sortable column, and the counts
  by reason on the Run tab; `--explain` prints the package's own value under its verdict, or the
  reason it was not measured. It is laid over the verdicts, not one of them: it counts every drift,
  healthy patches included, and enters no priority, `--fail-on` or baseline. The schemas gain the
  fields under the same number, optional so that older documents still validate. No new request
  is made: both dates were already in the data. A split package's installed version is dated by
  its monorepo parent's tag of the same version, not by the lock: the lock copies the date
  Packagist gave the split's tag, the commit its tags share, and illuminate/contracts v8.83.27
  would read as 4.65 libyears behind for 3.75. `--explain` prints that date as `installed release`
  and names the monorepo it came from. That holds for an installed tag on a shared commit
  under a branch whose newest tag has a commit of its own, as after a change to the split's
  directory: the parent is asked for the installed version, not only for the branch. `--explain`
  says whose date it is under
  `installed_release` and `installed_release_dated_by`, and so does the package card. Where no
  parent dates it, such a tag is not measured at all rather than measured from the commit's date:
  the installed tag's own entry in the repository decides that, not what dated the package's
  newest release.
- **`left-behind` suggests a branch the project can actually move to.** S8 named the newest
  releasing branch and wrote the constraint that follows it, whatever PHP that branch requires:
  Matomo supports `php >=7.2.5`, locks monolog 1.27.1, and was told `require ^3.12` — monolog
  3.x needs PHP 8.1. On the weekly watch, 99 of the 138 constraints suggested to projects that
  declare a `require.php` contradicted it. Every release branch now carries the php requirement of
  the release it names (read from the same repository data, no new request), and S8 holds the
  higher branches to two floors — the project's own `require.php` and the target PHP, the version
  Composer resolves against. The newest branch still proves the upstream moved on; the branch the
  evidence tells the project to follow is the newest releasing one within both floors, and when
  that is not the newest the line says what holds the newest back: `3.x released 3.12.0
  (2026-09-09), needs php >=8.1 above the project's php >=7.2.5; 2.x released 2.11.1 (2026-09-02);
  require ^2.11 to follow`. With no releasing branch within reach it says so and suggests nothing:
  the way forward is a PHP upgrade, not a `composer.json` line. `--format=json` gains `newest_php`,
  `newest_within_reach`, `floor_php`, `floor_source` and `reachable_branch`/`_version`/`_release`
  on the signal; `--explain` shows each branch's php requirement in the branch table and in the
  JSON `branches` rows. See [Within reach](docs/verdicts.md#within-reach).

- **`abandoned` says whether there is somewhere to go.** Packagist's marker comes with a free-text
  `replacement`, and on the weekly watch 19 of 72 abandoned packages carried one — 17 naming a
  package, two naming `Symfony` and a sentence. The verdict stays one (it is what `--fail-on`, the
  baseline, the SARIF rule and every count key on); what changes is the reading. Each finding
  carries `replacement` in `--format=json` — the named package when it is a Composer package name,
  null otherwise, the free text staying in the evidence as text — the document carries
  `"abandoned": {"total", "with_replacement"}` next to `counts`, the summary line reads
  `abandoned 7 (6 with a replacement)` where that is not zero, and the HTML page tags the row with
  the replacement and links it on the card. Both fields are optional in the schemas. See
  [Abandoned, and where to](docs/verdicts.md#abandoned-and-where-to).

### Changed

- **One reading of the installed version's date.** The lock's `time` is a release date only
  sometimes — a branch snapshot carries its commit's, a subtree split's tag the date of a commit
  its tags share, a monorepo parent can date the version instead — and each surface re-derived
  that for itself, which is how one explanation came to call a date a release four lines above
  saying it was not one. `InstalledRelease` answers it once. No output changes.

- **`old-promise` reads the date against the target's major, not its minor.** S5 held a release
  against the GA of the target PHP minor: anything older than 2024-11-21 with a `>=7.x` constraint
  was an old promise about PHP 8.4. That caught the wrong thing. A `>=7.2` cut in 2022 was written
  with PHP 8.1 on every CI matrix and differs from a `^7.2 || ^8.0` of the same day in spelling
  alone, yet only the first was flagged — and the evidence blamed the style (`has no upper bound`),
  which is Symfony's own convention. The line is now the GA of the target's major (8.0,
  2020-11-26, for any 8.x target): the release predates the major it admits, and its constraint was
  written for an older one. On the weekly watch that is 22 of the 178 `old-promise` verdicts the
  minor line produced; the other 156 were releases of the PHP 8 era. The evidence reads `released
  2020-01-11 for PHP 5 (php ">=5.3.2"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4
  untested`. In `--format=json` the signal's `ga_date` is now the major's, next to a new
  `target_major`, and `written_for_php` names the constraint's lower major. The verdict, its
  priority and `--fail-on` are unchanged; a project's count of `old-promise` will drop.

### Fixed

- **`--explain` says what the lock's date is a date of.** The `composer.lock` block called that
  date a release whenever the entry carried one, so a branch snapshot read `released 2026-09-21 ·
  branch snapshot` and a subtree split read `released 2023-06-05` four lines above the block saying
  that same date is a commit its tags share, not a release. It now reads `dated … by its commit`
  for a snapshot and `dated … by a commit its tags share` for a split, and `released …` only where
  the repository dated the version by a release. The JSON keeps its `lock.released` field, whose
  schema description now says which of the three it is carrying.
- **The report schema accepts the empty chain a run can really produce.** `chain` was declared with
  `minItems: 1`, and two ordinary runs break that: a `composer.lock` with no `composer.json` beside
  it has no direct requirements at all, so nothing reaches any package, and a lock can hold a
  package only the skipped `require-dev` asks for. Every finding of such a run was valid JSON that
  its own published schema rejected. Documents themselves are unchanged.
- **`migrate to` names a package, or says nothing.** The clause read the abandoned marker as the
  repository wrote it, so free text became an instruction — `no fix expected; migrate to Symfony`,
  `migrate to EnglishInflector from the String component` — and a repository that names the package
  itself sent the reader back to what they were leaving, while the new
  `abandoned.with_replacement` counted it. The clause, the JSON `replacement` and that count now
  all read the same validated successor: a Composer package name that is not this package's own.
  The repository's text is still shown in the evidence, as all free text is.

## [0.10.0] - 2026-09-22

### Security

- **A repository URL no longer carries its credentials into a report.** A private Composer source is
  routinely configured with a token in the URL — `https://gitlab-ci-token:$CI_JOB_TOKEN@…` is how
  GitLab CI hands a job access to one, and Bitbucket app passwords take the same shape — and
  Composer keeps it in the lock because it has to fetch with it. `--explain` printed that value
  verbatim, in the text output as the `source` line and in `--format=json` as `lock.repository`, so
  a pasted terminal buffer or an uploaded artifact carried a working token. Every repository URL
  lockrot prints now has its userinfo removed, the host kept (`Lockrot\Data\Repository\RepositoryUrl`).
  Affects 0.8.0 and 0.9.0, where `--explain` was the only path to it.

### Added

- **The report says what it was decided against.** Every verdict depends on settings the document
  did not record: the thresholds separate `stale` from `silent`, the target PHP decides whether a
  release predates it. Until now `--format=json` named the target in exactly one place — inside the
  data of an S5 signal — so a run where S5 never fired left no trace of what it aimed at, and the
  thresholds left none at all; two people comparing two reports could not tell whether they differ
  because the locks do or because the settings do. The report now carries a `run` block with the
  project's own name from composer.json — until now nothing in a report said which project it was
  about, every lock being called composer.lock, and `extra.lockrot.project` overrides it where the
  manifest has no name or where its name is not the one to publish — the target PHP, the thresholds, the `fail-on`, the
  name of the lock (never its path) and `flagged_verdicts`, the verdicts the run counted as
  findings. Each finding also carries
  `baseline`, where it stands against the baseline file — `known`, `new` or `worsened`, with the
  verdict the baseline accepted — beside the totals the `baseline` block already gave. Both are
  optional in the [published schema](docs/schema.md), so documents written by 0.9.0 still validate.
- `--format=html`: the whole run as one self-contained page. It carries the report, the release
  branches behind every finding and the baseline comparison inside a single file, so it opens from
  `file://`, uploads as one CI artifact and attaches to a ticket — no server, no network, no fonts
  or scripts fetched from anywhere. What a stream cannot show: one line per signal instead of one
  sentence with four semicolons in it, every release branch on a time axis with the installed one
  marked, advisories grouped by whether the fix is a patch on your own branch or a move to another,
  and what is new or worsened since the baseline. Filters and the open package live in the URL hash,
  the query understands `verdict:`, `priority:`, `signal:`, `severity:`, `cve:`, `direct:` and
  `dev:`, and `?` opens a glossary of every verdict and signal. A report is usually read by someone
  who did not run it, so the page closes with the two commands that produce the same page for their
  own lock. The payload's `report` key is byte
  for byte what `--format=json` writes, envelope included, so it validates against the published
  [report schema](docs/schema.md). `--all` puts every package in the page, at roughly 4 KB each;
  without it a 100-package lock lands around 250 KB. The page carries a description and an Open
  Graph card so a shared link says what was found, and no `robots` directive: whether a published
  report may be indexed is the publisher's call, made in their robots.txt, not this file's.
  See [ci.md](docs/ci.md).

## [0.9.0] - 2026-09-20

### Added

- Published JSON schemas for every document lockrot writes for a machine, and the one it reads:
  the `--format=json` report (`https://lockrot.dev/schema/report-1.json`), the `--explain`
  document (`explain-1.json`), the baseline file (`baseline-1.json`) and `extra.lockrot`
  (`config-1.json`). The report, the explanation and the baseline file now open with a `$schema`
  key naming theirs, so an editor completes a baseline file and a CI step can validate a report
  with any draft-04 validator. The files ship under `resources/` in the repository and the PHAR.
  Objects are open: under one number a document only gains fields, and the number moves only when
  a field is removed or renamed — `lockrot.schema` stays `1`. The test suite validates what the
  formatters write, and the JSON samples in the docs, against the files, with a strict copy that
  rejects undeclared fields. See [schema.md](docs/schema.md).

- A split package's release branches are dated by the monorepo they are cut from. Since 0.8.0 a tag
  sharing its commit with two others is read as undated, which stopped the false `left-behind` on
  `illuminate/macroable` but also stopped measuring a branch that really did end. The monorepo's own
  tag for the same version carries the release date, and `replace: {illuminate/contracts:
  self.version}` says the two are one release, so where a branch of the split package has no date
  the branch of the same name in its parent supplies one. A lock on `illuminate/contracts v5.8.36`
  reported nothing in 0.8.0 and now reads `branch 5.x last released 2020-08-18 (6.1 years ago,
  dated by laravel/framework); 12.x released v12.69.2`, and every other Laravel component on a
  branch of its own is measured again rather than skipped. Laravel's late security tags on 6.x, 7.x
  and 8.x are recent enough that those branches read as current under the default thresholds; being
  measured is the difference, not the verdict. The parent is taken from the lock
  when it is there; otherwise it is loaded from the configured repositories, one request, and only
  when `resources/monorepo-parents.json` lists that monorepo as carrying a package this lock needs
  dates for — `laravel/framework`, `symfony/symfony` and `cakephp/cakephp` with the components each
  replaces, refreshed by `bin/refresh-monorepo-parents`. A lock without such a package fetches
  nothing, and neither does one whose undated packages belong to no listed monorepo, which is the
  common case: `symfony/polyfill-*` is cut by a repository no Packagist package replaces. An
  install-time run that has used up its budget skips the request and the branch stays as it was.
  What a parent dates is its live `replace` list, never the snapshot. `--format=json` carries the
  parent as `dated_by` on S8 and S2, and `--explain` marks the branch rows it supplied.

## [0.8.0] - 2026-09-20

### Added

- `--explain=vendor/package`: one package, everything it was decided on, one call. The verdict and
  priority with how the package is reached; every signal with its summary and raw data (the dates
  the years were computed from, one line per advisory with what fixes it and where); the
  `composer.lock` entry; the repository metadata with the table S8 reads — every release branch,
  its highest tag and that tag's date, the installed branch marked, and a line saying so when that
  branch's highest tag is undated and S8 therefore does not measure it; the repository activity;
  the thresholds and target PHP; the run's notes. Text, or the same as JSON with `--format=json`.
  Exit 0 — it answers a question, it does not gate; a package not in the lock, or in `packages-dev`
  without `--dev`, is a configuration error (exit 2). See
  [configuration.md](docs/configuration.md#explaining-one-package).
- `left-behind` says what to require. S8 carries `suggested_constraint` — the constraint that
  follows the upstream onto the branch fixes land on, written as `composer require` writes it
  (`^8.2` from 8.2.0, `^0.4.3` below 1.0) — and for a package the project requires itself the
  evidence ends `…; 8.x released 8.2.0 (2026-09-06); require ^8.2 to follow`. A transitive
  package's parent owns that line, so there the clause stays off the row and the constraint stays
  on the signal's data.
- `no fix expected` says where to go when there is somewhere: on an `abandoned` package whose
  repository names a replacement, an advisory nothing fixes reads `no fix expected; migrate to
  symfony/mailer` — the fix is not coming here, and the package that took over is where it lands.

### Fixed

- A false `left-behind` on packages split out of a monorepo. A subtree split (`illuminate/*`,
  `symfony/*`) cuts a tag on every release whether or not the directory changed, so tags pile up on
  one commit and Packagist dates each of them by that commit: `illuminate/macroable` has 83 stable
  tags on the commit behind `v10.49.0`, all dated 2023-06-05, and a lock on 10.x read as `branch
  10.x last released 2023-06-05 (3.3 years ago)` while Laravel 10 kept releasing. A tag that shares
  its commit with two or more other stable tags is now read as undated — the date is the
  directory's, not the release's — so S8 does not measure the branch and S2 does not measure the
  package. Two tags on one commit keep their date: a re-tag, or a branch's last two releases cut
  with nothing changed between them (`symfony/*` 3.4.46 and 3.4.47), where the date is one release
  interval off at most — so a Symfony 3.4 lock still reads `left-behind` on every component. The
  cost is the other way: a split branch that really did stop and piled up more tags
  (`illuminate/contracts` 8.x, 31 on one commit) is no longer reported `left-behind`, since its last
  tag is dated the same way. Dev branches and pre-releases on a tag's commit do not count.

### Changed

- The footer's advisory line says why `composer audit` counts more. Without `--dev` it now reads
  `… the report does not flag; see composer audit (it counts packages-dev too, which this run
  skipped; pass --dev to include them)`: plain `composer audit` totals `packages-dev` and a plain
  lockrot run does not, and the difference should read as the scope it is. `--format=json` records
  the scope as `include_dev`.

## [0.7.0] - 2026-09-19

### Added

- `left-behind`: a verdict for the branch you are on, not the package. Signal S8 takes the newest
  stable release on the installed version's release branch (`1.x`; `0.3.x` below 1.0; the patch
  alone below 0.1 — what a caret constraint stays inside; pre-releases do not count), measures its age against
  `release-warn-years` / `release-high-years`, and fires only when a higher branch has released
  since and within `release-warn-years` of today — a package dead on every branch stays S2's.
  `composer outdated --major-only` says a newer major exists; S2 sees the package's newest release
  and stays quiet; this says the branch installed here gets no fixes. A branch whose highest tag
  the repository leaves undated is not measured: Packagist dates a tag by its commit, and a
  subtree split (`illuminate/*`, `symfony/*`) has undated tags and tags dated by the last change
  to the directory, years before the release. The verdict is `left-behind`
  at either threshold — between `pinned` and `old-promise` in severity, base priority `high`; the
  signal's level records whether the branch has also passed `release-high-years`. The evidence
  reads `branch 1.x last released 2021-08-03 (5.1 years ago); 2.x released v2.12.5 (2026-04-17)`
  — the higher branch whose release is newest, named as the branch fixes land on.
- Security advisories on the finding. Signal S9 carries the advisories that affect the installed
  version — the same ones `composer audit` reports, fetched through Composer's own advisory API from
  the configured repositories — with `id`, `cve`, `title`, `link`, `severity` and `reported_at`
  under `data.advisories` in `--format=json`. Advisories the project ignores in Composer's own
  configuration (`config.policy.advisories` on 2.10+, `config.audit.ignore` before) are dropped as
  `composer audit` drops them. S9 never decides a verdict. Each advisory is held against the
  highest stable tag on the installed version's branch and the package's highest stable tag, each
  only when above the installed version; one out of both ranges is already fixed, and the line says by what (`fixed by 6.3.0`; `1 fixed by
  v3.4.47, 3 fixed by v8.1.7` when they differ), with `affected_versions`, `fixed_by` and
  `fixed_on_branch` on each advisory in the JSON. On an `abandoned`, `silent` or `left-behind`
  package the advisories nothing listed fixes — on a left-behind branch, nothing listed *on the
  branch* — earn `no fix expected` (`no fix expected on 3.x` next to a fix in a higher branch) and
  the priority goes up one step, `critical` at most; a package whose every advisory is fixed by a
  listed release is not raised. The three advisories named on the line are the worst by severity. Advisories
  on packages the report does not flag stay off the rows and are totalled in the footer:
  `53 security advisories on 17 packages the report does not flag; see composer audit`. On
  Composer 2.2, under `--offline` and once the install-time budget is spent the report carries one
  note instead — each says that a priority the advisories would raise stays one step lower; a
  repository that could not be reached for advisories is a note and, under `--strict-network`,
  exit `1`.

### Changed

- A package on a quiet branch of a living upstream is now `left-behind` where it was `ok`, `stale`
  or `old-promise`; `--fail-on=left-behind` sees it. A baseline holding such a package at `stale` or
  `old-promise` — both below `left-behind` in severity — reports it `worsened`.
- A priority threshold can trip on a verdict that did not move: an advisory on an `abandoned`,
  `silent` or `left-behind` package lifts `high` to `critical`, so `--fail-on=critical` now fails
  on it. `left-behind` itself
  starts at `high`, so a run that passed `--fail-on=high` can fail on a package that was `ok`
  before. The baseline, keyed on the verdict, still calls the finding `known`.
- S2 (no stable release for years) stays quiet when the package's highest non-dev tag carries no
  release date: "last release" would otherwise date the newest tag the repository dated, and say
  nothing about the undated ones above it. Before, such a package could read `stale` or `silent`
  from a date that was not its last release's.
- `--strict-network` now covers the advisory request too: every repository that publishes
  advisories is asked, as `composer audit` asks them, including one the metadata pass never
  reached because Packagist had already answered for every name. A private repository that is
  down fails a strict run where it used to pass unnoticed.
- The counts, priority and `pulled in by:` lines fold between their `·`-separated items, never
  between a label and its number. The install-time block shows up to three notes, one per source
  that could not answer.
- The evidence line opens with the signal that decided the verdict — `pinned to branch snapshot
  dev-master` before the php-constraint clause on a `pinned` row, `repository archived on GitHub`
  right after `marked abandoned` — then the rest in signal order, then what the package pulls in.
  `--format=json` keeps the signals in signal order; GitLab fingerprints read package and verdict
  only and do not change.
- The counts line gained `left-behind`; the `verdict` enums in the config and baseline schemas, the
  SARIF rule list and `--fail-on` accept it. The JSON `schema` number stays `1`: an added enum value
  and two new signal ids, nothing removed or renamed.

## [0.6.1] - 2026-09-18

### Fixed

- `phive install somework/lockrot` works again. PHIVE takes any release asset ending in `.asc` or
  `.sig` for the GPG signature of the PHAR, last one wins, and 0.6.0's self-update signature was
  published as `lockrot.phar.sig` — so PHIVE tried to verify the archive with a JSON document and
  failed. The asset is `lockrot.phar.sig.json` from this release on, and was renamed on the 0.6.0
  release as well. The 0.6.0 archive looks for the old name, so it cannot `self-update` to this or
  any later release: it reports `release v0.6.1 has no lockrot.phar.sig asset` and leaves itself in
  place — download 0.6.1 by hand or `phive update`. Every other build, 0.5.0 included, updates as
  before.

## [0.6.0] - 2026-09-18

### Added

- `self-update` verifies the release signature. Every release from 0.6.0 on publishes
  `lockrot.phar.sig` next to the archive — an RSA signature (PKCS#1 v1.5 over SHA-384) by the new
  lockrot self-update key, in the `{"sha384": "<base64>"}` file format Composer uses for its own
  self-update — and the archive checks it with `openssl_verify()` against the public key built into
  itself before anything is written, after the sha256 check it already made. A release signed with a
  key the archive does not know, a signature over other bytes, or a signature file that is not one
  is reported and not installed. The key is RSA 4096, separate from the GPG release key (which
  still signs `lockrot.phar.asc` for people and PHIVE), published as `lockrot-selfupdate-key.pub`
  in the repository root; SECURITY.md says how it is rotated. The archive running 0.5.0 still
  checks the checksum only when it updates to 0.6.0; releases before 0.6.0 carry no `.sig`, so a
  0.6.0 archive cannot `--force` its way back to one.
- The PHAR is built reproducibly. `build/build-phar.sh` on the tagged commit — with Box 4.7.0,
  which the script downloads and checks, and the Composer version the release workflow pins at
  that tag (`tools: composer:…` in `.github/workflows/phar.yml`) — produces the archive byte for
  byte, whatever the PHP version, so a release can be verified against its own source without
  trusting the builder: dependencies come from the committed `build/phar/composer.lock`, the
  autoloader suffix, the PHAR alias and the recorded package versions are fixed, every file inside
  the archive carries the commit date of the build (or `SOURCE_DATE_EPOCH`), only an allowlist of
  lockrot's own files goes in, and Box compiles the files in sorted order. CI rebuilds every commit
  on a second machine and compares the bytes.

### Changed

- The mutation-testing gate now covers the whole source tree — self-update, the Composer
  integration, configuration, the baseline, the allowlist, the graph and the data layer — instead
  of the verdict engine, the signals, the analyzer and the output formats alone. No behaviour
  changes: the escapes it surfaced were closed with sharper tests, and a handful of statements
  that no test could ever observe were removed as redundant — in the allowlist merge, the baseline
  comparison, the dependency graph, the Composer cache adapter, the recorded HTTP client and the
  activity client.
- The PHAR's alias is `lockrot.phar`; Box used to generate a random
  `box-auto-generated-alias-<hex>.phar` for every build, which alone made no two builds compare
  equal. Its `installed.php` names lockrot as `dev-main` with no commit reference rather than the
  branch and commit of the build checkout. lockrot reads neither — its version comes from
  `src/Version.php` — and the stub's `phar://lockrot.phar/…` paths are the only use of the alias.

## [0.5.0] - 2026-09-17

### Added

- Releases are signed. From this release on, `lockrot.phar.asc` ships next to the PHAR, a detached
  OpenPGP signature by the lockrot release key (`39EC C3F6 4AE8 D06A 9A63 FD99 AB6F 7F52 AE51 3141`,
  public half in `lockrot-release-key.asc` and on `keys.openpgp.org`), together with a GitHub
  build-provenance attestation (`gh attestation verify lockrot.phar --repo somework/lockrot`). The
  release workflow verifies its own signature against the committed public key before it publishes
  anything. `phive install somework/lockrot` now works. The sha256 checksum and `self-update` are
  unchanged: `self-update` still verifies the checksum only.

### Fixed

- A package is no longer `abandoned` because an *older* release points at an archived repository.
  The repository asked about activity is the one its highest stable release names — its `source`,
  else its `support.source` — then the lock entry's, and when none of those names one the package
  is judged without a repository-activity check (Packagist's own `abandoned` flag still counts).
  phpstan/phpstan was reported `abandoned` on every project that runs lockrot with a GitHub token:
  its recent releases carry no `source`, `support.source` names the live phpstan/phpstan-src, and
  three old releases point at a one-off build repository that has since been archived. A
  `support.source` in the shape Packagist fills in by default, `<repository>/tree/<version>` (or
  `/src/<ref>` on Bitbucket), is reduced to the repository first.

## [0.4.0] - 2026-09-16

### Added

- Repository activity from GitLab and Bitbucket, next to GitHub. A package whose source lives on
  gitlab.com, on any instance in Composer's `gitlab-domains`, or on bitbucket.org now gets the S4
  signal — the newest commit on any branch, `last commit …` in the evidence — and, on GitLab when
  the run has credentials there, the archived flag behind S3 (`repository archived on GitLab`).
  GitLab is never capped anonymously; Bitbucket is capped like GitHub until Composer has
  credentials for it. Credentials: Composer's own `gitlab-token`, `gitlab-oauth`, `http-basic` and
  `bitbucket-oauth` entries are used as they are, and `GITLAB_TOKEN`/`LOCKROT_GITLAB_TOKEN` serve
  gitlab.com the way `GITHUB_TOKEN` serves github.com. S3 and S4 carry the host in their JSON
  `data`; every existing key is unchanged and the JSON `schema` stays `1`.
- `--fail-on` (and `extra.lockrot.fail-on`, `LOCKROT_FAIL_ON`) accepts a priority — `critical`,
  `high`, `medium` or `low` — next to the verdicts, so a build can fail on an abandoned direct
  production requirement and pass on the same verdict in a transitive development package. The
  error/warning line of the github, sarif and gitlab formats follows the same threshold; the
  baseline stays keyed by verdict.
- The report says how old its repository-activity data is. When any activity answer came from
  lockrot's cache, the footer's source clause reads `(package repositories; repository activity from
  lockrot's cache, up to 23 h old)` — under a day for a fresh hit, more after a failed refetch fell
  back to a stale entry or under `--offline` — instead of `(package repositories, repository
  hosts)`. The table footer had that plain clause already; the markdown footer gains it. `--format=json`
  carries the oldest cached answer's fetch time as `activity_cache_oldest_at` (null when everything
  was fetched in the run). Schema stays `1`. A cache envelope whose fetch time cannot be read now
  counts as a miss instead of an answer of unknowable age.

### Changed

- `--strict-network` now also trips when GitLab or Bitbucket is unreachable or rate-limits the
  run, the way it always has for GitHub. A repository a host does not answer for (private, renamed
  or removed) is now counted in a note — `GitHub did not answer for 2 repositories …` — instead of
  passing for a healthy one; the rate-limit note counts repositories, as it always did, and now
  says so.
- An empty `--fail-on=` is a configuration error (exit 2), like an empty `--baseline=`, instead of
  silently falling back to the next source.
- The table footer reads `Data as of … (package repositories, repository hosts)` instead of naming
  GitHub alone.
- The mutation-testing gate now covers the analyzer and every output format as well as the verdict
  engine and the signals (MSI threshold 97, from 94 over the narrower scope). No behaviour changes;
  the code the mutants proved unreachable or redundant is gone, and the behaviour they proved untested is
  now tested.

## [0.3.0] - 2026-09-16

### Added

- Transitive exposure (signal S7). A transitive finding now names every direct requirement it is
  reachable from, not only the one its shortest chain starts from: `via a › b, also via c, d` in
  the table, markdown, GitHub, GitLab and install-time formats, and `direct_dependents` on every
  finding in `--format=json` and in SARIF `properties`. Each direct requirement that pulls in flagged
  transitive packages gets an informational signal `S7` listing them, with the shortest chain from
  that requirement to each, so its evidence reads `…; pulls in 15 flagged packages: hoa/compiler
  (abandoned), …`. A `pulled in by:` line in the summary block (table, GitHub, markdown) and an
  `exposure` list in the JSON document sum that up per direct requirement. A flagged transitive
  package reached from more than eight direct requirements is shared infrastructure and is left out
  of both. S7 never decides a verdict, a priority, the exit code or the baseline comparison; the JSON
  `schema` number stays `1` (a consumer that validates `signals[].id` against `S1`–`S6` will meet
  the new value `S7`).

### Changed

- `--format=github` prints the `pulled in by:` line as a plain log line before the summary line;
  `--format=markdown` prints it as a paragraph between the table and the notes. The install-time
  block is unchanged: it shows neither the other parents nor S7.

## [0.2.2] - 2026-09-16

### Added

- Documentation for the GitHub Action, [somework/lockrot-action](https://github.com/somework/lockrot-action),
  and the Docker image it publishes, `ghcr.io/somework/lockrot`.

### Fixed

- `--format=markdown` renders everything that comes from the project or from package metadata as
  plain text: names, versions, evidence (including an abandoned package's replacement string),
  notes and the baseline file name are backslash-escaped, so a crafted `composer.lock` can no
  longer put an image, an HTML tag or Markdown link markup into the pull-request comment or job
  summary the report is posted to (a bare URL still autolinks, as plain text does on GitHub). A package name containing a backtick gets a code-span fence longer than
  the name can close.
- Repository-activity checks no longer fail with `HTTP/2 401` ("Bad credentials") when Composer
  has a `github-oauth` token for github.com — in `auth.json`, in `COMPOSER_AUTH`, or written by
  setup-php on a CI runner. Composer adds its own `Authorization` header to every
  api.github.com request in that case, lockrot added a second one, and GitHub refuses a request
  that carries two. lockrot now sends none of its own when Composer does; the token resolved from
  `GITHUB_TOKEN`/`LOCKROT_GITHUB_TOKEN` still lifts the 50-package cap. Before this, every run on
  a machine with `github-oauth` configured reported `GitHub unreachable` for every repository.

## [0.2.1] - 2026-09-16

### Fixed

- `self-update` no longer ends with `include(): zlib: data error` (or `internal corruption of phar`) and exit 255 after successfully
  replacing the archive: nothing that runs after the swap needs code from the old PHAR any more. The
  file was replaced correctly before; only the process's exit was wrong.

## [0.2.0] - 2026-09-16

### Added

- Every finding now carries a **priority** next to its verdict: `critical`, `high`, `medium`, `low`,
  or `none` for a package the report does not flag. The verdict sets a base level — `abandoned` and
  `silent` start at `critical`, `pinned` and `old-promise` at `high`, `stale` at `medium` — which
  drops one step for a transitive package and one more for a development-only one, never below
  `low`. A package nothing in the project reaches counts as transitive.
- `--format=json`: each finding gains `priority`, `direct` and `dev`, and the document gains a
  `priorities` object with all five counts next to `counts`. These are additions, so the `schema`
  number stays `1`.
- The exit code, `--fail-on` and the baseline are unchanged and still read the verdict alone.
- Every remaining format now names the priority next to the verdict, always as
  `<verdict> (<priority>)`. `--format=github` puts it in each annotation's title
  (`lockrot: abandoned (critical)`); `--format=gitlab` opens each issue's description with it;
  `--format=markdown` gains a `Priority` first column; and `--format=sarif` carries each result's
  priority as `rank` — the 0.0–100.0 number SARIF 2.1.0 defines for it, `critical` being `100.0` —
  alongside `properties.priority`, `properties.direct` and `properties.dev`.
- Nothing that decides an outcome reads the priority: the annotation level, the GitLab severity and
  fingerprint, and the SARIF `ruleId` and `level` all still follow the verdict alone, and the SARIF
  document still validates against the official 2.1.0 schema.

### Changed

- The report is ordered by priority first, then verdict severity, then direct dependencies ahead of
  transitive ones, then package name. The install-time summary shows the same order, so its 10-line
  budget now goes to the packages that apply most directly to the project.
- The default `table` format is now a width-aware list grouped by priority instead of a five-column
  box table that only read on a very wide terminal. Groups come highest priority first, headed by
  their level and count (`not flagged (N)` for the rows `--all` adds); each finding takes one line
  for its verdict, package, version and requirement chain and as many as it needs for its evidence,
  wrapped to the terminal with continuation lines indented past the verdict column. Critical and
  high rows are marked in red, medium in yellow. The summary block keeps its wording and order and
  gains a `priority: …` line right after the counts, printed whenever the run flagged anything; its
  lines wrap to the terminal too, at the full width and with no indent. The format id and the
  machine formats are unchanged.
- The terminal width is taken from `COLUMNS` when it is set, otherwise from the console itself,
  falling back to 120 columns and never going below 40.
- The evidence for the `abandoned` verdict now reads `marked abandoned by its repository` or
  `marked abandoned in composer.lock`, and the SARIF rule description says the same, so *flagged*
  carries one meaning across lockrot's output: a finding lockrot itself raised.
- Package metadata and community files for the first Packagist release: a `description` and
  `keywords` written for search, a `homepage`, `support.docs` and `support.security`, plus
  `SECURITY.md`, `CONTRIBUTING.md`, issue and pull-request templates, and a Dependabot
  configuration.

### Fixed

- `--format=json`, `sarif`, `gitlab`, `github` and `markdown` are now written raw, so a `<` in a
  constraint or a package name reaches the parser on the other end exactly as lockrot produced it.

## [0.1.0] - 2026-09-15

- `composer lockrot` (alias `composer rot`) command reporting dependency rot in `composer.lock`.
- Signals S1–S6: abandoned flag, no stable release, repository archived, no repository push,
  old release with an open-ended PHP constraint, and pinned branch/hash snapshots.
- Built-in finished-package allowlist (`resources/finished-packages.json`) plus a project-level
  `extra.lockrot.ignore` list with mandatory reason and optional expiry.
- Install-time summary on Composer's `PRE_OPERATIONS_EXEC`: `composer require`/`update`/`install`
  print a block of at most 10 lines for the packages the transaction is about to install or update,
  above Composer's own operations list. Bounded by a 5-second budget, and it never fails an install
  — an error lockrot cannot interpret becomes one `lockrot: install-time check skipped: …` line.
  The block is silent only when the transaction was both checked and clean: if nothing is flagged
  but a lookup failed (exhausted budget, unreachable repository), a shorter
  `lockrot: N of M changed packages could not be checked` block carries the reason instead.
- Two new `extra.lockrot` keys: `install-time` (`on`/`off`, default `on`) and `install-time-strict`
  (default `false`), which applies `fail-on` at install time and stops the transaction before any
  operation runs. `LOCKROT_DISABLE=1` still silences everything.
- The install-time pass's time budget is configurable via `extra.lockrot.install-time-budget`
  (integer seconds, 1–120, default 5); like the other install-time keys it has no CLI option or
  environment override.
- The install-time summary's `via` chain now resolves even when the lock on disk predates the
  transaction's own packages — a `--dry-run` update, or a project's very first lock — instead of
  showing no chain for a package new to the dependency graph.
- The "Repository metadata unavailable" note now breaks out each distinct reason with its own
  count when a run hits more than one, e.g. `Repository metadata unavailable for 4 packages: not
  checked: install-time budget exhausted (3); connection refused (1)`, instead of only naming the
  first reason it reached.
- `table` (default) and `--format=json` output.
- `--format=github` prints GitHub Actions workflow commands, one per finding, so flagged packages
  appear as annotations on their own line of `composer.lock` in a pull request; findings at or
  above `fail-on` are annotated as errors, the rest of the flagged ones as warnings, and the rows
  only `--all` shows as notices.
- `--format=sarif` prints a SARIF 2.1.0 document for `github/codeql-action/upload-sarif`, with one
  rule per verdict, `composer.lock` line numbers, and stable per-package fingerprints so code
  scanning can track a finding across runs. Exit codes are unchanged by the chosen format.
- Baseline file: `composer lockrot --generate-baseline` writes `lockrot-baseline.json` next to
  `composer.json` (path configurable via `--baseline` or `extra.lockrot.baseline`), and every later
  run compares against it, so `--fail-on` only trips on findings that are new or worse than the
  ones the project accepted. Matching is by package name, so a version bump that keeps the same
  verdict stays accepted; `first_seen` survives regeneration; baseline entries whose package has
  left the lock are reported as stale, never failed on. The table gains a
  `baseline: N known · M new · K worsened · S stale` line and marks rows `(baseline)`/`(was stale)`,
  the JSON report a `baseline` object, and SARIF a `properties.baseline` per result — with known
  findings reported at notice/note level so annotations keep matching the exit code.
  `install-time-strict` honours the same comparison. The baseline is the only file lockrot writes,
  only on that explicit flag, and it is written atomically; an unreadable, schema-invalid or
  unwritable baseline is exit 2, never a silently ungated run.
- `--format=gitlab` prints a GitLab Code Quality JSON report (one issue per flagged finding, every
  finding with `--all`), so `artifacts.reports.codequality` puts findings inline in a merge
  request's diff. Severity mirrors the GitHub/SARIF level (`major`/`minor`/`info`); each issue's
  fingerprint is a stable hash of the package name and verdict so it survives a version bump or a
  reformatted lock. Report notes are not representable in this format and are dropped; use
  `--format=json` for them.
- `--format=markdown` prints a PR-comment-shaped report — heading, a findings table, notes as
  bullets, a `<sub>` summary footer — for `composer lockrot --format=markdown > comment.md` piped
  into `gh pr comment --body-file`. A clean run prints a one-line heading with no table; with a
  baseline, a second line carries the same known/new/worsened/stale counts as the table format, and
  a verdict is bold only when it is not already accepted by the baseline.
- Exit codes 0/1/2 driven by `--fail-on`, with network failures defaulting to exit 0 unless
  `--strict-network` is set.
- Package metadata is loaded through the repositories configured for the project
  (`ComposerRepository::loadPackages()`), in their configured order — Packagist by default, but
  Private Packagist, Satis instances and mirrors are honoured the same way, with Composer's own
  authentication and proxy settings. The first repository to answer for a package name wins, and a
  repository that could not answer hands the name on to the next one. Composer's own metadata cache
  is reused and revalidated on every run; GitHub repository-activity responses keep a fixed 24-hour
  cache in Composer's cache directory.
- Repository metadata is loaded in two passes: tagged releases first, the `~dev` branch file only
  for packages with no tagged release — roughly half the requests on a cold cache (wallabag:
  403 → 206).
- `--offline` serves both from cache and never opens a connection, including when lockrot runs as a
  Composer plugin. A package with no cached metadata is reported as unavailable rather than as
  absent from the repository, so an empty cache cannot read as a clean result.
- Optional GitHub token (`GITHUB_TOKEN`/`LOCKROT_GITHUB_TOKEN`/Composer `github-oauth`) for
  repository activity signals; runs without one at a reduced candidate budget.
- Standalone PHAR build (`build/lockrot.phar`) for use without adding a Composer dependency.
- `lockrot.phar self-update` replaces the running PHAR with the latest GitHub release: it reads
  `releases/latest`, compares the tag against the running version with Composer's own semver, then
  downloads the archive and the `lockrot.phar.sha256` published beside it, refuses any download
  whose hash does not match, checks that the PHP runtime can open it, and swaps it in with the old
  file's permissions. `--check` reports without downloading and exits 1 when an update is available,
  so CI can notice a release; `--force` reinstalls the current version. Every failure is exit 2 with
  the running PHAR untouched and the temporary file removed; there is no rollback in 0.1, since
  earlier releases stay downloadable from GitHub. The command exists only in the PHAR — a plugin
  install is updated with `composer update somework/lockrot` — and it needs write access to the
  PHAR's own directory and nothing else.
- The PHAR now answers to command names (`lockrot`, still the default, and `self-update`) instead of
  being a single-command application. Composer's own commands and the inspected project's
  `composer.json` scripts are deliberately not registered, so `lockrot.phar list` shows lockrot's
  two commands and nothing in that project can be installed, updated or executed through the PHAR.
- Configuration in `extra.lockrot` is validated against `resources/lockrot-config.schema.json`;
  threshold values must be integers.
- Releases publish `lockrot.phar` with a `lockrot.phar.sha256` checksum; GPG signing and a Docker
  image are deferred to a later release.
- The standalone PHAR (and any lock-only run without an existing Composer instance) sets
  `COMPOSER_ROOT_VERSION=1.0.0` when the variable is unset: lockrot never reads the root package's
  version, so Composer's VCS probing (`git`/`hg`/`fossil`/`svn`, about 5 of the 6 seconds a PHAR run
  took on a lock-only directory) and its "could not detect the root package version" warning on
  stderr are skipped.
- The "GitHub token not set" note counts packages whose repository activity was checked, not
  distinct repositories, so packages sharing one repository are no longer under-reported.

[Unreleased]: https://github.com/somework/lockrot/compare/v0.13.0...HEAD
[0.13.0]: https://github.com/somework/lockrot/compare/v0.12.0...v0.13.0
[0.12.0]: https://github.com/somework/lockrot/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/somework/lockrot/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/somework/lockrot/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/somework/lockrot/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/somework/lockrot/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/somework/lockrot/compare/v0.6.1...v0.7.0
[0.6.1]: https://github.com/somework/lockrot/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/somework/lockrot/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/somework/lockrot/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/somework/lockrot/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/somework/lockrot/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/somework/lockrot/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/somework/lockrot/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/somework/lockrot/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/somework/lockrot/releases/tag/v0.1.0
