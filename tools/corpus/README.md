# The corpus checks

lockrot's own suite, its mutation gate, its JSON schemas and its cross-format checks all share one
blind spot: they prove lockrot agrees with itself. The tests encode the same assumption the code
does, mutation asks whether a test can tell two branches apart rather than whether the branch is
about the right thing, and a schema checks a shape. Every bug three review rounds found in the
0.11.0 branch was of one kind anyway — *a claim about the wrong subject* — and none of those
instruments can see it.

What can see it is a second reading. These tools take lockrot's finished output and read it against
the data lockrot read, derived again, here, by code that has never met lockrot's. Two of them caught
a real bug the day they were written:

| | what it reads | what it caught |
|---|---|---|
| the claims audit | every finding against the raw Packagist documents in the run's own cache | 31 wrong libyears claims on the pre-fix build, 0 after, over 4,124 findings |
| the contradiction scan | every `--explain` page against the JSON it was rendered from | 6 contradictions on the pre-fix build, 0 after, over 283 pages — including guzzlehttp/guzzle calling one day both a release and a commit its tags share |

The third member of the family lives elsewhere and needs none of this: `tests/Unit/Signal/Rule/NotCheckedReachabilityTest.php`
asks each rule whether a signal S10 names as blocked could have fired at all. It runs offline in the
ordinary suite.

## The rule that makes the rest worth running

**A check never discovers a skip while it is asserting.** Whether a document is judged is decided
from JSON fields only — never by matching the sentence the check is about to read. Once a document
is selected the assertion has to return a verdict, and a sentence that no longer parses is a
*problem* (`unparsable`), not a quietly smaller population.

That inversion is the whole design, and it is there because both scratchpad ancestors of these
checks could stop checking and still print `none`. One incremented its counter inside `if m:`, so a
reworded line took it from 283 documents to 0 and reported nothing wrong. The other skipped a
finding whose version did not parse, so a parsing regression would have sent the entire corpus into
one skip counter — under a clean bill of health.

Three more layers sit on top:

- **Dated floors.** Each check declares the smallest population it expects, measured on a pinned
  corpus with the date. A check that selects 4 documents where it selected 3,965 has been disabled
  by something, and the run says so instead of passing.
- **A phrase census that fails both ways.** Every pattern lives in `lockrot_corpus/catalog.py` with
  a declared fixture minimum, or a dated `unexercised` marker — and a marked phrase that *starts*
  matching fails too, so the marker is a claim that gets checked rather than a way to silence a
  check. A test greps the package to prove no check compiles a pattern of its own.
- **Poison fixtures.** `fixtures/poison.json` declares, for every problem key a check can emit, a
  mutation applied to the JSON of a recorded document and the exact key that must come out. A key
  no mutation can reach — the handful that fire only when the *page* changes — is declared instead,
  with a reason and a date, and the test enforces that every key is one or the other. The mutation
  never touches the recorded text, so a check that has stopped producing its own key, or has
  started collapsing into `unparsable`, is red offline in a fifth of a second.

A clean run prints its census. The bare word `none` is not an output of this tool.

What the offline suite does **not** do is watch lockrot. The recorded pages are frozen at the day
they were recorded, so a sentence reworded in `src/Output/` leaves it green; that change surfaces
when somebody re-records — where it is a diff of a page — or on the next corpus run, as `unparsable`
on every document the JSON says should carry the sentence. The suite's job is the converse and it is
the one that needed automating: that the *checker* has not drifted away from output that has not
changed.

## Running it

A full pass needs `GITHUB_TOKEN`, the network and a couple of hours. The offline self-test needs
none of that and takes under a second.

    tools/corpus/corpus selftest

    tools/corpus/corpus fetch
    tools/corpus/corpus run     --phar build/lockrot.phar --out head --today 2026-09-23
    tools/corpus/corpus explain --phar build/lockrot.phar --out head --today 2026-09-23
    tools/corpus/corpus check   --out head --explain-out head

`--today` pins every "years ago" through `LOCKROT_TODAY`. Without it the same lock crosses the
three- or five-year threshold between two runs and changes verdict with no code change — which is
how the first corpus comparison of this project filed the calendar as a regression. libyears is
clock-free by design and is unaffected either way.

To compare two archives, run both with the same `--today` into the same cache, close together, then:

    tools/corpus/corpus diff base head

`diff` refuses two runs whose day, token mode or cache differ, and refuses two runs of the same
archive. The refusal is the point: lockrot keeps a repository answer for 86,400 seconds, so past a
day apart the two halves were not reading the same upstream.

Exit codes: **0** every check ran and found nothing · **1** problems found · **2** *I cannot tell
you whether it is clean* — a check below its floor, a decline over the share it declared, a corrupt
cached document, an empty input tree, two runs that cannot be compared, a run that was interrupted,
a bug in this tool · **3** usage.

