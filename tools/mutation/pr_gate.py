#!/usr/bin/env python3
"""The mutation gate: undocumented escaped or uncovered mutants fail, documented ones are listed.

    python3 tools/mutation/pr_gate.py [--shard '<paths>' | --paths '<paths>'] [--changed <base>] [--report-only] build/infection.json [more.json ...] tests/infection-equivalents.md

On a pull request each mutation shard mutates only the lines the request changes (ci.yml), and a
minimum MSI over a handful of mutants is noise. A full run (--shard, the shard's filter) mutates the
whole shard. Either way the gate reads Infection's JSON log (`logs.json` in infection.json5) and
fails on an escaped or uncovered mutant that no entry of tests/infection-equivalents.md accounts
for, on any ignored mutant, and on a skipped one. Every such mutant goes to the step summary.
--paths, the shard filter of the diff mode, keeps only the mutants and the entries of those files
and directories. --changed <base> keeps only the mutants that a pull request against base mutates:
the full-check release rehearsal reads a full run as the release pull request into main will. The
log names no line of a skipped mutant, so --changed keeps the whole skipped count.

An escape is keyed on its file, its mutator and the line Infection mutated, whitespace-normalised,
so that a line added or removed above it leaves the key alone. When that text occurs more than once
in the file, the enclosing method is the key instead. An uncovered mutant answers only to a
`NotCovered` entry of its line or a `NotCoveredMethod` entry of its method, and an escape never
does. tests/infection-equivalents.md gives the form of an entry. In --shard mode, a NotCovered
claim must match exactly its count: once the dependencies are pinned, the uncovered set is
deterministic, but a kill or a timeout is not, so an unused escape claim is only a warning.
--report-only exits 0 on undocumented mutants. Exit code 1 when a mutant is not accounted for, 2
when a log or the list cannot be read, a log's arrays disagree with its stats, or a log names a file
that the checkout at the working directory does not hold, 0 otherwise.
Standard library only.
"""

import json
import os
import re
import subprocess
import sys
from typing import Dict, List, NamedTuple, Optional, Tuple

ESCAPED = 'escaped'
UNCOVERED = 'uncovered'
NOT_COVERED = 'NotCovered'
NOT_COVERED_METHOD = 'NotCoveredMethod'
# Each array of the log that the gate reads, with the stats count its length must equal.
SECTIONS = {ESCAPED: 'escapedCount', UNCOVERED: 'notCoveredCount', 'ignored': 'ignoredCount'}
MUTATOR = r'[A-Z]\w*(?: x\d+)?'
ENTRY = re.compile(
    r'^- `(?P<path>src/[\w/]+\.php)` (?P<mutators>' + MUTATOR + r'(?:(?:,| and|, and) ' + MUTATOR + r')*) (?P<fence>`+) ?(?P<code>.+?) ?(?P=fence)(?!`)(?P<reason>.*)$'
)
METHOD_SPAN = re.compile(r'^(\w+)\(\)$')
FUNCTION = re.compile(r'\bfunction\s+(\w+)\s*\(')
HUNK = re.compile(r'^@@ -\S+ \+(\d+)(?:,(\d+))? @@')
OPENS, CLOSES = '([{', ')]}'
Key = Tuple[str, str, str]


class LogError(Exception):
    pass


class Mutant(NamedTuple):
    section: str
    path: str
    line: int
    mutator: str
    text: str


class Log(NamedTuple):
    mutants: List[Mutant]
    ignored: List[Mutant]
    skipped: int


def normalise(text: str) -> str:
    return ' '.join(text.split())


def removed_line(diff: str) -> str:
    for line in diff.splitlines():
        if line.startswith('-') and not line.startswith('---'):
            return normalise(line[1:])
    return ''


def mutant(section: str, row, root: str) -> Mutant:
    try:
        mutator = row['mutator']
        return Mutant(section, os.path.relpath(mutator['originalFilePath'], root),
                      int(mutator['originalStartLine']), mutator['mutatorName'], removed_line(row.get('diff', '')))
    except (KeyError, TypeError, ValueError) as error:
        raise LogError('a {} mutant lacks {}'.format(section, error))


