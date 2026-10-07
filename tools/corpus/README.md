# The corpus checks

lockrot's own suite, its mutation gate, its JSON schemas and its cross-format checks prove only that
lockrot agrees with itself. The tests encode the same assumption as the code. Mutation asks whether
a test tells two branches apart, not whether the branch is about the right subject. A schema checks
a shape. None of them can see a claim about the wrong subject.

A second reading can see it. These tools take lockrot's finished output and read it against the data
that lockrot read, derived again by code that shares nothing with lockrot's.

| Tool | What it reads |
|---|---|
| The claims audit | Every finding against the raw Packagist documents in the cache of the run |
| The contradiction scan | Every `--explain` page against the JSON that rendered it |

A third check needs none of this: `tests/Unit/Signal/Rule/NotCheckedReachabilityTest.php` asks each
rule whether a signal that S10 names as blocked could have fired. It runs offline in the ordinary
suite.

## The rule that makes the rest worth running

**A check never discovers a skip while it is asserting.** The JSON fields alone decide whether a
document is judged, never a match on the sentence that the check is about to read. After a check
selects a document, its assertion must return a verdict. A sentence that does not parse is a problem
(`unparsable`), not a smaller population.

This inversion is the whole design. A check that counts its own skips can stop checking and still
print `none`.

These layers support it:

- **Dated floors.** Each check declares the smallest population that it expects, with the date of
  the measurement. A check that selects far fewer documents is disabled, and the run says so
  instead of passing.
- **A phrase census that fails both ways.** Every pattern lives in `lockrot_corpus/catalog.py` with
  a declared fixture minimum or a dated `unexercised` marker. A marked phrase that starts to match
  also fails, so the marker is a claim that the run checks. A test greps the package to prove that
  no check compiles a pattern of its own.
- **Poison fixtures.** `fixtures/poison.json` declares, for every problem key that a check can emit,
  a mutation of the JSON of a recorded document and the exact key that must result. A key that no
  mutation can reach (it fires only when the page changes) is declared with a reason and a date. The
  test enforces that every key is one or the other. The mutation never touches the recorded text, so
  a check that stops producing its own key, or collapses into `unparsable`, fails offline.

A clean run prints its census. The bare word `none` is not an output of this tool.

The offline suite does not watch lockrot. The recorded pages are frozen, so a reworded sentence in
`src/Output/` leaves the suite green. A re-record shows that change as a diff of a page. A corpus
run shows it as `unparsable` on every document whose JSON says that it must carry the sentence. The
suite proves the converse: the checker has not drifted from output that has not changed.

## Running it

A full pass needs `GITHUB_TOKEN` and the network. The offline self-test needs neither.

    tools/corpus/corpus selftest

    tools/corpus/corpus fetch
    tools/corpus/corpus run     --phar build/lockrot.phar --out head --today <YYYY-MM-DD>
    tools/corpus/corpus explain --phar build/lockrot.phar --out head --today <YYYY-MM-DD>
    tools/corpus/corpus check   --out head --explain-out head

`--today` pins every "years ago" through `LOCKROT_TODAY`. Without it, the same lock can cross an age
threshold between two runs and change its verdict with no code change. libyears do not depend on the
clock, so `--today` does not affect them.

To compare two archives, run both with the same `--today` and the same cache, close together in
time. Then run:

    tools/corpus/corpus diff base head

`diff` refuses two runs that differ in day, token mode or cache, and two runs of the same archive.
It also refuses two runs that are further apart than lockrot keeps a repository answer, because they
then read different upstream data.

The exit codes are:

| Code | Meaning |
|---|---|
| `0` | Every check ran and found nothing. |
| `1` | A check found problems. |
| `2` | The tool cannot tell you whether the output is clean: a check below its floor, a decline over its declared share, a corrupt cached document, an empty input tree, two runs that cannot be compared, an interrupted run or a bug in this tool. |
| `3` | Usage error. |

`check` takes the list of targets from the run, not from the directory. It reads a report only when
the run recorded that target as `ok` and the file still hashes to the recorded digest. It names and
counts every other case:

- A project that lockrot died on leaves no file.
- A project that the run never reached leaves no record.
- `unreadable output` leaves its bytes on disk beside its stderr.
- A file that something rewrote after the run is not evidence about that run.

Each of these cases makes the exit code 2, because a partial audit is not a clean audit. `check`
still audits and reports the other projects.

## The corpus

`corpus.lock.json` pins the real projects of the corpus in these ways:

- **Pinned by git:** the recorded locks under `tests/fixtures/apps` and `tests/fixtures/skeletons`.
-  **Pinned by commit:** every other project, by a commit SHA and a sha256 for its `composer.lock`
  and its `composer.json`, both fetched at that commit.

Only `corpus refresh --today <date>` moves a pin. It marks a project that was renamed, archived or
lost its lock as `unavailable` and keeps it. A shrinking corpus must show as a line in a diff,
because the differ skips what is missing on one side, and silence reads as agreement.

The locks and the Composer cache live under `build/corpus/`, which git ignores. This directory is
tracked: the manifest, the target list, the Python code and the fixtures.

`targets/explain-targets.tsv` holds hand-picked `(project, package)` pairs that cover every verdict,
every priority and every signal. Do not add a generator. Regenerating the file from a run's findings
reduces it to whatever the corpus happens to contain.

## Two things a contributor will otherwise assume

**Code here must not import or run lockrot's PHP to work out what an answer must be.** This includes
`Lockrot\Data\Repository\PackageMetadata`, `ReleaseBranch::of()` and `composer/semver`. A checker
that asks the subject about itself agrees with it by construction, and no test in this repository
can detect that. The independence is the whole product. That is why this tool is Python: in a PHP
tree, that `use` line is one keystroke away and reads as idiomatic in review.

`lockrot_corpus/semver.py` re-derives Composer's version semantics by hand, so a **recorded oracle**
checks it. p2 documents carry Composer's own `version_normalized` beside the pretty `version` and
list versions newest first. That is Composer's answer, available as data.

**This tool uses only the Python standard library, on the version in `.python-version`. It does not
inherit lockrot's PHP floor.** It has no `requirements.txt`, ruff or pytest and installs nothing.
`python -m compileall` and `python -m unittest` are the whole toolchain.

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

Run it before a release that changes the date-trust layer, the signal rules or any sentence that
lockrot prints. Do not run it for every release. It is slow and needs a token. A comparison is only
as current as the cache that both runs share.
