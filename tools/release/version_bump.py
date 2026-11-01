#!/usr/bin/env python3
"""Fail a release pull request into main that does not bump the version.

    python3 tools/release/version_bump.py <src/Version.php of the base>

A release pull request sets Version::STRING to the next patch, minor or major of the base, writes the
dated CHANGELOG heading of that version, and pins its schema copies in RELEASED_SCHEMAS (CONTRIBUTING,
"Cutting a release"). Without this check a release pull request without the bump is green, and the tag
that follows publishes the previous version. Standard library only, as tools/corpus.
"""

import re
import sys
from typing import List, Optional, Set

HEAD_FILES = ('src/Version.php', 'CHANGELOG.md', 'tests/Integration/SchemaEvolutionTest.php')
STRING = re.compile(r"const STRING = '(\d+)\.(\d+)\.(\d+)';")
RELEASED_ROW = re.compile(r"^ {8}'(\d+\.\d+\.\d+)' => \[$", re.M)


def version_of(php: str) -> Optional[str]:
    match = STRING.search(php)
    return None if match is None else '.'.join(match.groups())


def bumps(base: str) -> Set[str]:
    major, minor, patch = (int(part) for part in base.split('.'))
    return {
        '%d.%d.%d' % (major, minor, patch + 1),
        '%d.%d.0' % (major, minor + 1),
        '%d.0.0' % (major + 1),
    }


def released_schemas(schema_test: str) -> Set[str]:
    block = schema_test.split('RELEASED_SCHEMAS = [', 1)[-1].split('\n    ];', 1)[0]
    return set(RELEASED_ROW.findall(block))


def problems(base_php: str, head_php: str, changelog: str, schema_test: str) -> List[str]:
    base = version_of(base_php)
    head = version_of(head_php)
    if base is None:
        return ['src/Version.php of the base holds no X.Y.Z STRING']
    if head is None:
        return ['src/Version.php of the head holds no X.Y.Z STRING']
    if head == base:
        return ['Version::STRING is %s on both sides: a release pull request bumps it' % head]
    found = []
    if head not in bumps(base):
        found.append('Version::STRING %s is no bump of %s: take the next patch, minor or major' % (head, base))
    if re.search(r'^## \[%s\] - \d{4}-\d{2}-\d{2}$' % re.escape(head), changelog, re.M) is None:
        found.append('CHANGELOG.md has no "## [%s] - YYYY-MM-DD" heading' % head)
    if head not in released_schemas(schema_test):
        found.append('RELEASED_SCHEMAS of tests/Integration/SchemaEvolutionTest.php has no %s entry' % head)
    return found


def read(path: str) -> str:
    with open(path, encoding='utf-8') as handle:
        return handle.read()


def main(argv: List[str]) -> int:
    if len(argv) != 2:
        print(__doc__.strip().splitlines()[2].strip(), file=sys.stderr)
        return 2
    found = problems(read(argv[1]), *(read(path) for path in HEAD_FILES))
    for problem in found:
        print('::error::' + problem)
    return 1 if found else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
