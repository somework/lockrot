---
paths:
  - "CHANGELOG.md"
---
# Writing CHANGELOG.md

The changelog is for people upgrading lockrot, not for the people who built the release. It follows
[Keep a Changelog 1.1](https://keepachangelog.com/en/1.1.0/) and is included in the site by
`docs/changelog.md`, so `.claude/rules/docs/contracts.md` applies to it too. Every entry answers
three questions: what changed on the surface I use, does it affect me, what do I do. Anything else
goes in a docs page, a commit message or the PR — an upgrader reads dozens of entries and drops the
ones that explain the build.

Readers: the CI owner ("can this turn my pipeline red, must I change anything?"), the integrator
(json, sarif, gitlab, github, baseline, html: which fields appeared, does my validator still pass?),
the security reviewer (what lockrot writes, contacts, verifies or exposes). An entry that serves
only contributors — tests, CI, fixtures, refactors, corpus tooling — is not written.

## Release structure

```text
## [X.Y.Z] - YYYY-MM-DD
<Summary, at most four lines: who must act and which entries to read, then one compatibility line.>
### Verdict changes · ### Security · ### Added · ### Changed · ### Deprecated · ### Removed · ### Fixed
```

- Keep the heading exactly `## [X.Y.Z] - YYYY-MM-DD`: `tests/Integration/SchemaEvolutionTest.php`
  parses it. `## [Unreleased]` stays on top. Date the section on the day the tag is made.
- Every minor release has `### Verdict changes`; its body is `None.` when nothing moved, so a
  missing heading and "nothing changed" never look alike. Leave other empty sections out.
- Keep the section order: what can break a pipeline comes first.
- A leak of a name, path or credential into a report, a verification gap, or a change to what
  lockrot writes or contacts goes under `### Security`, never `### Fixed`.
- The summary's compatibility line ("the schemas gained optional fields only") replaces
  per-entry "X is unchanged" sentences; an entry mentions compatibility only as an exception.

## Entry shape

```text
- **<Surface>:** <what changed, in the reader's terms>. <Who is affected>. <What to do>.
  ([<Page title>](docs/<page>.md#<anchor>))
```

- `<Surface>` is what a user names: `CLI`, `Config`, `Baseline`, `PHAR`, `self-update`,
  `Install-time`, `Docs`, or a format (`json`, `sarif`, `gitlab`, `github`, `markdown`, `table`,
  `html`). A change to an environment variable is `Config`.
- A change that can break a working setup starts `**Breaking:**`, sorts first in its section and
  ends with a sentence starting `Action:`. Changing what an existing option, variable, field or
  exit code means is breaking even when the name stays.
- Name the changed option, key, field or command in the first few words.
- Aim for three wrapped lines at 100 columns; five is the maximum. Needing more means a docs
  section is missing: write it in the same branch and link it.
- One change is one entry, in its final state: a family of fields with one purpose is one entry
  with an inline list, and follow-up fixes within the unreleased section merge into the entry they
  fix. The reader upgrades from the previous tag, not through your commits.
- Give the reason in one clause only when the reader needs it to decide. Design history belongs
  in the PR.
- Link the topic's canonical home (`.claude/rules/docs/topic-homes.md`) as a relative
  `docs/<page>.md#<anchor>` link: the site build validates those, absolute lockrot.dev links it
  cannot.
- Define or avoid internal jargon ("stranded archive"); the reader has not read the code.

## What an entry never contains

These all go stale or mean nothing to an upgrader:

- PHP class, method, test, fixture or script names — name the public surface instead.
- How the change was tested, "the schema snapshot is refreshed", lists of what did not change.
- Measured numbers: corpus sizes, fixture counts, file sizes, timings.
- The current behaviour of another component (another repository's pages or files, a library's
  internals). Give its version, the contract with lockrot and a link to its changelog.
  A 0.13.0 entry once said the vendored page "does not read it yet"; it was false one bump later.
- Anything outside this release: "not tagged yet", "the next release will", "does not show it yet".
- Line numbers, commit hashes, branch links.

## The renderer entry

A bump of the vendored lockrot-report page is one `### Changed` entry, surface `html`: the renderer
version, a link to its changelog, and at most one more line naming which report fields the page
now uses. Nothing about its layout, tabs or wording — that is the renderer's changelog.

## Unreleased and released sections

- A pull request with a user-visible change writes its entry under `## [Unreleased]` in final
  form. The release pull request renames the heading, writes the summary, changes nothing else.
- Until its tag exists, a release section may be rewritten entirely: merge, drop superseded
  entries, move between sections, shorten. Rewrite the entry; never append a revision note.
- After the tag the section is frozen; only a broken link, anchor or rendering fault is fixed in
  it. A wrong statement is corrected by a `Fixed` entry in the next release.

## Check before committing

`mkdocs build --strict` (links and anchors), and `vendor/bin/phpunit
tests/Integration/SchemaEvolutionTest.php` when a release heading changed.
