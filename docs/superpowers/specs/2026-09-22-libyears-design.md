# Libyears: one number for how far behind a lock is

Date: 2026-09-22. Target release: 0.11.0.

## Why

The verdicts are precise and are not quoted. `abandoned 27 · left-behind 54` does not travel;
"wallabag is 152 libyears behind" does. Libyear (Cox et al., 2018; libyear.com) is the
established unit: for each dependency, the time between the version installed and the newest
version available, summed over the dependencies. This is a showcase number laid over the existing
verdicts. It is not a new verdict, it does not touch the priority ladder, and it is not a measure
of rot or of security: a package three healthy patches behind adds as much as an abandoned one.

## Definition

For one package: `lastStableReleaseAt() - LockedPackage::time()`, in years of 365.25 days
(`Clock::SECONDS_PER_YEAR`), clamped at zero. `lastStableReleaseAt()` is the highest stable tag's
release date as `PackageMetadata` already computes it — dated by the monorepo parent where the
package's own tags share a commit, null where the repository dates none. The lock's `time` is
Composer's own record of the installed version's release. Nothing about "now" enters: the number
depends on two dates that are both in the data lockrot already has, so it is stable on recorded
fixtures and needs no clock.

Summed over the analysed set: what `packages_checked` counts, so `--dev` includes `packages-dev`
and the install-time path measures the transaction. A package is **not measured** when:

| reason (`unmeasured` key)      | why                                                                                   |
|--------------------------------|---------------------------------------------------------------------------------------|
| `branch_snapshots`             | `isBranchSnapshot()`: a dev pin has a commit date, not a release date. Measured by push date it reads as zero on a fresh `dev-main` and as an artefact on an old one (lox/xhprof: 10.5 years of nothing). The `pinned` verdict already covers it. |
| `no_stable_release_date`       | `lastStableReleaseAt()` is null: no stable release at all, or the highest tag is undated (symfony/polyfill-*, scheb/2fa-* splits). Adding "unknown" to a sum is not measuring. |
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

**Not php-libyear's number.** ecoAPM/php-libyear reads `composer.json` and sums the direct
requirements only; lockrot sums the whole lock. On wallabag that is 94.5 against 151.5. The
`direct` subtotal is the bridge: it is the whole-lock number restricted to `direct: true`.

## Where it appears

- **JSON** (`--format=json`): every finding gains `libyears` (`number ≥ 0`, or `null` when not
  measured), and the document gains a `libyears` block that is *derived from the findings*:

  ```json
  "libyears": {
    "total": 151.52,
    "direct": 94.48,
    "measured": 191,
    "unmeasured": {
      "branch_snapshots": 4,
      "no_stable_release_date": 5,
      "not_from_composer_repository": 0,
      "metadata_unavailable": 0
    },
    "worst": {"package": "smalot/pdfparser", "version": "v1.1.0", "libyears": 4.71}
  }
  ```

  `total` and `direct` are sums of the unrounded per-package values, rounded to two decimals
  once; `measured` is the count of non-null findings; `worst` is the maximum (ties by package
  name, ascending; `null` when nothing was measured). The block is checkable by arithmetic over
  `findings`, to within the rounding of the per-package values. Both are required in the schema;
  the schema number does not change (a field is added, none removed or renamed).
- **Table footer**, one line after the priority totals, before `pulled in by:`:
  `151.5 libyears behind across 191 packages (direct 94.5); worst smalot/pdfparser v1.1.0, 4.7 · 9 not measured`.
  On a run where nothing was measured: `libyears: nothing measured (N not measured)`. Not added
  to `summaryLine()`, which the `github` format pins itself against.
- **HTML**: the headline shows the total next to the package count; the packages table gets a
  sortable `libyears` column; the Run block lists the block's counts.
- **Explain** (`--explain`): the finding is printed as it is, so the number rides along in
  `--format=json`; the text output is unchanged.
- **Markdown**: the same footer line as the table.

Not in: `github`, `gitlab`, SARIF (CI annotations are per finding and act on verdicts), the
install-time summary, `--fail-on`, priorities, the baseline.

## Code

- `Lockrot\Analyzer\Libyears` — immutable value object. `Libyears::of(PackageFacts $facts, bool $metadataFailed): ?float`
  is the per-package rule, the one place the arithmetic and the exclusion order live;
  `Libyears::summarise(list<Finding>): self` folds findings into the block. `toArray()`,
  `total()`, `direct()`, `measured()`, `unmeasuredBy()`, `worst()`, `line()` (the footer text).
- `Finding` gains `?float $libyears` (constructor, last, default null) and `libyears()`; it is set
  in `Analyzer::buildFinding()` and appears in `Finding::toArray()` as `libyears`.
- `Report::libyears()` computes the block from its findings — nothing to thread through
  constructors, and `withBaseline()`/`withRun()` need no change. `toArray()` adds the key after
  `exposure`.
- `TableFormatter::summaryLines()` and `MarkdownFormatter` add the line; `report.js` adds the
  column and the headline.
- `resources/lockrot-report.schema.json`: `finding.libyears`, root `libyears`, both required, with
  descriptions that state the definition and the exclusions.

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
