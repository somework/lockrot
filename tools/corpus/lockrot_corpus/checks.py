"""The framework: a check selects documents from JSON fields only, then must return a verdict.

Selection never reads the rendered text, so a reworded sentence makes a check `unparsable` on every
selected document and never leaves it a smaller population. A check must declare a skip in advance
as a `Decline` with a share ceiling. An undeclared decline raises, and a declared one over its
ceiling fails the run. A verdict is `ok()`, `problem(key, row)` or `unparsable(key, row)`, and
anything else, `None` included, is a framework error.
"""

from typing import Any, Callable, Iterable, Sequence


class FrameworkError(Exception):
    """A bug in this tool, never a finding about lockrot."""


class Verdict:
    OK = 'ok'
    PROBLEM = 'problem'
    UNPARSABLE = 'unparsable'

    def __init__(self, outcome: str, key: 'str | None' = None, row: 'str | None' = None) -> None:
        self.outcome = outcome
        self.key = key
        self.row = row


def ok() -> Verdict:
    return Verdict(Verdict.OK)


def problem(key: str, row: str) -> Verdict:
    return Verdict(Verdict.PROBLEM, key, row)


def unparsable(key: str, row: str) -> Verdict:
    """The sentence that this check reads is missing or has an unknown shape.

    This is a problem and not a skip, because a skip lets the check measure nothing. If lockrot
    rewords the line, update the pattern in catalog.py in the same commit.
    """
    return Verdict(Verdict.UNPARSABLE, key, row)


class Decline:

    def __init__(self, reason: str, why: str, max_share: float, measured_on: str) -> None:
        self.reason = reason
        self.why = why
        self.max_share = max_share  # of the documents offered to this check
        self.measured_on = measured_on


class Selection:

    def __init__(self, selected: bool, reason: 'str | None' = None,
                 subject: object = None) -> None:
        self.selected = selected
        self.reason = reason
        self.subject = subject


def select(subject: object) -> Selection:
    return Selection(True, subject=subject)


def decline(reason: str) -> Selection:
    return Selection(False, reason=reason)


class Check:
    """One assertion about lockrot's output, with the population that it expects to judge.

    `min_corpus` is the floor below which the run cannot claim to know anything: a check that
    selects a few documents where it normally selects thousands is disabled, usually by a rename.
    """

    def __init__(self, ident: str, title: str, selects: 'Callable[[Any], Selection]',
                 assert_: 'Callable[[Any], Verdict]', declines: 'Sequence[Decline]' = (),
                 min_corpus: int = 0, min_fixtures: int = 1, measured_on: 'str | None' = None,
                 kind: str = 'claim') -> None:
        # A run holds claims and explain pairs. `run()` offers a check only the documents of its
        # own `kind`, because a check raises on a document of the other kind.
        self.kind = kind
        self.ident = ident
        self.title = title
        self.selects = selects
        self.assert_ = assert_
        self.declines = {item.reason: item for item in declines}
        self.min_corpus = min_corpus
        self.min_fixtures = min_fixtures
        self.measured_on = measured_on


class CheckCensus:
    def __init__(self, ident: str, title: str) -> None:
        self.ident = ident
        self.title = title
        self.offered = 0
        self.selected = 0
        self.ok = 0
        self.declined = {}
        self.problems = {}
        self.unparsable = {}

    @property
    def problem_count(self) -> int:
        return sum(len(rows) for rows in self.problems.values())

    @property
    def unparsable_count(self) -> int:
        return sum(len(rows) for rows in self.unparsable.values())


class Census:
    """What a run knows: how much it looked at, what it declined and what it found.

    The report renders from this and never from a bare list of problems, so a clean result always
    shows its population.
    """

    def __init__(self, population: int, scope: str) -> None:
        self.population = population
        self.scope = scope
        self.checks = {}
        self.phrases = {}
        self.failures = []  # liveness and contract failures: floors, ceilings, framework errors

    @property
    def problem_count(self) -> int:
        return sum(check.problem_count + check.unparsable_count for check in self.checks.values())


