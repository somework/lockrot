"""Each `--explain` text read against its own JSON, to find a sentence that the data contradicts.

Every check selects from the JSON alone, so a sentence that does not match its pattern is
`unparsable` on every document that the JSON says carries it, and never a smaller population.
"""

from . import catalog
from .checks import (Check, Decline, Selection, Verdict, decline, ok, problem, select,
                     unparsable)

# The text prints one decimal of the unrounded value and the JSON carries two, so they can differ by
# half of the text's last digit. A wider tolerance hides real drift.
LIBYEARS_TOLERANCE = 0.051

# The number of branch rows that the page prints before it says how many it omitted
# (`Explanation::BRANCH_ROWS`).
BRANCH_ROWS = 15


class Pair:
    """One explain target rendered twice: the text a human reads and the JSON it was written from."""

    kind = 'pair'

    def __init__(self, name: str, text: str, document: dict) -> None:
        self.name = name
        self.text = text
        self.document = document
        self.finding = document.get('finding') or {}
        self.metadata = document.get('metadata')
        self.lock = document.get('lock') or {}

    @property
    def package(self) -> 'str | None':
        return self.document.get('package')

    @property
    def version(self) -> 'str | None':
        return self.document.get('version')

    @property
    def installed_at(self) -> 'str | None':
        return _day((self.metadata or {}).get('installed_release'))

    @property
    def dated_by(self) -> 'str | None':
        return (self.metadata or {}).get('installed_release_dated_by')

    @property
    def lock_day(self) -> 'str | None':
        return _day(self.lock.get('released'))

    @property
    def is_snapshot(self) -> bool:
        return bool(self.lock.get('branch_snapshot'))


def _day(value: object) -> 'str | None':
    """The date part of an ISO timestamp.

    The JSON carries a full timestamp and the text prints only the day.
    """
    text = str(value or '')

    return text[:10] or None


def _trim(value: 'str | None') -> 'str | None':
    """A capture with its sentence punctuation removed.

    `(\\S+)` on `the lock dates it 2026-04-10, a commit its tags share` takes the comma with the
    date, and a date with a comma equals nothing.
    """
    return value.rstrip('.,;') if value else value


def _c1_assert(pair: Pair) -> Verdict:
    measured = catalog.LIBYEARS_MEASURED.pattern.search(pair.text)
    unmeasured = catalog.LIBYEARS_NOT_MEASURED.pattern.search(pair.text)
    reported = pair.finding.get('libyears')
    if measured and unmeasured:
        return problem('the header both measures libyears and says it did not',
                       '%s: %s / %s' % (pair.name, measured.group(1), unmeasured.group(1)))
    if measured:
        if reported is None:
            return problem('the text measures libyears the JSON leaves null',
                           '%s text=%s' % (pair.name, measured.group(1)))
        if abs(float(measured.group(1)) - float(reported)) > LIBYEARS_TOLERANCE:
            return problem('the libyears in the text is not the one in the JSON',
                           '%s text=%s json=%s' % (pair.name, measured.group(1), reported))

        return ok()
    if unmeasured:
        if reported is not None:
            return problem('the text says libyears was not measured, the JSON carries a number',
                           '%s json=%s reason=%s' % (pair.name, reported, unmeasured.group(1)))

        return ok()

    return unparsable('no libyears line in the text at all', pair.name)


def _c2_selects(pair: Pair) -> Selection:
    if pair.metadata is None:
        return decline('no metadata block')

    return select(pair)


