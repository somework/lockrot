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

Code, tests, the site build and other repositories read the docs' structure. A break fails CI or
a published link.

- **Anchors are API.** Code builds URLs from them (`docs_url` = `lockrot.dev/notes/#<code>`), and
  external pages link the rest. Never delete a heading id. When you rename a heading in `docs/`,
  keep the old id: `## New title {#old-id}`. In a root file, which GitHub renders, put
  `<a id="old-id"></a>` on the line before the new heading. Give a heading that is only code an
  explicit id.
- **Relative links.** Link `verdicts.md#priority` from `docs/`, `docs/verdicts.md#priority` from
  root files. Check links in the other root files by hand (CONTRIBUTING, "Writing the changelog and
  the docs"). Absolute links to lockrot.dev pages go only in `README.md` (read on GitHub and
  Packagist). Published files (schemas, the PHAR) keep their URLs.
- **Python-Markdown nesting.** Indent nested lists and paragraphs in a list item four spaces. Put
  a blank line after an item's paragraph. Two-space nesting renders flat. A test checks
  `docs/*.md`.
- **JSON samples are validated.** Every ```` ```json ```` block in `docs/*.md` must decode (a line
  that holds only `…` is allowed). A report, explanation, baseline or `composer.json` sample must
  also validate against the published schema. Use ```` ```text ```` for fragments.
- **Text that tests read.** Before you change a page or `mkdocs.yml`, search `tests/` for its file
  name, and read each test that reads it. Tests that glob `docs/*.md` check every page.
- **Nav.** Every page in `docs/` is in `mkdocs.yml` nav or `exclude_docs`, or the strict build
  fails.
- **Front matter.** Each page keeps `title` (topic first) and `description` (the page's job for a
  search result, about 160 characters).
