"""Rendering a census, and the exit codes that make it mean something.

Neither scratchpad ancestor could gate anything: both printed `none` on an empty problem list and
both exited 0 whether they had found four hundred contradictions or had been handed an empty
directory. So two rules hold here.

The formatter takes a census, never a list of problems. There is no code path that can print a
clean verdict without the population it was reached over, and a census with no population renders
as exit 2 rather than as good news. The bare word `none` is not an output of this tool.

And a run has three answers, not two. Exit 0 is "I read this much and it holds". Exit 1 is "I found
problems". Exit 2 is "I cannot tell you whether it is clean" — a check below its floor, a decline
over the share it declared, a corrupt cached document, an empty input tree, two runs that are not
comparable. Both non-zero codes fail a gate, and the first line tells a human which one it was.
"""

import sys
from typing import TYPE_CHECKING

if TYPE_CHECKING:  # the census is the only thing this module renders, and importing it at
    from .checks import Census  # runtime would make checks.py and report.py a cycle

OK = 0
PROBLEMS = 1
CANNOT_TELL = 2
USAGE = 3

ROWS_INLINE = 30


def exit_code(census: 'Census') -> int:
    if census.failures:
        return CANNOT_TELL
    if census.problem_count:
        return PROBLEMS

    return OK


def render_text(census: 'Census', listing_path: 'str | None' = None) -> str:
    lines = []
    code = exit_code(census)
    lines.append('read %s: %d documents' % (census.scope, census.population))
    lines.append('')
    lines.append('=== what each check looked at ===')
    for ident in sorted(census.checks):
        entry = census.checks[ident]
        lines.append('%-5s %-58s selected %5d of %5d  ok %5d  problems %4d  unparsable %4d'
                     % (entry.ident, entry.title[:58], entry.selected, entry.offered, entry.ok,
                        entry.problem_count, entry.unparsable_count))
        for reason, count in sorted(entry.declined.items()):
            lines.append('        declined %5d  %s' % (count, reason))

    if census.phrases:
        lines.append('')
        lines.append('=== how many documents each rendered phrase was found in ===')
        for ident in sorted(census.phrases):
            lines.append('  %-24s %5d' % (ident, census.phrases[ident]))

    if census.failures:
        lines.append('')
        lines.append('=== this run cannot say whether the output is clean ===')
        for failure in census.failures:
            lines.append('  ' + failure)

    problems = _problem_groups(census)
    lines.append('')
    if problems:
        lines.append('=== what the data does not support ===')
        for key, rows in problems:
            lines.append('')
            lines.append('## %s — %d' % (key, len(rows)))
            for row in rows[:ROWS_INLINE]:
                lines.append('  ' + str(row))
            if len(rows) > ROWS_INLINE:
                # The rest is written out in full beside the report rather than dropped: a group of
                # four hundred used to show twenty and the other three hundred and eighty were only
                # recoverable by running the whole thing again.
                lines.append('  … %d more%s' % (len(rows) - ROWS_INLINE,
                                                ' — all of them in ' + listing_path if listing_path else ''))
    elif code == OK:
        lines.append('=== every check ran, over the population above, and found nothing ===')

    return '\n'.join(lines) + '\n'


def render_json(census: 'Census') -> dict:
    return {
        'population': census.population,
        'scope': census.scope,
        'exit': exit_code(census),
        'failures': census.failures,
        'checks': {
            ident: {
                'title': entry.title,
                'offered': entry.offered,
                'selected': entry.selected,
                'ok': entry.ok,
                'declined': entry.declined,
                'problems': {key: rows for key, rows in entry.problems.items()},
                'unparsable': {key: rows for key, rows in entry.unparsable.items()},
            }
            for ident, entry in sorted(census.checks.items())
        },
        'phrases': census.phrases,
    }


def full_listing(census: 'Census') -> str:
    lines = []
    for key, rows in _problem_groups(census):
        lines.append('## %s — %d' % (key, len(rows)))
        for row in rows:
            lines.append('  ' + str(row))
        lines.append('')

    return '\n'.join(lines)


def _problem_groups(census: 'Census') -> 'list[tuple[str, list]]':
    groups = []
    for ident in sorted(census.checks):
        entry = census.checks[ident]
        for key, rows in sorted(entry.problems.items()):
            groups.append(('%s %s' % (ident, key), rows))
        for key, rows in sorted(entry.unparsable.items()):
            groups.append(('%s could not read: %s' % (ident, key), rows))

    return groups


def note(message: str) -> None:
    """Progress and trouble go to stderr; the closing result goes to stdout.

    Piping this tool has to yield the result and not the log — the same split bin/record-fixtures
    keeps.
    """
    print(message, file=sys.stderr)