def _c2_assert(pair: Pair) -> Verdict:
    match = None
    for form in catalog.LOCK_LINE_FORMS:
        match = form.pattern.search(pair.text)
        if match is not None:
            break
    if match is None:
        return unparsable('the composer.lock line is not in a shape this check knows', pair.name)
    phrase = match.group(1)
    # Use `match.re.groups`, not `lastindex`: the day group nests inside the phrase group, so
    # `lastindex` reports the outer one.
    day = _trim(match.group(2)) if match.re.groups >= 2 else None

    if phrase.startswith('released'):
        if pair.installed_at is None:
            return problem('the text calls the lock date a release, the JSON dates no release',
                           '%s released %s, installed_release=null' % (pair.name, day))
        if pair.installed_at != day:
            return problem('the text calls the lock date a release, the JSON reads another day',
                           '%s released %s, installed_release=%s' % (pair.name, day, pair.installed_at))
    elif 'tags share' in phrase:
        # Both halves are needed: a package that a parent really dates has `installed_at` equal to
        # the day and a `dated_by`.
        if pair.installed_at == day and pair.dated_by is None:
            return problem('a commit date is also read as the release',
                           '%s dated %s by a shared commit, yet installed_release=%s'
                           % (pair.name, day, pair.installed_at))
    elif 'its commit' in phrase:
        if not pair.is_snapshot:
            return problem('a commit date on something that is not a branch snapshot', pair.name)
        if pair.installed_at is not None:
            return problem('a branch snapshot with a release date',
                           '%s installed_release=%s' % (pair.name, pair.installed_at))
    elif phrase == 'undated':
        if pair.lock_day is not None:
            return problem('the text calls the lock entry undated, the JSON dates it',
                           '%s lock.released=%s' % (pair.name, pair.lock_day))

    if day is not None and pair.lock_day is not None and day != pair.lock_day:
        return problem('the day printed is not the lock entry date',
                       '%s text=%s lock.released=%s' % (pair.name, day, pair.lock_day))

    return ok()


def _c3_dated_selects(pair: Pair) -> Selection:
    if pair.metadata is None:
        return decline('no metadata block')
    if pair.dated_by is None:
        return decline('no parent dated this version')

    return select(pair)


def _c3_dated_assert(pair: Pair) -> Verdict:
    match = catalog.INSTALLED_DATED.pattern.search(pair.text)
    if match is None:
        return unparsable('the JSON names a parent but the text has no sentence saying so', pair.name)
    if match.group(1) != pair.version:
        return problem('the sentence names another version',
                       '%s says %s, lock has %s' % (pair.name, match.group(1), pair.version))
    if _trim(match.group(2)) != pair.installed_at:
        return problem('the sentence prints a date that is not installed_release',
                       '%s text=%s json=%s' % (pair.name, match.group(2), pair.installed_at))
    if match.group(3) != pair.dated_by:
        return problem('the sentence credits another parent',
                       '%s text=%s json=%s' % (pair.name, match.group(3), pair.dated_by))

    return ok()


def _c3_undated_selects(pair: Pair) -> Selection:
    if pair.metadata is None:
        return decline('no metadata block')
    if pair.installed_at is not None:
        return decline('the installed version has a release date')
    if pair.lock_day is None:
        return decline('the lock carries no date either')
    if pair.is_snapshot:
        return decline('a branch snapshot is described by its own sentence')

    return select(pair)


def _c3_undated_assert(pair: Pair) -> Verdict:
    match = catalog.INSTALLED_UNDATED.pattern.search(pair.text)
    if match is None:
        return unparsable('the JSON dates no release but the text does not say the version is undated',
                          pair.name)
    if _trim(match.group(2)) != pair.lock_day:
        return problem('the undated sentence prints a day the lock does not carry',
                       '%s text=%s lock=%s' % (pair.name, match.group(2), pair.lock_day))

    return ok()


def _c4_selects(pair: Pair) -> Selection:
    if pair.metadata is None:
        return decline('no metadata block')
    branches = pair.metadata.get('branches')
    if not isinstance(branches, list):
        return decline('no branch table')

    return select(pair)


def _c4_assert(pair: Pair) -> Verdict:
    rows = [row for row in pair.metadata['branches'] if isinstance(row, dict)]
    installed = [row for row in rows if row.get('installed')]
    marked = catalog.BRANCH_ROW_MARKED.pattern.findall(pair.text)
    if pair.is_snapshot and installed:
        return problem('a branch snapshot marked as sitting on a release branch', pair.name)
    if len(installed) > 1:
        return problem('more than one branch marked installed in the JSON',
                       '%s rows=%d' % (pair.name, len(installed)))
    # The page prints only the highest BRANCH_ROWS branches and then `… and N more`, while the
    # document holds every branch. Compare the printed window only, or a package whose installed
    # branch falls out of the window gets a wrong count.
    printed = rows[:BRANCH_ROWS]
    installed = [row for row in printed if row.get('installed')]
    # Compare counts and not presence: 0 marked rows against 1 installed row is how a broken pattern
    # looks.
    if len(marked) != len(installed):
        return problem('the table marks a different number of rows than the JSON does',
                       '%s text=%d json=%d' % (pair.name, len(marked), len(installed)))

    return ok()


