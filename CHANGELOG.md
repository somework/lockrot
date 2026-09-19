# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
  its commit with another stable tag is now read as undated — the date is the directory's, not the
  release's — so S8 does not measure the branch and S2 does not measure the package. The cost is
  the other way: a split branch that really did stop (`illuminate/contracts` 8.x) is no longer
  reported `left-behind` either, since its last tag is dated the same way. Dev branches and
  pre-releases on a tag's commit do not count.

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

[Unreleased]: https://github.com/somework/lockrot/compare/v0.8.0...HEAD
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
