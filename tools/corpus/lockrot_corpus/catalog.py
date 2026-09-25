"""Every pattern that any check matches against lockrot's rendered output, in one place.

Scattering them through the checks is how two of them came to be dead without anybody knowing: the
`migrate to` half of the self-replacement check and the `repository activity not checked (` half of
the S10 check each matched zero documents out of 283, and nothing in the output said so — one of
them sat under a counter that was incremented before the regex ran, and the other under no counter
at all.

So each pattern is registered with an id and a declared minimum number of *fixture* documents it
must match. A pattern the committed fixtures cannot exercise says so, by name, with the date it was
found unexercised — and a test then asserts it matches exactly zero, so a phrase coming back to life
is as loud as one going dead. Setting a minimum to zero to make a red test green is not available:
the only way to say "this cannot be exercised" is the dated marker, and the marker costs a line in a
diff somebody reads.

No check may compile a pattern of its own. tests/test_catalog_census.py greps this package for
regex construction outside this module, because a census that covers most of the surface reads
exactly like one that covers all of it.
"""

import re
from typing import Iterable


class Phrase:
    def __init__(self, ident: str, pattern: 'str | re.Pattern[str]', reads: str,
                 min_fixtures: 'int | None' = None,
                 unexercised: 'tuple[str, str] | None' = None) -> None:
        if (min_fixtures is None) == (unexercised is None):
            raise ValueError('%s needs either a fixture minimum or a dated unexercised marker' % ident)
        self.ident = ident
        self.pattern = re.compile(pattern, re.M) if isinstance(pattern, str) else pattern
        self.reads = reads
        self.min_fixtures = min_fixtures
        self.unexercised = unexercised  # (reason, date) — asserted to match zero


def _phrase(*args: object, **kwargs: object) -> Phrase:
    phrase = Phrase(*args, **kwargs)
    PHRASES[phrase.ident] = phrase

    return phrase


PHRASES = {}

# --- the libyears header ---------------------------------------------------------------------

LIBYEARS_MEASURED = _phrase(
    'libyears-measured',
    r'^\s*libyears ([\d.]+) behind the newest stable release',
    'the header line that prints how far behind the installed version is',
    min_fixtures=1,
)

LIBYEARS_NOT_MEASURED = _phrase(
    'libyears-not-measured',
    r'^\s*libyears not measured: (.+)$',
    'the header line that names why no number could be produced',
    min_fixtures=1,
)

# --- the composer.lock line ------------------------------------------------------------------
#
# Two forms, and both are needed. The line usually continues after the phrase, so the first
# requires a trailing separator; on the packages where the phrase ends the line, only the second
# matches. Keeping one of them halves the population silently.

# One entry per way the line can describe that date, rather than one pattern with four
# alternatives inside it. A single entry censuses as "found in 283 documents" while three of its
# four branches may be dead, which is the same blindness the census exists to remove.
#
# `· ` may or may not follow: the line usually continues (`· from a Composer repository`), and on
# some packages the phrase ends it.
_LINE = r'^\s*version \S+ · .*?· %s(?: ·|\s*$)'

LOCK_RELEASED = _phrase(
    'lock-released',
    _LINE % r'(released (\S+))',
    'the composer.lock line, where the date is the installed release\'s own',
    min_fixtures=1,
)

LOCK_BY_COMMIT = _phrase(
    'lock-by-commit',
    _LINE % r'(dated (\S+) by its commit)',
    'the composer.lock line for a branch snapshot, dated by the commit it was taken from',
    min_fixtures=1,
)

LOCK_SHARED_COMMIT = _phrase(
    'lock-shared-commit',
    _LINE % r'(dated (\S+) by a commit its tags share)',
    'the composer.lock line where the date belongs to a commit several tags sit on',
    min_fixtures=1,
)

LOCK_UNDATED = _phrase(
    'lock-undated',
    _LINE % r'(undated)',
    'the composer.lock line for an entry that carries no date at all',
    min_fixtures=1,
)

LOCK_LINE_FORMS = (LOCK_RELEASED, LOCK_BY_COMMIT, LOCK_SHARED_COMMIT, LOCK_UNDATED)

# --- the installed release sentence ------------------------------------------------------------

INSTALLED_DATED = _phrase(
    'installed-dated',
    r'^\s*installed release (\S+) \((\S+), dated by (\S+)\)',
    'the sentence naming the installed version, its date and the parent that dated it',
    min_fixtures=1,
)

INSTALLED_UNDATED = _phrase(
    'installed-undated',
    r'^\s*installed release (\S+) undated: the lock dates it (\S+)',
    'the sentence saying the installed version has no release date of its own',
    min_fixtures=1,
)

# --- the branch table ---------------------------------------------------------------------------

BRANCH_ROW_MARKED = _phrase(
    'branch-row-marked',
    r'^ *\* (\d[\d.]*(?:\.x)?) +\S+ +\S',
    'a branch table row marked as the installed one',
    min_fixtures=1,
)
# The label must start with a digit. The legend printed under the table opens with `  * ` too — it
# reads `* the installed branch's highest tag has no release date …` — so a looser label counts a
# sentence of prose as a branch row on every package that carries the legend.

# --- the replacement sentence ---------------------------------------------------------------------

MIGRATE_TO = _phrase(
    'migrate-to',
    r'^.*\bmigrate to (\S+)',
    'the evidence clause telling the reader which package to move to',
    unexercised=('Finding::fix() (src/Verdict/Finding.php:238) writes this clause only for a '
                 'package that has an advisory with no fixed release AND names a successor, so it '
                 'is not a property of the fixtures: no target in the 283-pair corpus is of that '
                 'shape either, in the page or in the document', '2026-09-23'),
)

# --- the S10 sentences -----------------------------------------------------------------------------

S10_ACTIVITY = _phrase(
    's10-activity',
    r'repository activity not checked \(',
    'the S10 sentence for a repository round that did not run',
    unexercised=('every corpus run so far carried a token, so this half of S10 was never rendered; '
                 'reproducing it needs the tokenless run', '2026-09-23'),
)

S10_AGE = _phrase(
    's10-age',
    r'the age of the package was not read \(',
    'the S10 sentence for releases that carry no date',
    min_fixtures=1,
)


def census(texts: 'Iterable[str]') -> 'dict[str, int]':
    """How many of `texts` each registered phrase matched.

    Counted per document, not per match: the question is whether the phrase still finds the
    sentence it was written for, not how often that sentence appears.
    """
    counts = {ident: 0 for ident in PHRASES}
    for text in texts:
        for ident, phrase in PHRASES.items():
            if phrase.pattern.search(text):
                counts[ident] += 1

    return counts
