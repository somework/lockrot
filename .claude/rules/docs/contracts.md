---
paths:
  - "README.md"
  - "CONTRIBUTING.md"
  - "SECURITY.md"
  - "CHANGELOG.md"
  - "mkdocs.yml"
  - "docs/**/*.md"
---
# What the docs must not break

Code, tests, the site build and other repositories depend on the docs' structure. Breaking one of
these fails CI or a published link, so check it before committing.

- **Anchors are API.** Code builds URLs from them (`docs_url` = `lockrot.dev/notes/#<code>`,
  `phar.md#reinstalling-by-hand` in self-update's error, `verdicts.md#the-signals` in the html
  page), and external pages link the rest. Never delete a heading id; when renaming a heading,
  keep the old id explicitly: `## New title {#old-id}`. Give a heading that is only code an
  explicit id.
- **Relative links.** Link `verdicts.md#priority` from `docs/`, `docs/verdicts.md#priority` from
  root files: `mkdocs build --strict` validates those, anchors included. Absolute
  `https://lockrot.dev/...` links are unchecked; use them only in `README.md` (read on GitHub and
  Packagist), page-level where possible.
- **Python-Markdown nesting.** Nested lists and paragraphs inside a list item are indented four
  spaces, and after an item's paragraph the next item needs a blank line; two-space nesting
  renders flat. `tests/Integration/CompatibilityPageTest.php` checks `docs/*.md`.
- **JSON samples are validated.** Every ```` ```json ```` block in `docs/*.md` must decode (a line
  holding only `…` is allowed) and, if it is a report, explanation, baseline or `composer.json`,
  validate against the published schema. Use ```` ```text ```` for fragments.
- **Text that tests read.** Read the test before changing its text:
    - `compatibility.md` "### Open sets", the draft admonition, closed-set order; the severity
      ladder in backticks in `verdicts.md` — `tests/Unit/Verdict/ClosedSetsTest.php`,
      `tests/Integration/CompatibilityPageTest.php`.
    - `schema.md` "## Open sets", the paragraph starting "Objects are open" — `ClosedSetsTest.php`.
    - `notes.md` sections and its nav entry — `tests/Integration/NotesPageTest.php`.
    - The reserved namespace in `CONTRIBUTING.md` — `tests/Unit/PublicApiTest.php`.
    - The key fingerprint in `SECURITY.md` — `tests/Unit/SelfUpdate/ReleaseKeyTest.php`.
    - Release headings in `CHANGELOG.md` — `tests/Integration/SchemaEvolutionTest.php`.
- **Nav.** Every page in `docs/` is in `mkdocs.yml` nav or `exclude_docs`, or the strict build
  fails.
- **Front matter.** Each page keeps `title` (topic first) and `description` (the page's job for a
  search result, about 160 characters, no counts or time words).

## Checks

```sh
python3 -m venv .venv && .venv/bin/pip install -r docs/requirements.txt   # once
.venv/bin/mkdocs build --strict
vendor/bin/phpunit tests/Integration/CompatibilityPageTest.php tests/Integration/NotesPageTest.php \
  tests/Unit/Verdict/ClosedSetsTest.php tests/Unit/PublicApiTest.php tests/Unit/SelfUpdate/ReleaseKeyTest.php
vendor/bin/phpunit --filter testTheDocsSamplesValidate tests/Integration/JsonSchemaConformanceTest.php
```