def read_log(text: str, root: str) -> Log:
    """The escaped, uncovered and ignored mutants of an Infection JSON log, and its skipped count.
    Each path is relative to root, the checkout that Infection mutated."""
    try:
        data = json.loads(text)
    except ValueError as error:
        raise LogError('not JSON: {}'.format(error))
    if not isinstance(data, dict) or not isinstance(data.get('stats'), dict):
        raise LogError('no stats')
    stats = data['stats']
    rows: Dict[str, list] = {}
    for section, count in SECTIONS.items():
        if not isinstance(data.get(section), list) or not isinstance(stats.get(count), int):
            raise LogError('no {} or no stats.{}'.format(section, count))
        if len(data[section]) != stats[count]:
            raise LogError('{} holds {} mutants, stats.{} says {}'.format(section, len(data[section]), count, stats[count]))
        rows[section] = data[section]
    if not isinstance(stats.get('skippedCount'), int):
        raise LogError('no stats.skippedCount')
    return Log([mutant(s, row, root) for s in (ESCAPED, UNCOVERED) for row in rows[s]],
               [mutant('ignored', row, root) for row in rows['ignored']], stats['skippedCount'])


class Source:
    """The checked-out source the keys are read from."""

    def __init__(self, root: str):
        self.root = root
        self.cache: Dict[str, List[str]] = {}
        self.code_cache: Dict[str, List[str]] = {}

    def lines(self, path: str) -> List[str]:
        if path not in self.cache:
            try:
                with open(os.path.join(self.root, path), encoding='utf-8') as handle:
                    self.cache[path] = handle.read().splitlines()
            except OSError:
                self.cache[path] = []
        return self.cache[path]

    def code(self, path: str) -> List[str]:
        if path not in self.code_cache:
            self.code_cache[path] = code_lines(self.lines(path))
        return self.code_cache[path]

    def text(self, path: str, line: int) -> str:
        lines = self.lines(path)
        return normalise(lines[line - 1]) if 0 < line <= len(lines) else ''

    def method(self, path: str, line: int) -> str:
        for candidate in reversed(self.lines(path)[:line]):
            match = FUNCTION.search(candidate)
            if match:
                return match.group(1)
        return ''

    def has_method(self, path: str, name: str) -> bool:
        return any(m.group(1) == name for m in (FUNCTION.search(line) for line in self.lines(path)) if m)

    def keys(self, path: str, mutator: str, line: int, text: str, methods: Tuple[str, ...] = ()) -> List[Key]:
        """The one key a mutant answers to: the line's text when it is unique in the file, else the
        enclosing method with the text. The method alone lets one documented mutant cover another
        line. An entry has no line: of the methods that hold its text, it takes the one
        its reason opens with."""
        occurrences = [n for n, candidate in enumerate(self.lines(path), 1) if normalise(candidate) == text]
        if text and len(occurrences) == 1:
            return [(path, mutator, 'text:' + text)]
        if line:
            method = self.method(path, line)
        else:
            method = next((m for m in (self.method(path, n) for n in occurrences) if m in methods), '')
        return [(path, mutator, 'method:' + method + ' text:' + text)] if method else []


def git(root: str, *args: str) -> str:
    try:
        return subprocess.run(['git', '-C', root, '--no-pager'] + list(args), check=True, capture_output=True, text=True).stdout
    except (OSError, subprocess.CalledProcessError) as error:
        raise LogError('git {} failed: {}'.format(' '.join(args), getattr(error, 'stderr', '') or error))


Ranges = Dict[str, Optional[List[Tuple[int, int]]]]