def run(checks: 'Sequence[Check]', documents: 'Iterable[Any]', population_scope: str,
        phrase_counts: 'dict[str, int] | None' = None, fixtures: bool = False) -> Census:
    """Every check over every document, and the census of what that came to.

    An exception from `selects` or `assert_` is recorded as a failure that names the check and the
    document, and the run goes on. One bad document must not hide the result of the other checks.
    """
    documents = list(documents)
    census = Census(len(documents), population_scope)
    for check in checks:
        census.checks[check.ident] = CheckCensus(check.ident, check.title)

    for document in documents:
        for check in checks:
            if getattr(document, 'kind', None) != check.kind:
                continue
            entry = census.checks[check.ident]
            entry.offered += 1
            try:
                selection = check.selects(document)
            except Exception as error:  # noqa: BLE001 - reported, never swallowed
                census.failures.append(
                    '%s: selecting %s raised %s: %s'
                    % (check.ident, _name(document), type(error).__name__, error))
                continue
            if not isinstance(selection, Selection):
                raise FrameworkError('%s.selects returned %r, not a Selection' % (check.ident, selection))
            if not selection.selected:
                if selection.reason not in check.declines:
                    raise FrameworkError(
                        '%s declined %s for the undeclared reason %r'
                        % (check.ident, _name(document), selection.reason))
                entry.declined[selection.reason] = entry.declined.get(selection.reason, 0) + 1
                continue
            entry.selected += 1
            try:
                verdict = check.assert_(selection.subject)
            except Exception as error:  # noqa: BLE001 - attributed, never swallowed
                census.failures.append(
                    '%s: asserting %s raised %s: %s'
                    % (check.ident, _name(document), type(error).__name__, error))
                continue
            if not isinstance(verdict, Verdict):
                raise FrameworkError(
                    '%s.assert_ returned %r for %s; a selected document gets a verdict, never None'
                    % (check.ident, verdict, _name(document)))
            if verdict.outcome == Verdict.OK:
                entry.ok += 1
            elif verdict.outcome == Verdict.PROBLEM:
                entry.problems.setdefault(verdict.key, []).append(verdict.row)
            else:
                entry.unparsable.setdefault(verdict.key, []).append(verdict.row)

    census.phrases = phrase_counts or {}
    _assert_liveness(checks, census, fixtures)

    return census


def _assert_liveness(checks: 'Sequence[Check]', census: Census, fixtures: bool) -> None:
    """Failures for a check below its floor and for a decline over its share ceiling.

    This runs after the checks, so the census still prints which check collapsed.
    """
    if census.population == 0:
        census.failures.append(
            'nothing to read: the %s held no documents, so this run cannot say anything is clean'
            % census.scope)
    for check in checks:
        entry = census.checks[check.ident]
        floor = check.min_fixtures if fixtures else check.min_corpus
        if entry.selected == 0:
            # Keep the wording free of `below its floor`, which the `--partial` filter strips: a
            # check that judged nothing must fail a partial run too.
            census.failures.append(
                '%s judged nothing at all, out of %d documents it was offered'
                % (check.ident, entry.offered))
        elif entry.selected < floor:
            census.failures.append(
                '%s selected %d of %d documents, below its floor of %d%s'
                % (check.ident, entry.selected, entry.offered, floor,
                   ' measured on ' + check.measured_on if check.measured_on else ''))
        if not entry.offered or fixtures:
            # A fixture set holds one document per shape, so a decline share means nothing there
            # and only teaches a reader to widen a real ceiling.
            continue
        for reason, count in sorted(entry.declined.items()):
            declared = check.declines[reason]
            share = count / entry.offered
            if share > declared.max_share:
                census.failures.append(
                    '%s declined %d of %d documents as %r (%.1f%%), over the %.1f%% it declared%s'
                    % (check.ident, count, entry.offered, reason, share * 100,
                       declared.max_share * 100,
                       ' on ' + declared.measured_on if declared.measured_on else ''))


def _name(document: object) -> str:
    return getattr(document, 'name', None) or repr(document)[:80]
