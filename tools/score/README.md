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
| `python3 tools/score/sweep.py --sample` | every 1,000th row of the stream |
| `python3 tools/score/sweep.py --axis gate` | the stream of the gate axis. `--axis gate --sha256` and `--axis gate --sample` read that axis: put `--axis gate` first |

`tests/fixtures/score/sweep-rows.txt.gz` holds the stream (`gzip -9n`). `sweep-rows.txt.sha256` holds
the sha256 of the uncompressed stream, never of the `.gz`: two gzip builds can write different bytes.
If the stream changes, write both files again:

```sh
python3 tools/score/sweep.py > rows.txt
sha256sum rows.txt | cut -d' ' -f1 > tests/fixtures/score/sweep-rows.txt.sha256
gzip -9n < rows.txt > tests/fixtures/score/sweep-rows.txt.gz
```

The gate axis has its own files. `GateAxisSweepTest` compares the sample with the PHP gate in the
default suite and the whole stream with the sha256 in the sweep group. If the gate rows change,
write both files again:

```sh
python3 tools/score/sweep.py --axis gate --sample > tests/fixtures/score/gate-rows.sample.txt
python3 tools/score/sweep.py --axis gate --sha256
```

Copy the digest that the second command prints into `tests/fixtures/score/gate-rows.txt.sha256`,
with one LF at the end.

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
| `gate` | a direct or transitive combination under each recorded lead and each known advisory set of a baseline entry. Only `--axis gate` prints it |

## The fields of a row

A row of every axis except `gate` has 14 fields joined by `|`, in this order. No field holds `|`, `,` or `:`, except as a
separator of a list. Every empty list and every null is `-`. Lines end in LF, the stream ends in LF,
and every byte is ASCII.

| # | Field | Written as |
|---|---|---|
| 1 | axis | the axis |
| 2 | flags | the maintenance flags in enumeration order (liveness word, `pinned`, `left-behind`, then `old-promise` or `capped`), joined by `,` |
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

## The fields of a gate row

A gate row has 16 fields joined by `|`, in this order. The separators, `-` and the line ends follow
the base row.

| # | Field | Written as |
|---|---|---|
| 1 | axis | `gate` |
| 2 | flags | as in the base row |
| 3 | advisories | as in the base row |
| 4 | reach | `direct` or `transitive` |
| 5 | dev | `1` or `0` |
| 6 | lead | the lead that the baseline entry records |
| 7 | known | the advisory ids that the baseline entry knows, joined by `,` |
| 8 | total | the score |
| 9 | new exact | the exact score of the flags and advisories that the entry does not cover, in integer half points |
| 10 | new total | that score, rounded |
| 11 | gate score | the score that the gate reads: the lower of field 8 and twice field 10 |
| 12 | limited_by | `score`, `cap` or `equal` |
| 13–16 | marks | one field per grade in `critical high medium low` order. A failing grade gives its facts as `kind:id`, joined by `,`, or `!` without a fact. A passing grade gives `-` |
