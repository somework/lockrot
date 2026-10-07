---
paths:
  - "README.md"
  - "CONTRIBUTING.md"
  - "SECURITY.md"
  - "docs/**/*.md"
---
# Writing the documentation

## Readers and where they land

| Reader | Question they bring | Lands on |
|---|---|---|
| Evaluator | What does this find that `composer audit` does not, is it worth a try? | README, `docs/index.md` |
| Developer who triages a finding | Why is this package graded, what do I do? | `verdicts.md`, `baseline.md`, `configuration.md` |
| Maintainer of a graded package | Which flag graded my package, what clears it? | `verdicts.md` |
| CI owner | Which step fails the build on the right thing, what does each exit code mean? | `ci.md`, `baseline.md` |
| Integrator | Which fields are stable, where is the schema, which sets can grow? | `schema.md`, `notes.md`, `compatibility.md` |
| Security reviewer | What does lockrot read, write, contact, and how do I verify the PHAR? | `SECURITY.md`, `phar.md` |
| Contributor | How do I build, test and record fixtures without breaking a gate? | `CONTRIBUTING.md` |

- Outside `CONTRIBUTING.md`, name only the public surface (options, exit codes, environment
  variables, config keys, JSON fields, published files, URLs), never a class, a test or a script.
- The text before the first `##` has at most three sentences. It holds a command, a snippet, a
  table of values or the rule itself. An Explanation page can open with its question. Bad: "This
  page describes the configuration." Good: "The first source that sets a value wins: option, then
  environment variable, then `extra.lockrot`."

## One page, one mode (Diátaxis)

| Mode | Pages | Shape |
|---|---|---|
| Tutorial | README "First run" | Goal, prerequisites, numbered steps with command and output, next step |
| How-to | `ci.md`, `baseline.md`, `install-time.md`, `phar.md` | A snippet that works, before the first `##`, variants as `##`, failures as symptom → cause → fix |
| Reference | `configuration.md`, `schema.md`, `notes.md`, `compatibility.md` | Tables first (name, type, default, effect, link). Prose only where a cell cannot hold it |
| Explanation | `verdicts.md`, `internals.md` | The question, the model, one recorded example, limits, links to reference |
| Landing | README, `docs/index.md` | Value in one sentence, install, one real run, routes onward |

When a how-to section lists every value of an option or key, move the list to the reference page.
Leave a link, unless `topic-homes.md` makes the how-to its home. End each page with `## Related`,
except the landings, `changelog.md` and `example-run.md`. Each link there gets a clause that names
what the reader finds.

## Docs only

- Write to the reader as "you". Outside the Explanation pages and `CONTRIBUTING.md`, state inputs,
  outputs, guarantees and limits. Do not describe internal steps, caches or data structures.
- **Versions of a change only where a reader needs them.** These are a `schema.md` field row, a
  `compatibility.md` policy with its effective version, and one `!!! note "Older releases"` in the
  canonical section. An action for older releases goes in that section's text, with the version as
  its condition. Floors, requirements and pins are not history.
- **Recorded output only.** A console block matches a recording that the same diff changes
  (`docs/example-run.md`, a golden file), marked "Abridged" when cut. Or it is a template with a
  `<placeholder>` for every value. Re-record `docs/example-run.md`, never hand-edit it. Relative
  ages ("3.6 years ago") appear only in recordings. A file or JSON sample can use placeholders
  (`x.y.z`) and example values when it validates (`contracts.md`).
