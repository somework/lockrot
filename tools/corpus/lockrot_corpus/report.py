"""Rendering a census, and the exit codes that make it mean something.

The formatter takes a census and never a list of problems, so a clean result always prints with
its population. Exit 0 means that everything read holds, exit 1 means that the run found
problems, and exit 2 means that the run cannot say whether it is clean: a check below its floor,
a decline over its ceiling, a corrupt cached document, an empty input tree or two runs that are
not comparable. Both non-zero codes fail a gate.
"""

import sys
from typing import TYPE_CHECKING

# A runtime import of Census makes checks.py and report.py an import cycle.
if TYPE_CHECKING:
    from .checks import Census

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
    """Writes progress and trouble to stderr.

    A pipe from the tool then yields the result and not the log.
    """
    print(message, file=sys.stderr)
