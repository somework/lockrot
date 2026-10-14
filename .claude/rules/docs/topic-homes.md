---
paths:
  - "README.md"
  - "CONTRIBUTING.md"
  - "SECURITY.md"
  - "CHANGELOG.md"
  - "docs/**/*.md"
---
# One home per topic

Each topic that recurs has one canonical section (the rule: `../writing.md`, "Less text"). Write a
fact in full twice only when a test reads every copy. Link the home's anchor when the home is a section.

| Topic | Home | Elsewhere |
|---|---|---|
| Exit codes of a run, and what trips `--strict-network` | `ci.md#exit-codes` | One line and a link. `compatibility.md` states only the promise. `notes.md` says per note whether it counts |
| The `--fail-on` choice, and the value for snippets | `ci.md` (opening) | `configuration.md#fail-on-values` lists accepted values only. Every snippet uses the recommended value |
| How each format marks a finding (levels, SARIF ranks) | `ci.md#how-each-format-marks-a-finding` | `compatibility.md#severity-mapping`: the promise and a link |
| Each output format | its `ci.md` section | README: one list line |
| Verdicts, and what "finding" / "graded" mean | `verdicts.md#the-nine-verdicts` | README: one-line meanings of the flags, no thresholds |
| The score, and how to read a score line | `verdicts.md#score` | The rules of each score model: `verdicts/model-<n>.md`, one section per rule (`#score-<rule id>`) |
| Signals, and S6 data (`#what-s6-carries`) | `verdicts.md#the-signals` | Links |
| Flags, and the `lead` of a finding | `verdicts.md#flags` | Links |
| Moving from report-1 to report-2 | `schema.md#migrating-from-report-1` | CHANGELOG and `changes/0.14.md`: links |
| Upgrading from 0.13: baselines, installs, staying on 0.13 | `changes/0.14.md` | `baseline.md#moving-from-a-baseline-1-file` holds the commands. `phar.md` holds what 0.13 does with 0.14 files |
| Priority, the alias of the grade | `verdicts.md#priority` | One sentence |
| Security advisories and Composer's advisory settings | `verdicts.md#security-advisories` | `configuration.md#the-allowlist`: one sentence and a link |
| A finding's move (`next_step`) | `verdicts.md#move` | Links |
| Rot depth, the `depth:` line | `verdicts.md#rot-depth` | `schema.md` field rows |
| Composer's abandoned ignore list | `verdicts.md#abandoned` | `configuration.md#composer-abandoned-ignore`: the keys, how the list differs from an `ignore` entry, and a link |
| `unchecked` and S10 | `verdicts.md#what-was-not-checked` | Links |
| Transitive exposure, `unattributed` | `verdicts.md#transitive-exposure` | `schema.md` field rows |
| Libyears | `verdicts.md#libyears` | One sentence |
| Baseline: buckets, the file's fields, `--generate-baseline` and its exit codes | `baseline.md` | One sentence in README and `ci.md`, one line in `ci.md#exit-codes` |
| `extra.lockrot` keys and defaults | `configuration.md#extralockrot-keys` | README: a few common keys and a link |
| Environment variables | `configuration.md#environment-overrides` | `SECURITY.md` links it for what lockrot reads |
| `--output`, `--explain`, allowlist | `configuration.md#writing-reports-to-files`, `#explaining-one-package`, `#the-allowlist` | `ci.md#several-reports-from-one-run`: the snippet |
| Install-time block | `install-time.md` | `configuration.md` key rows |
| Run notes | `notes.md` (section id = note code) | `schema.md`: the `note_details` shape |
| Schema URLs, number, open sets, fields | `schema.md` (the open-set list: `#open-sets`) | `compatibility.md` states what is frozen and links the list |
| `origin`, `replacement_url`, what a report reveals | `schema.md#where-a-package-came-from` | `SECURITY.md`: a link |
| What 1.0 freezes, public surface, verdict-change policy, deprecation | `compatibility.md` | `CONTRIBUTING.md#backward-compatibility`: contributor duties and a link |
| Reserved names | `compatibility.md#names-reserved-for-extensions` | `CONTRIBUTING.md` keeps the backticked namespace that a test reads |
| What lockrot reads, writes and contacts | `SECURITY.md#what-lockrot-does-and-does-not-do` | One sentence and a link |
| Repository hosts, tokens, anonymous caps, cache | `internals.md` | README Limitations: one line each and a link |
| PHAR verification | `phar.md#verifying-the-download` | README: one command and a link |
| Key fingerprints | `SECURITY.md#verifying-a-downloaded-phar` | Links only |
| `self-update` and its exit codes | `phar.md#keeping-it-updated` | Links |
| Page list and labels | `mkdocs.yml` nav | `docs/index.md` routes with the same labels |
| History of a behaviour | `CHANGELOG.md` | Only the version notes that `docs/writing.md` allows |

A new topic that recurs gets a row here in the same change that writes its home.
