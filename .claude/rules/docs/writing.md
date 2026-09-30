---
paths:
  - "README.md"
  - "CONTRIBUTING.md"
  - "SECURITY.md"
  - "docs/**/*.md"
---
# Writing the documentation

Covers `README.md`, `CONTRIBUTING.md`, `SECURITY.md` and `docs/*.md` (lockrot.dev, built by
mkdocs-material from the newest release tag). Where each topic lives: `topic-homes.md`. What
breaks tests and the build: `contracts.md`. `docs/example-run.md` is a recording: re-record it,
never hand-edit it.

## Readers and where they land

| Reader | Question they bring | Lands on |
|---|---|---|
| Evaluator | What does this find that `composer audit` does not, is it worth trying? | README, `docs/index.md` |
| Developer triaging a finding | Why is this package flagged, what do I do? | `verdicts.md`, `baseline.md`, `configuration.md` |
| Maintainer of a flagged package | Which rule flagged my package, what clears it? | `verdicts.md` |
| CI owner | Which step fails the build on the right thing, what does each exit code mean? | `ci.md`, `baseline.md` |
| Integrator | Which fields are stable, where is the schema, which sets may grow? | `schema.md`, `notes.md`, `compatibility.md` |
| Security reviewer | What does lockrot read, write, contact, and how do I verify the PHAR? | `SECURITY.md`, `phar.md` |
| Contributor | How do I build, test and record fixtures without breaking a gate? | `CONTRIBUTING.md` |

- Write each page for its row. Contributor detail (tests, fixtures, classes, scripts) goes only in
  `CONTRIBUTING.md`: users cannot act on it, and it changes with every refactor.
- The first screen (opening paragraph up to the first `##`) lets the reader act or decide: a
  command, a snippet, a table of values or the rule itself. At most three opening sentences, naming
  the reader's job. Bad: "This page describes the configuration." Good: "Every `extra.lockrot`
  key, its default and what it changes."
- `docs/index.md` routes by task ("I want to …"), and reaches every page in the `mkdocs.yml` nav.
  Nav labels, page titles and index labels name a page the same way.

## One page, one mode (Diátaxis)

Mixing modes is what makes a page long: a how-to that also explains and lists every case serves
nobody fast.

| Mode | Pages | Shape |
|---|---|---|
| Tutorial | README "First run" | Goal, prerequisites, numbered steps with command and output, next step |
| How-to | `ci.md`, `baseline.md`, `install-time.md`, `phar.md` | Working snippet on the first screen, variants as `##`, failures as symptom → cause → fix |
| Reference | `configuration.md`, `schema.md`, `notes.md`, `compatibility.md` | Tables first (name, type, default, effect, link); prose only where a cell cannot hold it |
| Explanation | `verdicts.md`, `internals.md` | The question, the model, one recorded example, limits, links to reference |
| Landing | README, `docs/index.md` | Value in one sentence, install, one real run, routes onward |

A how-to section that grows into reference moves to the reference page and leaves a sentence and a
link. Pages other than the landings end with `## Related`: links, each with a clause saying why.

## Concision

- One paragraph, one idea, at most six wrapped lines. Conditions or cases become a list or table.
- Aim for sentences under about 26 words; split any with more than two semicolons or dashes.
- Present tense, active voice, second person, condition first: "With `COMPOSER=alt.json`, lockrot
  reads `alt.lock`."
- State the contract — inputs, outputs, guarantees, limits — not how the code gets there. The
  mechanism changes under refactors; the contract does not.
- No sentences defending the design, no lists of what something does not affect.
- Keep the file's hand-wrap width; do not reflow paragraphs you did not change (noisy diffs hide
  the real edit).

## Terms

Use one word per concept, because readers search for it: a *finding* is any entry in the report's
`findings`; a *flagged* finding has a verdict from `abandoned` through `stale` (defined in
`verdicts.md`). The service behind a package's source is a *repository host*, not "code host" or
"forge" (`forge_id` stays as the field name).

## Anti-fragility

Write nothing that becomes false while the code stays the same.

- **No time words** outside `CHANGELOG.md`: "now", "no longer", "used to", "until now", "not yet",
  "currently", "recently". A page is read years later; the changelog is where time lives. Product
  states (`new` against the baseline, `--today`) are not time words.
- **Versions only where a reader needs them:** a `schema.md` field row (when it appeared), a
  `compatibility.md` policy with its effective version, and one `!!! note "Older releases"` in the
  canonical section for behaviour that differs in archives users still run.
- **No counts of sets.** Not how many formats, verdicts, signals, notes or keys exist; say "every
  output format" and link the list. A heading that had a count keeps its id when renamed.
- **Numbers only from an owner:** a default next to its key, a contract value the code owns, or a
  dated measurement naming its input. Never corpus, test or fixture sizes.
- **Public names only** outside `CONTRIBUTING.md`: options, exit codes, environment variables,
  config keys, JSON fields, published files and URLs. No PHP classes, methods, tests, scripts.
- **Recorded output only.** Console blocks come from a committed recording, marked "Abridged" when
  cut; relative ages ("3.6 years ago") appear only there. Never invent output.
- **No other repository's internals** — name it by version and link it.
- **No line numbers, byte sizes, or paths inside other repositories.** Link a heading.
- **One home per fact** (`topic-homes.md`): a copied paragraph drifts from its original.

Exception: a fact may be written in full in two places when a test reads every copy.