def changed_since(base: str, root: str) -> Ranges:
    """The lines of src that ci.yml mutates on a pull request against base, per path. None is a whole
    file: a renamed one, or one at the root of src. A hunk that only removes lines changes none, as in
    Infection's --git-diff-lines."""
    found: Ranges = {}
    path = ''
    for line in git(root, 'diff', '--no-ext-diff', '--no-color', '--unified=0', '--diff-filter=AM', base, '--', 'src').splitlines():
        if line.startswith('+++ '):
            path = line[6:] if line.startswith('+++ b/') else ''
            found.setdefault(path, [])
        hunk = HUNK.match(line)
        if hunk and path and int(hunk.group(2) or 1) > 0:
            start = int(hunk.group(1))
            found[path].append((start, start + int(hunk.group(2) or 1) - 1))
    renamed = git(root, 'diff', '--name-only', '--diff-filter=R', base, '--', 'src').split()
    return {**found, **{p: None for p in list(found) + renamed if p in renamed or os.path.dirname(p) == 'src'}}


CASE = re.compile(r'(case\b|default\s*:)')
HEREDOC = re.compile(r'<<<[ \t]*([\'"]?)([A-Za-z_]\w*)\1')


def code_lines(lines: List[str]) -> List[str]:
    """Each line's code, without string literals, comments, docblocks and heredoc bodies. A line
    keeps its place, so line n of the result is line n of the source."""
    text = '\n'.join(lines)
    out: List[str] = []
    state, n, heredoc = '', 0, ''
    while n < len(text):
        char = text[n]
        if char == '\n':
            out.append(char)
            if state == '//':
                state = ''
            elif state == '<<<':
                line_end = text.find('\n', n + 1)
                if re.match(r'[ \t]*' + heredoc + r'\b', text[n + 1:len(text) if line_end < 0 else line_end]):
                    state = ''
                    n = text.index(heredoc, n + 1) + len(heredoc) - 1
        elif state in ("'", '"'):
            if char == '\\':
                n += 1
                out.append('\n' if text[n:n + 1] == '\n' else '')
            elif char == state:
                state = ''
        elif state == '/*':
            if text.startswith('*/', n):
                state, n = '', n + 1
        elif state in ('//', '<<<'):
            pass
        elif char in '\'"':
            state = char
        elif text.startswith('/*', n):
            state, n = '/*', n + 1
        elif text.startswith('//', n) or (char == '#' and not text.startswith('#[', n)):
            state = '//'
        elif text.startswith('<<<', n) and HEREDOC.match(text, n):
            state, heredoc = '<<<', HEREDOC.match(text, n).group(2)
            n = HEREDOC.match(text, n).end() - 1
        else:
            out.append(char)
        n += 1
    return ''.join(out).split('\n')


def node_end(code: List[str], start: int) -> int:
    """A line at or after the last line of each node that opens at line start, read from the brackets
    of code_lines(). The span ends on a `;` or a closing `}` that brings the depth back to where it
    started, or on a bracket that closes one opened before start. The `}` that opens a start line of
    `} else {` or `} catch (…) {` closes the block before the node. A `case` spans to the next `case`
    or `default`. A span never ends early, so the rehearsal can keep a mutant that the pull request
    gate does not mutate, never the reverse."""
    depth = 0
    first = re.sub(r'^[\s)\]}]+', '', code[start - 1]).strip() if start <= len(code) else ''
    case = CASE.match(first) is not None
    for number in range(start, len(code) + 1):
        line = first if number == start else code[number - 1].strip()
        if case and number > start and depth == 0 and CASE.match(line):
            return number - 1
        depth += sum(line.count(c) for c in OPENS) - sum(line.count(c) for c in CLOSES)
        if depth < 0 or (not case and depth == 0 and line.endswith((';', '}'))):
            return number
    return len(code)


def touched(found: Mutant, ranges: Ranges, source: Source) -> bool:
    if found.path not in ranges:
        return False
    spans = ranges[found.path]
    if spans is None:
        return True
    end = node_end(source.code(found.path), found.line)
    return any(first <= end and found.line <= last for first, last in spans)


