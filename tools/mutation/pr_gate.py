#!/usr/bin/env python3
"""The mutation gate of a pull request: undocumented escapes fail, documented ones are listed.

    python3 tools/mutation/pr_gate.py build/infection.log [more.log ...] tests/infection-equivalents.md

On a pull request each mutation shard mutates only the lines the request changes (ci.yml), so a
shard sees a handful of mutants, often none, and a minimum MSI over a handful is noise. This gate
reads the escaped mutants of Infection's text log instead, and fails on one that no entry of
tests/infection-equivalents.md accounts for. Every escape goes to the step summary either way.

An escape is keyed so that a line added or removed above it leaves the key alone: its file, its
mutator and the line Infection mutated, whitespace-normalised (read from the checked-out file at the
line the log reports, since the log's diff drops comments). When that text occurs more than once in
the file, or an entry's text does not match, the enclosing method is the key. An entry of the
documented list gives that key in one of two forms:

- `src/Path/File.php` Mutator `the original line` -- the form that does not drift;
- `src/Path/File.php:123` Mutator -- read through the checked-out source (the text at line 123),
  until the list is re-keyed in the first form.

An entry accounts for as many escapes as it names the mutator ("x2" counts twice). A section whose
heading says its mutants are not equivalent accounts for nothing. Exit code 1 when an escape is not
accounted for, 2 when a log or the list cannot be read, 0 otherwise. Standard library only.
"""

import os
import re
import sys
from typing import Dict, List, NamedTuple, Optional, Tuple

HEADER = re.compile(r'^\d+\) \S*?/?(src/\S+\.php):(\d+)\s+\[M\] (\w+) \[ID\]')
SECTION = re.compile(r'^([A-Z][A-Za-z ]+) mutants:$')
TOKEN = re.compile(
    r'`(?P<path>src/[\w/]+\.php)(?::(?P<line>\d+))?`'
    r'|`:(?P<bare>\d+)`'
    r'|`(?P<code>[^`]+)`'
    r'|(?<![A-Za-z])(?P<mutator>[A-Z][a-z]+(?:[A-Z][a-z0-9]*)*_?)(?:\s+x(?P<times>\d+))?(?![A-Za-z])'
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

    def keys(self, path: str, mutator: str, line: int, text: str) -> List[Key]:
        """The keys a mutant answers to, most precise first: the line's text when it is unique in the
        file, then the enclosing method."""
        occurrences = [n for n, candidate in enumerate(self.lines(path), 1) if normalise(candidate) == text]
        found = [(path, mutator, 'text:' + text)] if text and len(occurrences) == 1 else []
        method = self.method(path, line or (occurrences[0] if occurrences else 0))
        if method:
            found.append((path, mutator, 'method:' + method))
        return found


def entries(markdown: str) -> List[str]:
    """The entries of the documented list: list items, and paragraphs opening with a source reference."""
    blocks: List[List[str]] = []
    not_equivalent = False
    for line in markdown.splitlines():
        if line.startswith('#'):
            # A section listing mutants that are detections, not equivalents, accounts for nothing.
            not_equivalent = 'not equivalent' in line.lower()
            blocks.append([])
            continue
        if not_equivalent:
            continue
        if line.startswith('- ') or re.match(r'^`?src/[\w/]+\.php:\d+', line):
            blocks.append([line])
        elif blocks and blocks[-1] and line.strip() and (line.startswith((' ', '\t')) or not blocks[-1][0].startswith('- ')):
            blocks[-1].append(line)
        else:
            blocks.append([])
    return [' '.join(block) for block in blocks if block]


def allowances(markdown: str, source: Source) -> List[list]:
    """One claim per mutator an entry names: [the keys it answers to, how many escapes it covers]."""
    found: List[list] = []

    def add(keys: List[Key], count: int) -> None:
        if keys:
            found.append([set(keys), count])

    for entry in entries(markdown):
        # A paragraph entry may open with an unquoted reference; quote it so it reads as one.
        entry = re.sub(r'^(src/[\w/]+\.php:\d+)', r'`\1`', entry)
        path: Optional[str] = None
        line = 0
        pending: List[Tuple[str, int]] = []
        for match in TOKEN.finditer(entry):
            if match.group('path'):
                path, line, pending = match.group('path'), int(match.group('line') or 0), []
            elif match.group('bare') and path:
                line, pending = int(match.group('bare')), []
            elif match.group('mutator') and path:
                count = int(match.group('times') or 1)
                if line:
                    add(source.keys(path, match.group('mutator'), line, source.text(path, line)), count)
                else:
                    pending.append((match.group('mutator'), count))
            elif match.group('code') and path and not line and pending:
                for mutator, count in pending:
                    add(source.keys(path, mutator, 0, normalise(match.group('code')))[:1], count)
                pending = []
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
