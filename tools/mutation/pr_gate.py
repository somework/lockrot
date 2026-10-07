#!/usr/bin/env python3
"""The mutation gate of a pull request: undocumented escapes fail, documented ones are listed.

    python3 tools/mutation/pr_gate.py build/infection.log [more.log ...] tests/infection-equivalents.md

On a pull request each mutation shard mutates only the lines the request changes (ci.yml), and a
minimum MSI over a handful of mutants is noise. This gate reads the escaped mutants of Infection's
text log instead, and fails on one that no entry of tests/infection-equivalents.md accounts for.
Every escape goes to the step summary either way.

An escape is keyed on its file, its mutator and the line Infection mutated, whitespace-normalised,
so that a line added or removed above it leaves the key alone. When that text occurs more than once
in the file, the enclosing method is the key instead. tests/infection-equivalents.md gives the form
of an entry. Exit code 1 when an escape is not accounted for, 2 when a log or the list cannot be
read, 0 otherwise. Standard library only.
"""

import os
import re
import sys
from typing import Dict, List, NamedTuple, Optional, Tuple

HEADER = re.compile(r'^\d+\) \S*?/?(src/\S+\.php):(\d+)\s+\[M\] (\w+) \[ID\]')
SECTION = re.compile(r'^([A-Z][A-Za-z ]+) mutants:$')
MUTATOR = r'[A-Z]\w*(?: x\d+)?'
ENTRY = re.compile(
    r'^- `(?P<path>src/[\w/]+\.php)` (?P<mutators>' + MUTATOR + r'(?:(?:,| and|, and) ' + MUTATOR + r')*) (?P<fence>`+) ?(?P<code>.+?) ?(?P=fence)(?!`)(?P<reason>.*)$'
)
FUNCTION = re.compile(r'\bfunction\s+(\w+)\s*\(')
Key = Tuple[str, str, str]


class Escape(NamedTuple):
    path: str
    line: int
    mutator: str
    text: str


def normalise(text: str) -> str:
    return ' '.join(text.split())


def escapes(log: str) -> List[Escape]:
    """The escaped mutants of an Infection text log, each with the first original line its diff removes."""
    found: List[list] = []
    section: Optional[str] = None
    current: Optional[list] = None
    for line in log.splitlines():
        heading = SECTION.match(line)
        if heading:
            section, current = heading.group(1), None
            continue
        match = HEADER.match(line)
        if match:
            current = [match.group(1), int(match.group(2)), match.group(3), ''] if section == 'Escaped' else None
            if current is not None:
                found.append(current)
            continue
        if current is not None and not current[3] and line.startswith('-') and not line.startswith('---'):
            current[3] = normalise(line[1:])
    return [Escape(*item) for item in found]


class Source:
    """The checked-out source the keys are read from."""

    def __init__(self, root: str):
        self.root = root
        self.cache: Dict[str, List[str]] = {}

    def lines(self, path: str) -> List[str]:
        if path not in self.cache:
            try:
                with open(os.path.join(self.root, path), encoding='utf-8') as handle:
                    self.cache[path] = handle.read().splitlines()
            except OSError:
                self.cache[path] = []
        return self.cache[path]

    def text(self, path: str, line: int) -> str:
        lines = self.lines(path)
        return normalise(lines[line - 1]) if 0 < line <= len(lines) else ''

    def method(self, path: str, line: int) -> str:
        for candidate in reversed(self.lines(path)[:line]):
            match = FUNCTION.search(candidate)
            if match:
                return match.group(1)
        return ''

    def keys(self, path: str, mutator: str, line: int, text: str, methods: Tuple[str, ...] = ()) -> List[Key]:
        """The one key a mutant answers to: the line's text when it is unique in the file, else the
        enclosing method. Never both: the method would let one documented mutant cover another line.
        An entry has no line: of the methods that hold its text, it takes the one its reason opens with."""
        occurrences = [n for n, candidate in enumerate(self.lines(path), 1) if normalise(candidate) == text]
        if text and len(occurrences) == 1:
            return [(path, mutator, 'text:' + text)]
        if line:
            method = self.method(path, line)
        else:
            method = next((m for m in (self.method(path, n) for n in occurrences) if m in methods), '')
        return [(path, mutator, 'method:' + method)] if method else []


def entries(markdown: str) -> List[str]:
    """The list items of the documented list, each joined with its indented continuation lines."""
    found: List[str] = []
    for line in markdown.splitlines():
        if line.startswith('- '):
            found.append(line)
        elif found and line.startswith((' ', '\t')) and line.strip():
            found[-1] += ' ' + line.strip()
        elif line.strip():
            found.append('')
    return [entry for entry in found if entry]


def allowances(markdown: str, source: Source) -> List[list]:
    """One claim per mutator an entry names: [the keys it answers to, how many escapes it covers]."""
    found: List[list] = []
    for entry in entries(markdown):
        match = ENTRY.match(entry)
        if not match:
            continue
        named = re.match(r'\W*in (\w+)\(\):', match.group('reason'))
        methods = (named.group(1),) if named else ()
        for mutator in re.finditer(r'([A-Z]\w*)(?: x(\d+))?', match.group('mutators')):
            keys = source.keys(match.group('path'), mutator.group(1), 0, normalise(match.group('code')), methods)
            if keys:
                found.append([set(keys), int(mutator.group(2) or 1)])
    return found


def summary(found: List[Escape], claims: List[list], source: Source) -> Tuple[int, str]:
    if not found:
        return 0, 'No mutant escaped on the lines this pull request changes.\n'
    rows = ['| escaped mutant | original line | in tests/infection-equivalents.md |', '|---|---|---|']
    undocumented = 0
    for escape in found:
        # The text Infection mutated is the checked-out line it reports; its diff drops comments.
        text = source.text(escape.path, escape.line) or escape.text
        ok = False
        for key in source.keys(escape.path, escape.mutator, escape.line, text):
            claim = next((c for c in claims if c[1] > 0 and key in c[0]), None)
            if claim is not None:
                claim[1] -= 1
                ok = True
                break
        undocumented += 0 if ok else 1
        rows.append('| `{}` {} (line {}) | `{}` | {} |'.format(
            escape.path, escape.mutator, escape.line, text.replace('|', '\\|'), 'yes' if ok else '**no**'))
    head = ('{} escaped mutant(s) no entry of tests/infection-equivalents.md accounts for: kill them with a test, '
            'or document why no test can.\n\n'.format(undocumented) if undocumented
            else 'Every escaped mutant is one tests/infection-equivalents.md accounts for.\n\n')
    return (1 if undocumented else 0), head + '\n'.join(rows) + '\n'


def main(argv: List[str], root: str = '.') -> Tuple[int, str]:
    """The logs of every Infection pass of one shard, then the documented-equivalents file."""
    if len(argv) < 2:
        return 2, 'usage: pr_gate.py <infection.log>... <infection-equivalents.md>\n'
    found: List[Escape] = []
    try:
        for path in argv[:-1]:
            with open(path, encoding='utf-8') as handle:
                found.extend(escapes(handle.read()))
        with open(argv[-1], encoding='utf-8') as handle:
            markdown = handle.read()
    except OSError as error:
        return 2, 'Cannot read the mutation log or the documented list: {}\n'.format(error)
    source = Source(root)
    return summary(found, allowances(markdown, source), source)


if __name__ == '__main__':
    code, text = main(sys.argv[1:])
    sys.stdout.write(text)
    sys.exit(code)
