# tools/report

These scripts connect lockrot with its report renderer,
[somework/lockrot-report](https://github.com/somework/lockrot-report).

| Script | What it does |
|---|---|
| `update-renderer vX.Y.Z` | Vendors a release of the renderer into `resources/report/`. It verifies the attestation, the page against the sha256 in its manifest, and that the manifest names the requested version. It writes the source commit of the attestation to `renderer-commit` |
| `update-renderer vX.Y.Z-rc.N` | Vendors a pre-release of the renderer, with the same checks |
| `update-renderer --from-dir DIR` | Vendors a local build for development. It is not attested, and it removes `renderer-commit` |
| `check-vendored` | Fails unless `resources/report/` holds a plain release with the attestation that `update-renderer` checks, built from the commit in `renderer-commit`. The release pull request and the tag workflow run it |
| `record` | Records the rendering contract bundle into `tests/fixtures/contract/` (`--all-baselined`: the real baselined projects too, `--engine-pages`: the page cases of real apps) |

## Which renderer a lockrot release vendors

- A lockrot release vendors a plain `vX.Y.Z` of the renderer. Never tag lockrot with a pre-release
  or a `--from-dir` build in `resources/report/`.
- The release recipe vendors a pre-release `vX.Y.Z-rc.N` first, then the release
  ([Cutting a release](../../CONTRIBUTING.md#cutting-a-release)). The release branch tests the page
  of the pre-release before the renderer release.
- Cut the renderer release with a commit that changes only the version of its newest
  pre-release and its pin. The pin moves to the lockrot commit that vendors that pre-release. A
  lockrot rc tag keeps that commit reachable, and a green full check on
  `release/full-check-<sha>` passes the renderer's release gate
  ([Cutting a release](../../CONTRIBUTING.md#cutting-a-release), step 1). The page carries the
  version, so its bytes, its CSP hash and its manifest differ from the pre-release. The CI of the
  lockrot release pull request that vendors the release tests those final bytes, before the
  lockrot tag.
- `update-renderer` and `check-vendored` accept only an attestation from the renderer's
  `release.yml`, run for the tag that the manifest names. A tag can move, and a moved tag gets a
  second attestation. So `check-vendored` also requires the source commit that `update-renderer`
  pinned in `renderer-commit`.
- Before the first final renderer tag, check that the renderer repository has a tag ruleset on
  `refs/tags/v*` that blocks deletion and update: `gh api repos/somework/lockrot-report/rulesets`.
  If it is missing, create it, with lockrot's tag ruleset as the model.
