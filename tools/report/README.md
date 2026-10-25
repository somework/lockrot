# tools/report

These scripts connect lockrot with its report renderer,
[somework/lockrot-report](https://github.com/somework/lockrot-report).

| Script | What it does |
|---|---|
| `update-renderer vX.Y.Z` | Vendors a release of the renderer into `resources/report/`. It verifies the attestation, the page against the sha256 in its manifest, and that the manifest names the requested version |
| `update-renderer vX.Y.Z-rc.N` | Vendors a pre-release of the renderer, with the same checks |
| `update-renderer --from-dir DIR` | Vendors a local build for development. It is not attested |
| `record` | Records the rendering contract bundle into `tests/fixtures/contract/` (`--all-baselined`: the real baselined projects too, `--engine-pages`: the page cases of real apps) |

## Which renderer a lockrot release vendors

- A lockrot release vendors a plain `vX.Y.Z` of the renderer. Never tag lockrot with a pre-release
  or a `--from-dir` build in `resources/report/`.
- A pull request into a release branch can vendor a pre-release `vX.Y.Z-rc.N`. This lets the
  release branch test the page before the renderer release.
- Cut the renderer release with a commit that changes only the version of its newest
  pre-release. The page carries that version, so its bytes, its CSP hash and its manifest differ
  from the pre-release. The CI of the lockrot release pull request that vendors the release tests
  those final bytes, before the lockrot tag.
- `update-renderer` and the PHAR workflow accept only an attestation from the renderer's
  `release.yml`, run for the tag that the manifest names.