def _c5_assert(pair: Pair) -> Verdict:
    package = (pair.package or '').lower()
    replacement = pair.finding.get('replacement')
    if replacement and replacement.lower() == package:
        return problem('the JSON says the package replaces itself', pair.name)
    match = catalog.MIGRATE_TO.pattern.search(pair.text)
    if match:
        named = _trim(match.group(1))
        if named.lower() == package:
            return problem('the text says to migrate to the package itself', pair.name)
        if replacement and named != replacement:
            return problem('the replacement named in the text is not the one in the JSON',
                           '%s text=%s json=%s' % (pair.name, named, replacement))

    return ok()


def _c6_assert(pair: Pair) -> Verdict:
    ids = {signal.get('id') for signal in pair.finding.get('signals', []) if isinstance(signal, dict)}
    said = bool(catalog.S10_ACTIVITY.pattern.search(pair.text)
                or catalog.S10_AGE.pattern.search(pair.text))
    # Compare both ways: a formatter regression leaves the signal in the JSON and removes its
    # sentence from the page.
    if ('S10' in ids) != said:
        return problem('S10 is in one of the two renderings only',
                       '%s json=%s text=%s' % (pair.name, 'S10' in ids, said))

    return ok()


NO_METADATA = Decline(
    'no metadata block',
    'the repository answered with nothing, so the lock is read as it reads itself and there is no '
    'second reading for a sentence to contradict',
    max_share=0.15,
    measured_on='2026-09-23, 14 of 283 explain pairs',
)


def checks() -> 'list[Check]':
    return [
        Check('C1', 'the libyears header against the JSON',
              lambda pair: select(pair), _c1_assert,
              min_corpus=250, min_fixtures=2, kind='pair', measured_on='2026-09-23, 283 of 283 pairs'),
        Check('C2', 'the composer.lock line against what the JSON reads that date as',
              _c2_selects, _c2_assert, declines=[NO_METADATA],
              min_corpus=200, min_fixtures=2, kind='pair', measured_on='2026-09-23, 269 of 283 pairs'),
        Check('C3a', 'the sentence crediting a parent for the installed date',
              _c3_dated_selects, _c3_dated_assert,
              declines=[
                  NO_METADATA,
                  Decline('no parent dated this version',
                          'the ordinary case: the release dates itself',
                          max_share=1.0, measured_on='2026-09-23, 266 of 283 pairs'),
              ],
              min_corpus=1, min_fixtures=1, kind='pair', measured_on='2026-09-23, 3 of 283 pairs'),
        Check('C3b', 'the sentence saying the installed version is undated',
              _c3_undated_selects, _c3_undated_assert,
              declines=[
                  NO_METADATA,
                  Decline('the installed version has a release date',
                          'the ordinary case', max_share=1.0, measured_on='2026-09-23'),
                  Decline('the lock carries no date either',
                          'nothing to print the sentence from', max_share=1.0,
                          measured_on='2026-09-23'),
                  Decline('a branch snapshot is described by its own sentence',
                          'a snapshot is dated by its commit and says so in another form',
                          max_share=1.0, measured_on='2026-09-23'),
              ],
              min_corpus=20, min_fixtures=1, kind='pair', measured_on='2026-09-23, 41 of 283 pairs selected'),
        Check('C4', 'the branch table marks the branch the JSON calls installed',
              _c4_selects, _c4_assert,
              declines=[
                  NO_METADATA,
                  Decline('no branch table', 'the repository listed no release branches',
                          max_share=0.10, measured_on='2026-09-23, none of 283 pairs'),
              ],
              min_corpus=200, min_fixtures=2, kind='pair', measured_on='2026-09-23, 269 of 283 pairs'),
        Check('C5', 'a package is never its own replacement',
              lambda pair: select(pair), _c5_assert,
              min_corpus=250, min_fixtures=2, kind='pair', measured_on='2026-09-23, 283 of 283 pairs'),
        Check('C6', 'S10 in the text exactly when it is in the JSON',
              lambda pair: select(pair), _c6_assert,
              min_corpus=250, min_fixtures=2, kind='pair', measured_on='2026-09-23, 283 of 283 pairs'),
    ]
