# Baselined projects

Two real wallabag projects under a committed baseline-2 file. They are contract fixtures:
lockrot-report vendors `check/report.bundle.json`, and the tests compare lockrot's output with every
recorded file. Re-record them when lockrot's output changes, and review the diff like a snapshot.

| Scenario | Earlier lock | Later lock | Options |
|---|---|---|---|
| `wallabag-main` | `before/`, commit c7e5755 | `tests/fixtures/apps/wallabag_wallabag` | `--target-php=8.4 --dev` |
| `wallabag-2.6` | `before/`, tag 2.6.10 | `after/`, tag 2.6.14 | `--target-php=8.4`, and one check with `--target-php=8.3` |

## The recipe

`tools/report/record --baselined <scenario>` runs four steps on the recorded answers in `http/`:

1. `generate`: the earlier lock writes its baseline on its own day, the clock `t0` of `provenance.json`.
2. `as-of`: the recorder removes each advisory that was reported after `t0`, then each entry with no
   fact left. A team that wrote the file on that day did not see those advisories.
3. `check`: the later lock is checked against the file on the clock `t1`, in every format, with
   `--explain` for one package per standing shape and with `--fail-on=left-behind,vulnerable-high`.
4. `regenerate`: the file is written again on the later lock, and the later lock is checked again.

The recorder runs every scenario twice and writes only when both runs agree byte for byte. It
refuses a scenario that misses a coverage floor (`BaselinedFloors`). Every recorded file carries the
tool version that `VersionMask` names, and the address of the fixture server loses its port.

Record the answers of a new lock once, with the network:

```sh
GITHUB_TOKEN=$(gh auth token) bin/record-fixtures <scenario>/before <scenario>/after
```

Move the answers of each side's `http/` into the scenario's `http/`, then run
`tools/report/record --all-baselined`.

A run at `t0` reads the release lists and the pushes of the recording day, so a flag at `t0` can
rest on a later release. `provenance.json` lists each such reading in `as_of.later_than_t0`.
