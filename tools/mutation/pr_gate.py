#!/usr/bin/env python3
"""The mutation gate of a pull request: undocumented escapes fail, documented ones are listed.

    python3 tools/mutation/pr_gate.py build/infection.log [more.log ...] tests/infection-equivalents.md

On a pull request each mutation shard mutates only the lines the request changes (ci.yml), so a
shard sees a handful of mutants, often none. A minimum MSI over a handful is noise: one documented
equivalent on a touched line is several points. This gate reads the escaped mutants of Infection's
text log instead. tests/infection-equivalents.md says its line numbers drift as the code above them
moves, so an escape is not matched by line: it is accounted for while the entries naming its file
(a list item, or a paragraph opening with a `src/...php:line` reference) name its mutator at least as
many times as the run has escapes of that file and mutator ("x2" counts twice). Every escape goes to
the step summary, accounted for or not; the exit code is 1 when any is not, 2 when the log cannot be
read, 0 otherwise.

The full run on a push to release/** and main keeps the per-shard minimum MSI; this gate does not
replace it. Standard library only, as tools/corpus.
"""

import re
import sys
from typing import List, NamedTuple, Optional, Tuple

ESCAPE = re.compile(r'^\d+\) \S*?/?(src/\S+\.php):(\d+)\s+\[M\] (\w+) \[ID\]')
SECTION = re.compile(r'^([A-Z][A-Za-z ]+) mutants:$')
SOURCE = re.compile(r'src/[\w/]+\.php')
OPENS_WITH_REFERENCE = re.compile(r'^`?src/[\w/]+\.php:\d+')


class Escape(NamedTuple):
    path: str
    line: int
    mutator: str


class Entry(NamedTuple):
    paths: frozenset
    text: str


def escapes(log: str) -> List[Tuple[str, int, str]]:
    """The escaped mutants of an Infection text log, as (path under src/, line, mutator)."""
    found = []
    section: Optional[str] = None
    for line in log.splitlines():
        heading = SECTION.match(line)
        if heading:
            section = heading.group(1)
            continue
        match = ESCAPE.match(line)
        if section == 'Escaped' and match:
            found.append(Escape(match.group(1), int(match.group(2)), match.group(3)))
    return [tuple(escape) for escape in found]


def entries(markdown: str) -> List[Entry]:
    """The entries of the documented-equivalents file.

    An entry is a list item (a line starting `- ` and its indented continuation) or a paragraph whose
    first word is a `src/...php:line` reference; prose around them documents nothing.
    """
    blocks: List[List[str]] = []
    for line in markdown.splitlines():
        if line.startswith('- ') or OPENS_WITH_REFERENCE.match(line):
            blocks.append([line])
        elif blocks and blocks[-1] and line.strip() and (line.startswith((' ', '\t')) or not blocks[-1][0].startswith('- ')):
            blocks[-1].append(line)
        else:
            blocks.append([])
    found = []
    for block in blocks:
        text = ' '.join(block)
        paths = frozenset(SOURCE.findall(text))
        if paths:
            found.append(Entry(paths, text))
    return found


def _mentions(mutator: str, text: str) -> int:
    count = 0
    for match in re.finditer(r'(?<![A-Za-z])' + re.escape(mutator) + r'(?![A-Za-z])(\s+x(\d+))?', text):
        count += int(match.group(2)) if match.group(2) else 1
    return count


def documented(escape: Tuple[str, int, str], known: List[Entry]) -> bool:
    """Whether any entry naming the escape's file names its mutator."""
    return allowance(escape[0], escape[2], known) > 0


def allowance(path: str, mutator: str, known: List[Entry]) -> int:
    """How many escapes of this file and mutator the entries account for."""
    return sum(_mentions(mutator, entry.text) for entry in known if path in entry.paths)


def accounted(found: List[Tuple[str, int, str]], known: List[Entry]) -> List[bool]:
    """Per escape, in order: whether it is within the number the entries document for its file and mutator."""
    used: dict = {}
    result = []
    for path, _, mutator in found:
        key = (path, mutator)
        used[key] = used.get(key, 0) + 1
        result.append(used[key] <= allowance(path, mutator, known))
    return result


def summary(found: List[Tuple[str, int, str]], known: List[Entry]) -> Tuple[int, str]:
    if not found:
        return 0, 'No mutant escaped on the lines this pull request changes.\n'
    lines = ['| escaped mutant | in tests/infection-equivalents.md |', '|---|---|']
    undocumented = 0
    for escape, ok in zip(found, accounted(found, known)):
        undocumented += 0 if ok else 1
        lines.append('| `{}:{}` {} | {} |'.format(escape[0], escape[1], escape[2], 'yes' if ok else '**no**'))
    head = ('{} escaped mutant(s) no entry of tests/infection-equivalents.md accounts for: kill them with a test, '
            'or document why no test can.\n\n'.format(undocumented) if undocumented
            else 'Every escaped mutant is one tests/infection-equivalents.md accounts for (file, mutator and count).\n\n')
    return (1 if undocumented else 0), head + '\n'.join(lines) + '\n'


def main(argv: List[str]) -> Tuple[int, str]:
    """The logs of every Infection pass of one shard, then the documented-equivalents file."""
    if len(argv) < 2:
        return 2, 'usage: pr_gate.py <infection.log>... <infection-equivalents.md>\n'
    found: List[Tuple[str, int, str]] = []
    try:
        for path in argv[:-1]:
            with open(path, encoding='utf-8') as handle:
                found.extend(escapes(handle.read()))
        with open(argv[-1], encoding='utf-8') as handle:
            markdown = handle.read()
    except OSError as error:
        return 2, 'Cannot read the mutation log or the documented list: {}\n'.format(error)
    return summary(found, entries(markdown))


if __name__ == '__main__':
    code, text = main(sys.argv[1:])
    sys.stdout.write(text)
    sys.exit(code)
