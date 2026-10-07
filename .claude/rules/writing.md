# Writing: docs, comments, the changelog and these rules

These rules adapt the writing rules of ASD-STE100 Simplified Technical English to a developer tool.
Apply them to the text that you write or change. Do not rewrite or reflow the lines around it. Text
that a test reads or that lockrot prints keeps its wording. Change it only with its test or its
recording.

## Words and sentences

- Use the glossary term. Do not use a word from its "Not" column in that meaning. Technical names
  (code spans, options, keys, JSON fields, identifiers, versions, URLs) keep their spelling.
- Use only `must` (a requirement), `can` (a possibility or a permission, `could` as its past) and
  `will` (the result of a condition, never a plan) as modal verbs. Not `should`, `shall`, `may`,
  `might`, `would`, `have to`, `need to`. Give advice as "we recommend that".
- Do not use contractions, `e.g.`, `i.e.`, `etc.`, or the filler "note that", "simply", "just",
  "in order to". Do not use a phrasal verb or a noun where one verb says the same. Write "omit",
  not "leave out", and "validates", not "performs validation of".
- Use the simple tenses and the active voice. Use the passive only when the actor is unknown:
  "lockrot reads the lock", not "the lock is read". A state (`abandoned`, graded) is not a passive.
- Use the -ing form only as a noun (a heading, the wording), in a technical name (breaking change,
  signing key) or as "missing", "remaining", "during". Write "before you commit", not "before
  committing", and "a test that reads the lock", not "a test reading the lock".
- A noun cluster (a noun and its noun or adjective modifiers) has at most three words.
- An instruction has at most 20 words, a description at most 25. A code span, a number with its
  unit, a quote, a title, a proper name and a hyphenated word count as one word each. A link counts
  as one word when its text is a title, else as its text. A parenthesis counts as one word and is
  also a sentence. A colon ends a sentence only before a vertical list.
- Keep the articles, the subject and the "that" after a verb. A list item, a table cell or a
  one-line comment can be a noun phrase. Write "this <noun>", not a bare "this".
- Do not use semicolons outside code.

## Instructions, notes, warnings, paragraphs

- Write one instruction per sentence, in the imperative (not "you must …"), condition first: "If
  it fails, run …". Steps in a fixed order are a numbered list.
- A note gives information: no instruction, requirement or limit. A warning (`!!! warning`) is for
  data loss, a security risk or a broken pipeline. Start it with the instruction or its condition.
  Then give the risk.
- A paragraph has one topic and at most six sentences. Three or more conditions or cases become a
  list or a table.

## Text that stays true

Write nothing that becomes false while the code it describes stays the same. Do not describe how
other code works (who calls this, another file's branches): name it by its symbol or by the test
that pins it. Write "this job checks X", not "the only check of X".

- **No time words** outside `CHANGELOG.md`: "now", "no longer", "used to", "currently",
  "recently", "not yet", "until now". Product states (`new` against the baseline, `--today`) are
  not time words.
- **Count only a closed set** (`docs/compatibility.md#closed-sets-and-their-order`) or exit codes.
  Name any other set and link its list.
- **Numbers only beside their source:** the key, constant or schema value that sets the number, an
  example value in a sample, recorded output. A documented limit of the public surface (a width, a
  range) is its own source in its home section. A version is a floor, a pin or a constraint, never
  the one a lock resolved.
- **No positions:** no line numbers, no `File.php:82`, no "above" or "below" that points at other
  text. Name the symbol or link the heading.
- **No internals of another repository:** give its version and a link.

## Less text

- Rewrite a changed fact in place, with no appended correction or "Update:" line.
- Give each fact one home (`.claude/rules/docs/topic-homes.md` for the docs). Elsewhere, link the
  home in at most two sentences. Do not retell what the link or the code says.
- Do not write "by design", "deliberately", "on purpose" or "by choice". Say what a change does not
  affect only for alert fingerprints and exit codes.

## Glossary

| Term | Meaning | Not, in this meaning |
|---|---|---|
| finding | One entry in a report's `findings`, one per analysed package, `ok` included | issue, problem, result |
| flag | One fact of the closed flag set (`abandoned` … `vulnerable`) that signals raise, in flag order | cause, issue, verdict |
| lead | The first counted maintenance flag of a finding, or null | verdict |
| vulnerable | The flag of a finding with at least one counted advisory | insecure, affected |
| score | The number that the score model computes from a finding's counted flags (`score.total`) | priority, rank |
| verdict | What a finding's score says: a grade, or `finished`, `unknown` or `ok` at score 0 | status, state |
| grade | A verdict from a score of 1 or more: `critical`, `high`, `medium`, `low` | severity, level |
| graded | A finding whose verdict is a grade | flagged, failing, bad |
| verdict order | The order of the verdicts that the sort and `--fail-on` read | severity order, ladder |
| severity | The bucket of an advisory: `critical`, `high`, `medium`, `unrated`, `low` | grade, level |
| priority | The alias of the verdict in report-2: the grade, else `none` | severity, urgency |
| signal | One observation with an id (`S2`, `S8` …) and its data (`verdicts.md#the-signals`) | check, rule |
| level | One value of the closed level set on a signal. For a SARIF or annotation level, name the format | severity |
| run note | An entry in a report's `notes`: about the run, not about a package (`notes.md`) | warning, error |
| repository host | The service that hosts a package's source repository | forge, code host |
| the lock | `composer.lock` | lock file, lockfile |
| direct requirement | A package in the project's `require` or `require-dev` | direct dependency |
| target PHP | The PHP version that lockrot judges the lock against | PHP floor (that is `require.php`) |
| libyears | Plural, except the unit name that libyear.com defines (`verdicts.md#libyears`) | libyear |
| unchecked, unmeasured, unknown | S10 and its `--fail-on` value, libyears without a result, a verdict. Never swap them | not verified |
