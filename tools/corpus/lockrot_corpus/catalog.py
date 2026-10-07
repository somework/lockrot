"""Every pattern that a check matches against lockrot's rendered output.

Each phrase declares the number of fixture documents that it must match, or a dated `unexercised`
marker that a test asserts matches none. A check must not compile a pattern of its own:
tests/test_catalog_census.py fails on a regex call outside the modules that it allows.
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
        self.unexercised = unexercised


def _phrase(*args: object, **kwargs: object) -> Phrase:
    phrase = Phrase(*args, **kwargs)
    PHRASES[phrase.ident] = phrase

    return phrase


PHRASES = {}

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

# One phrase for each way that the line dates a version. A single pattern with four alternatives
# counts as found while three of its branches match nothing. The phrase can end the line or
# continue after ` ·`.
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

# The label must start with a digit. The legend under the table also opens with `* `, and a looser
# label counts that sentence as a branch row.
BRANCH_ROW_MARKED = _phrase(
    'branch-row-marked',
    r'^ *\* (\d[\d.]*(?:\.x)?) +\S+ +\S',
    'a branch table row marked as the installed one',
    min_fixtures=1,
)

MIGRATE_TO = _phrase(
    'migrate-to',
    r'^.*\bmigrate to (\S+)',
    'the evidence clause telling the reader which package to move to',
    unexercised=('`Finding::noFixClause()` writes this clause only for a package that has an advisory with '
                 'no fixed release and names a successor, and no fixture or corpus target is of '
                 'that shape', '2026-09-23'),
)

S10_ACTIVITY = _phrase(
    's10-activity',
    r'repository activity not checked \(',
    'the S10 sentence for a repository round that did not run',
    unexercised=('no recorded finding carries a repository_activity S10: the runs carry a token and every host answered',
                 '2026-09-23'),
)

S10_AGE = _phrase(
    's10-age',
    r'the age of the package was not read \(',
    'the S10 sentence for releases that carry no date',
    min_fixtures=1,
)


def census(texts: 'Iterable[str]') -> 'dict[str, int]':
    """How many of `texts` each registered phrase matched. Each document counts once."""
    counts = {ident: 0 for ident in PHRASES}
    for text in texts:
        for ident, phrase in PHRASES.items():
            if phrase.pattern.search(text):
                counts[ident] += 1

    return counts
