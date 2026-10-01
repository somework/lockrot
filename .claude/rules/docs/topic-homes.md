---
paths:
  - "README.md"
  - "CONTRIBUTING.md"
  - "SECURITY.md"
  - "CHANGELOG.md"
  - "docs/**/*.md"
---
# One home per topic

Each recurring topic has exactly one canonical section. Every other page gets at most two sentences
and a link to it — never a paraphrase or a copied table, because copies drift and then contradict
each other (three copies of the severity table once listed rows in different orders). A fact may
be written in full twice only when a test reads every copy. Link the home's anchor, not the top of
its page.

| Topic | Home | Elsewhere |
|---|---|---|
| Exit codes of a run | `ci.md#exit-codes` | One line and a link; `compatibility.md` states only the promise |
| Choosing and meaning of `--fail-on` | `ci.md` (opening) | `configuration.md#fail-on-values` lists accepted values only |
| One starting `--fail-on` for snippets | `ci.md` (opening) | Every copy-paste snippet uses the value recommended there |
| How each format marks a finding (levels, SARIF ranks) | `ci.md#how-each-format-marks-a-finding` | `compatibility.md#severity-mapping`: the promise and a link |
| Each output format | its `ci.md` section | README: one list line |
| What trips `--strict-network` | `ci.md#exit-codes` | Other pages link it; `notes.md` says per note whether it counts |
| Verdicts, and what "finding" / "flagged" mean | `verdicts.md#the-nine-verdicts` | README: one-line meanings, no thresholds |
| Signals | `verdicts.md#the-signals` | Links |
| S6 data | `verdicts.md#what-s6-carries` | Links |
| Priority | `verdicts.md#priority` | One sentence |
| Security advisories and Composer's advisory settings | `verdicts.md#security-advisories` | `configuration.md#the-allowlist`: one sentence and a link |
| `unchecked` and S10 | `verdicts.md#what-was-not-checked` | Links |
| Transitive exposure, `unattributed` | `verdicts.md#transitive-exposure` | `schema.md` field rows |
| Libyears | `verdicts.md#libyears` | One sentence |
| Baseline | `baseline.md` | One sentence in README and `ci.md` |
| `extra.lockrot` keys and defaults | `configuration.md#extralockrot-keys` | README: a few common keys and a link |
| Environment variables | `configuration.md#environment-overrides` | `SECURITY.md` links it for what lockrot reads |
| `--output`, `--explain`, allowlist | `configuration.md` sections of those names | `ci.md#several-reports-from-one-run`: the snippet |
| Install-time block | `install-time.md` | `configuration.md` key rows |
| Run notes | `notes.md` (section id = note code) | `schema.md`: the `note_details` shape |
| Schema URLs, number, open sets, fields | `schema.md` (the open-set list: `#open-sets`) | `compatibility.md` states what is frozen and links the list |
| `origin`, `replacement_url`, what a report reveals | `schema.md#where-a-package-came-from` | `SECURITY.md`: a link |
| What 1.0 freezes, public surface, verdict-change policy, deprecation | `compatibility.md` | `CONTRIBUTING.md#backward-compatibility`: contributor duties and a link |
| Reserved names | `compatibility.md#names-reserved-for-extensions` | `CONTRIBUTING.md` keeps the sentence `PublicApiTest` reads |
| What lockrot reads, writes and contacts | `SECURITY.md#what-lockrot-does-and-does-not-do` | One sentence and a link |
| Repository hosts, tokens, anonymous caps, cache | `internals.md` | README Limitations: one line each and a link |
| PHAR verification | `phar.md#verifying-the-download` | README: one command and a link |
| Key fingerprints | `SECURITY.md#verifying-a-downloaded-phar` | Links only; the pinned copy is the one `ReleaseKeyTest` reads |
| `self-update` and its exit codes | `phar.md#keeping-it-updated` | Links |
| Page list and labels | `mkdocs.yml` nav | `docs/index.md` routes with the same labels |
| History of a behaviour | `CHANGELOG.md` | Nowhere else |

A new recurring topic gets a row here in the same change that writes its home.
