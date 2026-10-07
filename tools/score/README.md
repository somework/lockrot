# tools/score

`sweep.py` prints the sweep row stream of score model 1. `model.py` holds what the sweep evaluates:
the engine, the score object, the one grammar and the grade gate. Both use the Python standard
library only and read no file. `tests/Integration/SweepDifferentialTest.php` compares the committed
stream with the PHP engine row by row.

## Commands

| Command | Output |
|---|---|
| `python3 tools/score/sweep.py > rows.txt` | the stream |
| `python3 tools/score/sweep.py --sha256` | the rows per axis and the sha256 of the uncompressed stream |

`tests/fixtures/score/sweep-rows.txt.gz` holds the stream (`gzip -9n`). `sweep-rows.txt.sha256` holds
the sha256 of the uncompressed stream, never of the `.gz`: two gzip builds can write different bytes.
`tests/Support/ScoreSweep.php` gives the commands that write both files.

## The axes

The sweep walks every flag set, advisory set and dev value, direct or transitive. It prints one row
per evaluated score.

| Axis | Rows |
|---|---|
| `base` | every combination, direct or transitive |
| `unreached` | the unreached variant of each transitive row |
| `under` | an abandoned row with a hidden `silent` or `stale` reading |
| `under_entry` | the same, under an `ignore[]` entry that accepts the hidden word |
| `accepted` | a base row with one of its flags accepted by an `ignore[]` entry |

## The fields of a row

A row has 14 fields joined by `|`, in this order. No field holds `|`, `,` or `:`, except as a
separator of a list. Every empty list and every null is `-`. Lines end in LF, the stream ends in LF,
and every byte is ASCII.

| # | Field | Written as |
|---|---|---|
| 1 | axis | the axis |
| 2 | flags | the maintenance flags in enumeration order (liveness word, `pinned`, `left-behind`, `old-promise`), joined by `,` |
| 3 | advisories | `severity:fix_kind` per advisory, joined by `,`. The ids are `A0`, `A1` in that order |
| 4 | reach | `direct`, `transitive` or `unreached` |
| 5 | dev | `1` or `0` |
| 6 | under | the hidden liveness word (`silent`, `stale`) |
| 7 | accepted | the accepted flag, the hidden word on `under_entry` |
| 8 | exact | the exact score in integer half points (36.5 is `73`) |
| 9 | total | the score |
| 10 | grade | the band of the score, `-` at 0 |
| 11 | decided_by | the id |
| 12 | without | `id:total:verdict` per `without[]` row in its order, with `+` when `at_least` |
| 13 | if_counted | `flag:total:verdict` per `accepted[]` row in its order, with `+` when `at_least` |
| 14 | gate | the grade gate with no baseline entry: one digit per grade in `critical high medium low` order, `1` fails |