class Claim:
    """What one mutator name of one entry accounts for."""

    def __init__(self, entry: str, section: str, keys: List[Key], count: int):
        self.entry, self.section, self.keys, self.count, self.left = entry, section, set(keys), count, count


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


def entry_keys(match, mutator: str, source: Source) -> List[Key]:
    path, code = match.group('path'), normalise(match.group('code'))
    if mutator == NOT_COVERED_METHOD:
        method = METHOD_SPAN.match(code)
        return [(path, mutator, 'method:' + method.group(1))] if method and source.has_method(path, method.group(1)) else []
    named = re.match(r'\W*in (\w+)\(\):', match.group('reason'))
    methods = (named.group(1),) if named else ()
    return source.keys(path, mutator, 0, code, methods)


def in_shard(path: str, shard: Optional[List[str]]) -> bool:
    return shard is None or any(path == p or path.startswith(p.rstrip('/') + '/') for p in shard)


def allowances(markdown: str, source: Source, shard: Optional[List[str]] = None) -> List[Claim]:
    """One claim per mutator an entry names. NotCoveredMethod needs its count."""
    found: List[Claim] = []
    for entry in entries(markdown):
        match = ENTRY.match(entry)
        if not match or not in_shard(match.group('path'), shard):
            continue
        for mutator in re.finditer(r'([A-Z]\w*)(?: x(\d+))?', match.group('mutators')):
            name, count = mutator.group(1), mutator.group(2)
            if name == NOT_COVERED_METHOD and count is None:
                continue
            keys = entry_keys(match, name, source)
            if keys:
                section = UNCOVERED if name in (NOT_COVERED, NOT_COVERED_METHOD) else ESCAPED
                found.append(Claim(entry, section, keys, int(count or 1)))
    return found


def stale(markdown: str, source: Source) -> List[str]:
    """The entries whose code span names no line or method of the checked-out source: no mutant can match them."""
    found: List[str] = []
    for entry in entries(markdown):
        match = ENTRY.match(entry)
        if not match:
            continue
        method_only = re.fullmatch(NOT_COVERED_METHOD + r' x\d+', match.group('mutators'))
        if not entry_keys(match, NOT_COVERED_METHOD if method_only else '', source):
            found.append(entry)
    return found


def mutant_keys(found: Mutant, text: str, source: Source) -> List[Key]:
    if found.section == ESCAPED:
        return source.keys(found.path, found.mutator, found.line, text)
    return (source.keys(found.path, NOT_COVERED, found.line, text)
            + [(found.path, NOT_COVERED_METHOD, 'method:' + source.method(found.path, found.line))])


def account(found: Mutant, text: str, claims: List[Claim], source: Source) -> bool:
    for key in mutant_keys(found, text, source):
        claim = next((c for c in claims if c.section == found.section and c.left > 0 and key in c.keys), None)
        if claim is not None:
            claim.left -= 1
            return True
    return False


def row(found: Mutant, text: str, mark: str) -> str:
    return '| {} | `{}` {} (line {}) | `{}` | {} |'.format(
        found.section, found.path, found.mutator, found.line, text.replace('|', '\\|'), mark)


