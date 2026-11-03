#!/usr/bin/env python3
"""Print the GitHub release notes of a lockrot tag: its CHANGELOG.md section.

    python3 tools/release/release_notes.py <X.Y.Z>

The notes of an X.Y.0 release open with a link to docs/changes/<X.Y>.md when that upgrade page
exists. A link to docs/ points at its lockrot.dev page, and another relative link at the file of the
tag on GitHub, because GitHub resolves neither on a release page. Exits 1 without a dated section for the version, so a
release cannot go out without saying what changed. Standard library only, as tools/corpus.
"""

import os
import re
import sys
from typing import List

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..')
DATED = re.compile(r'^## \[(\d+\.\d+\.\d+)\] - \d{4}-\d{2}-\d{2}$', re.M)
SITE = 'https://lockrot.dev/'
BLOB = 'https://github.com/somework/lockrot/blob/v%s/'
CODE_SPAN = re.compile(r'(`[^`]*`)')
LINK = re.compile(r'\]\(([^)\s]+)\)')
DOCS_PAGE = re.compile(r'^docs/(?:(.*?)/)?(?:index|([^/#]+))\.md(#.*)?$')


class NoSection(Exception):
    pass


def upgrade_page(version: str) -> str:
    return 'docs/changes/%s.md' % version.rsplit('.', 1)[0]


def target(path: str, version: str) -> str:
    if re.match(r'^(?:[a-z]+:|#)', path):
        return path
    page = DOCS_PAGE.match(path)
    if page is None:
        return BLOB % version + path
    directory, name, anchor = page.groups()
    url = SITE + ''.join(part + '/' for part in (directory, name) if part)
    return url + (anchor or '')


def absolute_links(text: str, version: str) -> str:
    parts = CODE_SPAN.split(text)
    return ''.join(part if index % 2 else LINK.sub(lambda link: '](%s)' % target(link.group(1), version), part) for index, part in enumerate(parts))


def section(changelog: str, version: str) -> str:
    heading = re.search(r'^## \[%s\] - \d{4}-\d{2}-\d{2}$' % re.escape(version), changelog, re.M)
    if heading is None:
        raise NoSection('CHANGELOG.md has no "## [%s] - YYYY-MM-DD" section' % version)
    lines: List[str] = []
    for line in changelog[heading.end():].split('\n'):
        if line.startswith('## ') or re.match(r'^\[[^\]]+\]: ', line):
            break
        lines.append(line)
    body = '\n'.join(lines).strip()
    if not body:
        raise NoSection('the CHANGELOG.md section of %s is empty' % version)
    return body


def notes(version: str, changelog: str, has_upgrade_page: bool) -> str:
    body = absolute_links(section(changelog, version), version)
    if not has_upgrade_page or version.split('.')[2] != '0':
        return body
    minor = version.rsplit('.', 1)[0]
    return '[Upgrading to %s](%schanges/%s/) has the steps for each install.\n\n%s' % (minor, SITE, minor, body)


def main(argv: List[str]) -> int:
    if len(argv) != 2:
        print(__doc__.strip().splitlines()[2].strip(), file=sys.stderr)
        return 2
    version = argv[1]
    with open(os.path.join(ROOT, 'CHANGELOG.md'), encoding='utf-8') as handle:
        changelog = handle.read()
    try:
        print(notes(version, changelog, os.path.isfile(os.path.join(ROOT, upgrade_page(version)))))
    except NoSection as error:
        print('::error::%s' % error, file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
