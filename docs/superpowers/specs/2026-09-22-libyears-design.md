# Libyears: one number for how far behind a lock is

Date: 2026-09-22. Target release: 0.11.0.

## Why

The verdicts are precise and are not quoted. `abandoned 27 · left-behind 54` does not travel;
"wallabag is 163.7 libyears behind" does. The libyear (libyear.com, on the version-release-date
metric of Cox et al., ICSE 2015) is the established unit: for each dependency, the time between the version installed and the newest
version available, summed over the dependencies. This is a showcase number laid over the existing
verdicts. It is not a new verdict, it does not touch the priority ladder, and it is not a measure
of rot or of security: a package three healthy patches behind adds as much as an abandoned one.

## Definition

For one package: `lastStableReleaseAt() - LockedPackage::time()`, in years of 365.25 days
(`Clock::SECONDS_PER_YEAR`), clamped at zero. `lastStableReleaseAt()` is the highest stable tag's
release date as `PackageMetadata` already computes it — dated by the monorepo parent where the
package's own tags share a commit, null where the repository dates none. The lock's `time` is
Composer's own record of the installed version's release — the repository's date for that tag,
which for a subtree split is the shared commit's, and that case is excluded below. Nothing about "now" enters: the number
depends on two dates that are both in the data lockrot already has, so it is stable on recorded
fixtures and needs no clock.

Summed over the analysed set: what `packages_checked` counts, so `--dev` includes `packages-dev`
and the install-time path measures the transaction. A package is **not measured** when:

| reason (`unmeasured` key)      | why                                                                                   |
|--------------------------------|---------------------------------------------------------------------------------------|
| `branch_snapshot`              | `isBranchSnapshot()`: a dev pin has a commit date, not a release date. Measured by push date it reads as zero on a fresh `dev-main` and as an artefact on an old one (lox/xhprof: 10.5 years of nothing). The `pinned` verdict already covers it. |
| `no_stable_release_date`       | `lastStableReleaseAt()` is null and no dated release sits above the installed version in `latestStableByBranch()` (symfony/polyfill-ctype v1.37.0, the undated newest tag); when one does, its date is used as a lower bound — scheb/2fa-* v5.13.2 measure 4.06 to the newest dated 8.x release, whose later tags share that commit; or `lastStableDatedBy()` is set: the newest date was repaired from the monorepo parent but the lock's `time` for the installed version is the same stale shared-commit date (illuminate/macroable v10.48.28 in Mautic's lock: 2023-06-05 for a 2024-11-21 release), so measuring would add the artefact. Adding "unknown" to a sum is not measuring. |
| `not_from_composer_repository` | no metadata was asked for                                                             |
| `metadata_unavailable`         | metadata was asked for and did not come                                               |

Also unmeasured, folded into `no_stable_release_date` because the lock has no date to compare:
a lock entry without `time` (a `path` repository entry, which is also not from a Composer
repository and is counted there first). The checks run in this order, and the first that applies
names the reason: `not_from_composer_repository`, `metadata_unavailable`, `branch_snapshots`,
`no_stable_release_date`. So a snapshot not from a Composer repository counts under the first, and
a `dev-master` pin on a package with no stable release at all (lox/xhprof) counts as a snapshot.

A negative difference (the lock is on a version the repository no longer lists, or on a
pre-release above the last stable) is clamped to zero, not dropped: the package is measured and
is not behind. Measured: zero cases in 640 packages across five recorded fixtures.

**Not php-libyear's number.** ecoAPM/php-libyear reads `composer.json` (`require` and
`require-dev`) and sums the direct requirements only, picks the newest version by the project's
`minimum-stability`, and scores an undated package as zero; lockrot sums the whole lock, always
against the newest stable, and leaves the undated unmeasured. On wallabag that is 106.7 against
163.7. The `direct_requirements` subtotal is the nearest number to that tool's — compare under
`--dev` — not the same one.

## Where it appears

- **JSON** (`--format=json`): every finding gains `libyears` (`number ≥ 0`, or `null` when not
  measured), and the document gains a `libyears` block that is *derived from the findings*:

  ```json
  "libyears": {
    "total": 163.69,
    "direct_requirements": 106.7,
    "measured": 195,
    "unmeasured": {
      "branch_snapshot": 4,
      "no_stable_release_date": 1,
      "not_from_composer_repository": 0,
      "metadata_unavailable": 0
    },
    "furthest_behind": {"package": "smalot/pdfparser", "version": "v1.1.0", "libyears": 4.7}
  }
  ```

  `total` and `direct_requirements` are sums of the unrounded per-package values, rounded to two
  decimals once (so the sum of the printed values agrees with `total` to within 0.005 per measured
  finding); `measured` is the count of non-null findings, and `measured` plus the `unmeasured`
  counts is the number of findings; `furthest_behind` is the maximum above zero (ties by package
  name, ascending; `null` when nothing was measured or nothing measured is behind). Named
  `direct_requirements` and `furthest_behind` rather than `direct` and `worst`: `findings[].direct`
  is a boolean in the same document, and "worst" is the priority ladder's vocabulary — the package
  furthest behind is often an `ok` one. Both fields are **optional** in the schema, like `run`, so
  documents written before 0.11.0 still validate under the same schema number; a 0.11.0 document
  always carries both.
