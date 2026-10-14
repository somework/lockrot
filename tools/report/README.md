# tools/report

These scripts connect lockrot with its report renderer,
[somework/lockrot-report](https://github.com/somework/lockrot-report).

| Script | What it does |
|---|---|
| `update-renderer vX.Y.Z` | Vendors a release of the renderer into `resources/report/`. It verifies the attestation, the page against the sha256 in its manifest, and that the manifest names the requested version |
| `update-renderer vX.Y.Z-rc.N` | Vendors a pre-release of the renderer, with the same checks |
| `update-renderer --from-dir DIR` | Vendors a local build for development. It is not attested |
| `record` | Records the rendering contract bundle into `tests/fixtures/contract/` (`--all-baselined`: the real baselined projects too) |

## Which renderer a lockrot release vendors

- A lockrot release vendors a plain `vX.Y.Z` of the renderer. Never tag lockrot with a pre-release
  or a `--from-dir` build in `resources/report/`.
- A pull request into a release branch can vendor a pre-release `vX.Y.Z-rc.N`. This lets the
  release branch test the page before the renderer release.
- The renderer release is cut from the source of its newest pre-release. The release branch then
  vendors that release before the lockrot tag.
