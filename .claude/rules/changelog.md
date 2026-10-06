---
paths:
  - "CHANGELOG.md"
---
# Writing CHANGELOG.md

The changelog is for people who upgrade lockrot: the CI owner, the integrator of a format, the
security reviewer. It follows [Keep a Changelog 1.1](https://keepachangelog.com/en/1.1.0/). Every
entry says what changed on the surface the reader uses. A breaking or security entry also says who
is affected and what to do. Do not write an entry that serves only contributors (tests, CI,
fixtures, refactors, corpus tooling).

## Release structure

```text
## [X.Y.Z] - YYYY-MM-DD
<Summary, at most four lines: who must act (or "Read every **Breaking:** entry"), then one compatibility line.>
### Verdict changes · ### Security · ### Added · ### Changed · ### Deprecated · ### Removed · ### Fixed
```

- Keep the heading exactly `## [X.Y.Z] - YYYY-MM-DD`: a test parses it, and a pre-release heading
  needs a change to that test first. `## [Unreleased]` stays on top.
- Every minor release has `### Verdict changes`, with the body `None.` when nothing moved. Omit
  other empty sections.
- Keep the section order: what can break a pipeline comes first.
- A leak of a name, path or credential into a report goes under `### Security`, never `### Fixed`.
  So does a verification gap or a change to what lockrot writes or contacts.
- The summary's compatibility line ("the schemas gained optional fields only") replaces
  per-entry "X is unchanged" sentences, except for alert fingerprints and exit codes.

## Entry shape

```text
- **<Surface>:** <what changed, in the reader's terms>. [<Who is affected>. <What to do>.]
  ([<Page title>](docs/<page>.md#<anchor>))
```

- `<Surface>` is what a user names: `CLI`, `Config`, `Baseline`, `PHAR`, `self-update`,
  `Install-time`, `PHP API`, `Docs`, `All formats`, or a format (`json`, `sarif`, `gitlab`,
  `github`, `markdown`, `table`, `html`). A change to an environment variable is `Config`.
- A change that can break a setup that works starts `**Breaking:**`, sorts first in its section and
  ends with a sentence that starts `Action:`. A change to what an option, variable, field or
  exit code means is breaking even when the name stays.
- Name the changed option, key, field or command within the first eight words after the surface.
- An entry has at most five lines at 100 columns, and the link line counts as one at any width.
  Aim for three. If you need more, write the missing docs section.
- One change is one entry, in its final state. A family of fields with one purpose is one entry
  with an inline list. A follow-up fix in the unreleased section merges into the entry that it
  fixes. The reader upgrades from the previous tag, not through your commits.
- Give the reason in one clause, only when the reader needs it to decide.
- A home outside `docs/` gets a GitHub URL pinned to the tag.
- Define or avoid internal jargon ("stranded archive"): the reader has not read the code.
- A bump of the vendored lockrot-report page is one `### Changed` entry, surface `html`: its
  version, its changelog link, and at most one line on the report fields it reads.

## What an entry never contains

Never:

- PHP class, method, test, fixture or script names, outside the `PHP API` surface.
- How the change was tested, "the schema snapshot is refreshed".
- Anything outside this release: "not tagged yet", "the next release will", "does not show it yet".
- Commit hashes or branch links.

## Unreleased and released sections

- A pull request with a user-visible change writes its entry under `## [Unreleased]` in final
  form. In `CHANGELOG.md`, the release pull request only renames the heading, writes the summary
  and the tag in pinned URLs, opens a new `## [Unreleased]` and updates the compare links
  (CONTRIBUTING, "Cutting a release").
- Until its tag exists, you can rewrite a release section entirely: merge, drop superseded
  entries, move between sections, shorten.
- After the tag the section is frozen. Fix only a broken link, anchor or rendering fault in
  it. Correct a wrong statement with a `Fixed` entry in the next release.