def summary(log: Log, claims: List[Claim], source: Source, shard_mode: bool) -> Tuple[int, str]:
    rows = ['| mutant | where | original line | in tests/infection-equivalents.md |', '|---|---|---|---|']
    undocumented = documented = 0
    for found in log.mutants:
        # The text Infection mutated is the checked-out line it reports; its diff drops comments.
        text = source.text(found.path, found.line) or found.text
        ok = account(found, text, claims, source)
        undocumented += 0 if ok else 1
        documented += 1 if ok else 0
        rows.append(row(found, text, 'yes' if ok else '**no**'))
    for found in log.ignored:
        rows.append(row(found, source.text(found.path, found.line) or found.text, '**ignored**'))
    short = [c for c in claims if shard_mode and c.section == UNCOVERED and c.left > 0]
    unused = [c for c in claims if shard_mode and c.section == ESCAPED and c.left == c.count]
    failing = undocumented + len(log.ignored) + len(short) + (1 if log.skipped else 0)
    if not log.mutants and not log.ignored and not log.skipped and not short:
        head = 'No mutant escaped or went uncovered in what this run mutates.\n'
        return 0, head + ''.join('\nWarning: no escape used the entry {}\n'.format(c.entry) for c in unused)
    head = ''
    if undocumented:
        head += ('{} escaped or uncovered mutant(s) no entry of tests/infection-equivalents.md accounts for: '
                 'kill them with a test, or document why no test can.\n\n'.format(undocumented))
    if log.ignored:
        head += '{} ignored mutant(s): the ledger is the only exception path, so remove the ignore.\n\n'.format(len(log.ignored))
    if log.skipped:
        head += '{} skipped mutant(s): a mutant whose covering tests reach the timeout is not checked.\n\n'.format(log.skipped)
    for claim in short:
        head += 'The entry {} claims {} uncovered mutant(s), and {} match it.\n\n'.format(claim.entry, claim.count, claim.count - claim.left)
    if not failing:
        head += 'Every escaped or uncovered mutant is one tests/infection-equivalents.md accounts for.\n\n'
    if documented:
        head += ('{} mutant(s) have an entry. Each one still lowers this shard\'s MSI in the full run.\n\n'.format(documented))
    for claim in unused:
        head += 'Warning: no escape used the entry {}\n\n'.format(claim.entry)
    return (1 if failing else 0), head + '\n'.join(rows) + '\n'


def main(argv: List[str], root: str = '.') -> Tuple[int, str]:
    """The options, the JSON logs of every Infection pass of one shard, then the documented-equivalents file."""
    shard: Optional[List[str]] = None
    base: Optional[str] = None
    shard_mode = report_only = False
    args = list(argv)
    while args and args[0].startswith('--'):
        option = args.pop(0)
        if option == '--report-only':
            report_only = True
        elif option in ('--shard', '--paths') and args:
            shard, shard_mode = args.pop(0).split(), option == '--shard'
        elif option == '--changed' and args:
            base = args.pop(0)
        else:
            return 2, 'unknown option {}\n'.format(option)
    if len(args) < 2:
        return 2, 'usage: pr_gate.py [--shard <paths> | --paths <paths>] [--changed <base>] [--report-only] <infection.json>... <infection-equivalents.md>\n'
    logs: List[Log] = []
    try:
        for path in args[:-1]:
            with open(path, encoding='utf-8') as handle:
                logs.append(read_log(handle.read(), os.path.abspath(root)))
        with open(args[-1], encoding='utf-8') as handle:
            markdown = handle.read()
    except OSError as error:
        return 2, 'Cannot read the mutation log or the documented list: {}\n'.format(error)
    except LogError as error:
        return 2, 'The mutation log cannot be read: {}\n'.format(error)
    # A path outside the checkout matches no --paths entry, so the run would pass on nothing.
    elsewhere = sorted({m.path for log in logs for m in log.mutants + log.ignored if not os.path.isfile(os.path.join(root, m.path))})
    if elsewhere:
        return 2, 'The mutation log names files that this checkout does not hold: {}\n'.format(', '.join(elsewhere))
    source = Source(root)
    try:
        ranges = changed_since(base, root) if base is not None else None
    except LogError as error:
        return 2, 'The changed lines cannot be read: {}\n'.format(error)

    def kept(found: Mutant) -> bool:
        return in_shard(found.path, shard) and (ranges is None or touched(found, ranges, source))

    merged = Log([m for log in logs for m in log.mutants if kept(m)],
                 [m for log in logs for m in log.ignored if kept(m)], sum(log.skipped for log in logs))
    code, text = summary(merged, allowances(markdown, source, shard), source, shard_mode)
    return (0 if report_only else code), text


if __name__ == '__main__':
    code, text = main(sys.argv[1:])
    sys.stdout.write(text)
    sys.exit(code)
