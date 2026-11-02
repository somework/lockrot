#!/usr/bin/env python3
"""Fail a release pull request into main that does not bump the version.

    python3 tools/release/version_bump.py <src/Version.php of the base> <file of changed paths> <renderer package-lock.json>

A pull request into main that changes a file the release ships is a release pull request. It sets
Version::STRING to the next patch, minor or major of the base, writes the dated CHANGELOG heading and
compare links of that version, and pins its schema copies in RELEASED_SCHEMAS. The renderer that it
vendors has the manifest's version in both version fields of its package-lock.json, read at the
commit of tools/report/renderer-commit (CONTRIBUTING, "Cutting a release").
Without this check a release pull request without the bump is green, and the tag that follows
publishes the previous version. A pull request that ships nothing, such as the tag-day fix of
CONTRIBUTING or a Dependabot bump, keeps the version. Standard library only, as tools/corpus.
"""

import json
import re
import sys
from typing import Any, List, Optional, Set

HEAD_FILES = ('src/Version.php', 'CHANGELOG.md', 'tests/Integration/SchemaEvolutionTest.php', 'resources/report/manifest.json')
COMPARE = 'https://github.com/somework/lockrot/compare/'
STRING = re.compile(r"const STRING = '(\d+)\.(\d+)\.(\d+)';")
SHIPPED = ('src/', 'resources/', 'bin/lockrot', 'composer.json')
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


def unreleased_link(head: str) -> str:
    return '[Unreleased]: %sv%s...HEAD' % (COMPARE, head)


def release_link(head: str, base: str) -> str:
    return '[%s]: %sv%s...v%s' % (head, COMPARE, base, head)


def decoded(text: str) -> Any:
    try:
        return json.loads(text)
    except ValueError:
        return None


def renderer_lock_problems(manifest: str, lock: str) -> List[str]:
    renderer = decoded(manifest)
    version = renderer.get('version') if isinstance(renderer, dict) else None
    if not isinstance(version, str):
        return ['resources/report/manifest.json names no version']
    data = decoded(lock)
    if not isinstance(data, dict):
        return ['package-lock.json of the vendored renderer is no JSON object']
    packages = data.get('packages')
    root = packages.get('') if isinstance(packages, dict) else None
    fields = (('version', data.get('version')), ('packages[""].version', root.get('version') if isinstance(root, dict) else None))
    return [
        'package-lock.json of the vendored renderer has %s %s, but its manifest names %s: set both version fields with npm version %s --no-git-tag-version'
        % (field, value if isinstance(value, str) else 'nothing', version, version)
        for field, value in fields
        if value != version
    ]


def problems(base_php: str, head_php: str, changelog: str, schema_test: str, manifest: str, renderer_lock: str, changed: List[str]) -> List[str]:
    base = version_of(base_php)
    head = version_of(head_php)
    if base is None:
        return ['src/Version.php of the base holds no X.Y.Z STRING']
    if head is None:
        return ['src/Version.php of the head holds no X.Y.Z STRING']
    if head == base:
        shipped = [path for path in changed if path.startswith(SHIPPED)]
        if not shipped:
            return []
        return ['Version::STRING is %s on both sides, but the pull request changes %s, which the release ships: bump the version' % (head, shipped[0])]
    found = []
    if head not in bumps(base):
        found.append('Version::STRING %s is no bump of %s: take the next patch, minor or major' % (head, base))
    if re.search(r'^## \[%s\] - \d{4}-\d{2}-\d{2}$' % re.escape(head), changelog, re.M) is None:
        found.append('CHANGELOG.md has no "## [%s] - YYYY-MM-DD" heading' % head)
    for link in (unreleased_link(head), release_link(head, base)):
        if re.search(r'^%s$' % re.escape(link), changelog, re.M) is None:
            found.append('CHANGELOG.md has no "%s" link' % link)
    if head not in released_schemas(schema_test):
        found.append('RELEASED_SCHEMAS of tests/Integration/SchemaEvolutionTest.php has no %s entry' % head)
    return found + renderer_lock_problems(manifest, renderer_lock)


def read(path: str) -> str:
    with open(path, encoding='utf-8') as handle:
        return handle.read()


def main(argv: List[str]) -> int:
    if len(argv) != 4:
        print(__doc__.strip().splitlines()[2].strip(), file=sys.stderr)
        return 2
    changed = read(argv[2]).splitlines()
    found = problems(read(argv[1]), *(read(path) for path in HEAD_FILES), read(argv[3]), changed)
    for problem in found:
        print('::error::' + problem)
    return 1 if found else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