- **Table footer**, one line after the priority totals, before `pulled in by:`:
  `libyears: 163.7 behind across 195 of 200 packages · 106.7 from direct requirements · furthest behind smalot/pdfparser v1.1.0 at 4.7`,
  joined with ` · ` so the table folds it between items. Every number carries its noun: the scope
  gives both the measured and the analysed count (`all 200 packages` when they are equal), the
  direct share says whose number it is, and the package is "furthest behind", not "worst". When
  nothing measured is behind the line stops at the scope; when nothing could be measured:
  `libyears: none of the 20 packages could be measured`; on an empty run: `libyears: nothing to
  measure`. Not added to `summaryLine()`, which the `github` format pins itself against.
- **HTML**: a fourth ledger block, last, after advisories: the eyebrow `Libyears behind`, the
  total as a figure, the rest of the footer's items under it. The packages table gets a sortable
  `libyears` column; the Run tab lists the block's numbers as rows, the unmeasured counts by
  reason among them. The ledger grid goes from three columns to four (two below 1400px).
- **Explain** (`--explain`): the finding is printed as it is, so the number rides along in
  `--format=json`; the text output is unchanged.
- **Markdown**: the same footer line as the table.

Not in: `github`, `gitlab`, SARIF (CI annotations are per finding and act on verdicts), the
install-time summary, `--fail-on`, priorities, the baseline.

## Code

- `Lockrot\Analyzer\Libyears` — immutable value object. `Libyears::behind(LockedPackage, ?PackageMetadata): ?float`
  is the per-package rule, the one place the arithmetic and the exclusion order live;
  `Libyears::fromFindings(list<Finding>): self` folds findings into the block. `toArray()`,
  `total()`, `direct()`, `measured()`, `unmeasured()`, `worst()`, `line()` (the footer text).
- `Finding` gains `?float $libyears` (constructor, last, default null) and `libyears()`; it is set
  in `Analyzer::buildFinding()` and appears in `Finding::toArray()` as `libyears`.
- `Report::libyears()` computes the block from its findings — nothing to thread through
  constructors, and `withBaseline()`/`withRun()` need no change. `toArray()` adds the key after
  `exposure`.
- `TableFormatter::summaryLines()` and `MarkdownFormatter` add the line; `report.js` adds the
  column and the headline.
- `resources/lockrot-report.schema.json` and `lockrot-explain.schema.json`: `finding.libyears` and
  the root `libyears` block, both **optional** (documents from before 0.11.0 stay valid under
  schema 1), the block's own fields required once the block is present, with descriptions that
  state the definition and the exclusions.

## Tests

- `tests/Unit/Analyzer/LibyearsTest.php`: per-package rule for every row of the table above, the
  clamp, the `--dev` set, rounding, ties, empty run; the block sums the findings.
- `ReportTest`, `FindingTest`, `TableFormatterTest`, `MarkdownFormatterTest`, `JsonFormatterTest`,
  `HtmlFormatterTest`: one line, one key, one column.
- `AcceptanceTest`: totals on the recorded fixtures — wallabag 151.52, nextcloud/3rdparty 45.3,
  matomo 0.9, laravel skeleton 0.5 — and that the wallabag block equals the arithmetic over its
  findings. The research table (`docs/19-lockrot-landscape.md` in the maintainer's notes) says
  171.6, 47.1 and 11.8: it was computed against ecosyste.ms's `latest_release_published_at`,
  which dates a dev branch by its push (lox/xhprof, wallabag/rulerz-*) and a split package by its
  own undated tags (scheb/2fa-*). The gap is those packages, to the decimal, and they are exactly
  the ones the table above leaves unmeasured.
- `JsonSchemaConformanceTest` validates a real report against the updated schema.
- Gates: `composer cs`, `composer stan`, `composer test`; `infection` on the new class.

## Documentation

`docs/verdicts.md` gains a "Libyears" section: definition, the unmeasured table, the three caveats
(counts every drift, not a measure of rot or security, not php-libyear's number), how to check the
block against the findings. `docs/schema.md` lists the new fields. `README.md` shows the footer
line. `CHANGELOG.md` under Unreleased. No new page, so the lockrot.dev nav is untouched.

## Follow-up, not in this release

lockrot.dev: a `libyears` column in `history.csv` and on the watch page, read from the block.
