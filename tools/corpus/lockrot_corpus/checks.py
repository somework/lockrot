"""The framework, and the one inversion the whole tool exists for.

Both scratchpad ancestors of these checks could stop checking and still print `none`. The shape was
always the same: a check that decides *whether to look* by matching the text it is about to read.
`C2 checked` was incremented inside `if m:`, so a reworded composer.lock line took the counter from
283 to 0 and the run said nothing was wrong. `claims.py` skipped a finding when the lock entry did
not parse, so a regression in version parsing put the entire corpus into one counter — and printed
`none` under it.

So: **selection never touches the rendered text.** A check declares `selects(document)` over JSON
fields only. Once a document is selected the assertion must return a verdict, and there are exactly
three: `ok()`, `problem(key, row)`, `unparsable(key, row)`. Anything else, None included, is a
framework error naming the check. A sentence that no longer parses is therefore not a smaller
population — it is `unparsable` on every selected document, which is a non-zero exit.

Skips still exist, because some of them are right: a document with no metadata block has nothing
for the lock's self-description to disagree with. They are declared in advance, by name, each with
a share of the population it may not exceed, measured on a dated corpus. An undeclared decline
raises. A declared one over its ceiling fails the run. Neither can be reached by accident.
"""

from typing import Any, Callable, Iterable, Sequence


class FrameworkError(Exception):
    """A check broke its own contract. Never a finding about lockrot — always a bug in this tool."""


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
    """lockrot said something the data underneath does not support."""
    return Verdict(Verdict.PROBLEM, key, row)


def unparsable(key: str, row: str) -> Verdict:
    """The sentence this check reads is not there any more, or not in a shape it knows.

    A problem, not a skip, and deliberately so: the alternative is the check quietly measuring
    nothing. If lockrot reworded the line on purpose, the pattern in catalog.py is updated with it,
    in the same commit, which is the point at which somebody looks at whether the check still holds.
    """
    return Verdict(Verdict.UNPARSABLE, key, row)


class Decline:
    """A reason a check declares, in advance, for not judging a document it could have judged."""

    def __init__(self, reason: str, why: str, max_share: float, measured_on: str) -> None:
        self.reason = reason
        self.why = why
        self.max_share = max_share  # of the documents offered to this check
        self.measured_on = measured_on  # the date the share was measured, for the next reader


class Selection:
    """What `selects()` answers: a judgement to make, or a declared reason for not making one."""

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
    """One assertion about lockrot's output, with the population it expects to make it on.

    `min_corpus` is a measured number with the date it was measured, in the same style as every
    other threshold in this repository. It is not a target: it is the floor below which the run
    stops claiming to know anything, because a check that selected 4 documents where it selected
    2,600 last time has been disabled by something, and the something is usually a rename.
    """

    def __init__(self, ident: str, title: str, selects: 'Callable[[Any], Selection]',
                 assert_: 'Callable[[Any], Verdict]', declines: 'Sequence[Decline]' = (),
                 min_corpus: int = 0, min_fixtures: int = 1, measured_on: 'str | None' = None,
                 kind: str = 'claim') -> None:
        # `kind` is what a document has to be for this check to be offered it at all. One run holds
        # both the findings of the reports and the explain pairs, and a check handed the wrong one
        # does not decline it — it raises, which is loud but is not the question being asked.
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
    """What a run knows: how much it looked at, what it declined, and what it found.

    The report is rendered from this and never from a bare list of problems, so an empty problem
    list cannot be printed without the population that produced it. A census over nothing renders
    as "I cannot tell you", not as a clean bill of health.
    """

    def __init__(self, population: int, scope: str) -> None:
        self.population = population
        self.scope = scope  # what was read, in words, for the first line of the output
        self.checks = {}
        self.phrases = {}
        self.failures = []  # liveness and contract failures: floors, ceilings, framework errors

    @property
    def problem_count(self) -> int:
        return sum(check.problem_count + check.unparsable_count for check in self.checks.values())


def run(checks: 'Sequence[Check]', documents: 'Iterable[Any]', population_scope: str,
        phrase_counts: 'dict[str, int] | None' = None, fixtures: bool = False) -> Census:
    """Every check over every document, and the census of what that came to.

    An exception out of an assertion is attributed to the check and the document and recorded as a
    framework failure; it never aborts the run. excontra.py died on one document with a KeyError and
    produced no output at all — not even for the 200 pairs it had already checked — which is how a
    schema change reads as a tool that was never run.
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
    """The floors and the ceilings, which is where a disabled check becomes visible.

    Checked after the run rather than during it, so the census still prints: a reader needs to see
    which check collapsed and to what, not just that one did.
    """
    if census.population == 0:
        census.failures.append(
            'nothing to read: the %s held no documents, so this run cannot say anything is clean'
            % census.scope)
    for check in checks:
        entry = census.checks[check.ident]
        floor = check.min_fixtures if fixtures else check.min_corpus
        if entry.selected == 0:
            # Deliberately worded without the phrase the `--partial` filter strips: a check that
            # judged nothing at all is not a smaller population, it is no answer, and a partial
            # tree is never a reason to accept one.
            census.failures.append(
                '%s judged nothing at all, out of %d documents it was offered'
                % (check.ident, entry.offered))
        elif entry.selected < floor:
            census.failures.append(
                '%s selected %d of %d documents, below its floor of %d%s'
                % (check.ident, entry.selected, entry.offered, floor,
                   ' measured on ' + check.measured_on if check.measured_on else ''))
        if not entry.offered or fixtures:
            # The share ceilings are measured over a corpus of thousands. The fixture set holds one
            # document per shape on purpose, so a decline that is one finding in four thousand is
            # one document in nine here — a share that means nothing and would only teach whoever
            # reads this failure to widen a real ceiling to silence it.
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