`check` reads a report only when the run recorded that target `ok` and the file still hashes to what
was recorded, and it takes the list of targets from the run rather than from the directory. Every
other shape is named and counted, never passed over: a project lockrot died on leaves no file at
all, a project the run never reached leaves not even a record, `unreadable output` deliberately
leaves its bytes on disk beside its stderr, and a file rewritten after the run is not evidence about
that run. A single one of those is exit 2 — a partial audit is not a clean one — but the other
thirty-eight projects are still audited and still reported, because "one file will not parse" and
"nothing was checked" are different facts.

## The corpus

39 real projects, pinned in `corpus.lock.json`, last refreshed **2026-09-23**.

- **21 of the 39 are pinned by git already**: the recorded locks under `tests/fixtures/apps` and
  `tests/fixtures/skeletons`. More than half the evidence was reproducible before this tool
  existed.
- **18 are pinned by commit**, with a sha256 for `composer.lock` and one for `composer.json`, both
  fetched from that one ref. The ancestor of this fetcher pulled each project's default branch and
  kept no commit, no digest and no timestamp, so the same script produced a different corpus six
  months later under the same directory names — and fetched the two files through separate calls
  that could straddle a push.

`corpus refresh --today <date>` is the only thing that moves a pin, and it is run deliberately. A
project that has been renamed, archived or has dropped its lock is marked `unavailable` and kept,
because a shrinking corpus has to be a line in a diff: the differ skips what is missing on one side,
so silence reads as agreement.

Nothing large is committed. The locks and the ~90 MB Composer cache live under `build/corpus/`,
which is gitignored. What is tracked is this directory — 896 KB over 65 files: the manifest, the
target list, ~220 KB of Python, and a 608 KB fixture set of which one real monorepo parent's
Packagist document is 238 KB.

`targets/explain-targets.tsv` holds 279 hand-picked `(project, package)` pairs spanning all nine
verdicts, all five priorities and all ten signals across 24 of the 39 projects. There is no
generator and there must not be one — regenerating it from a run's findings would quietly reduce it
to whatever the corpus happens to contain.

## Two things a contributor will otherwise assume

**Nothing here may import or shell out to lockrot's PHP to work out what an answer should be.** Not
`Lockrot\Data\Repository\PackageMetadata`, not `ReleaseBranch::of()`, not `composer/semver`. A
checker that asks the subject what it is about agrees with it by construction, and no test in this
repository could detect that. The independence is the entire product, which is also why this is
Python: in a PHP tree that `use` line would be one keystroke away and would read as idiomatic in
review.

The price is paid honestly. Re-deriving Composer's version semantics by hand has already produced
four false alarms, so `lockrot_corpus/semver.py` is checked against a **recorded oracle**: p2
documents carry Composer's own `version_normalized` beside the pretty `version` and list versions
newest first, which is Composer's answer available as data. Recording that oracle immediately found
three real faults in this tool — an uppercase `RC` spelled back lowercase, an upper-case `V` prefix,
and a dash before a pre-release number — together affecting 3,346 of 96,599 recorded versions.

**This tool is stdlib-only Python on the 3.12 in `.python-version`, and it does not inherit
lockrot's PHP 7.4 floor.** There is no `requirements.txt`, no ruff, no pytest and nothing to
install, which follows the precedent CONTRIBUTING already sets for the node checks over
`resources/report/lib.js`: the language's own runner, nothing installed. `python -m compileall` and
`python -m unittest` are the whole toolchain.

## Files

| | |
|---|---|
| `corpus` | the entry point: `fetch`, `run`, `explain`, `check`, `diff`, `refresh`, `selftest` |
| `record` | records the offline fixtures and the version oracle from a finished run |
| `lockrot_corpus/checks.py` | the framework: selection, verdicts, declared declines, floors |
| `lockrot_corpus/catalog.py` | every pattern read against rendered output, and the census |
| `lockrot_corpus/claims.py` | A1–A5: findings against the repository data |
| `lockrot_corpus/explain.py` | C1–C6: each page against its own JSON |
| `lockrot_corpus/semver.py` | Composer's version semantics, re-derived, oracle-checked |
| `lockrot_corpus/metadata.py` | the date-trust layer, re-derived |
| `lockrot_corpus/p2.py` | the Composer cache, minified entries reconstructed |
| `lockrot_corpus/runner.py` | running an archive over the corpus, resumably |
| `lockrot_corpus/load.py` | turning a finished run into documents, and refusing what is not one |
| `lockrot_corpus/diff.py` | comparing two runs, and refusing incomparable ones |

## When to run it

Before a release that changes the date-trust layer, the signal rules, or any sentence lockrot
prints — not for every release. It is slow, it needs a token, and its answer is only as fresh as the
cache both halves of a comparison share.
