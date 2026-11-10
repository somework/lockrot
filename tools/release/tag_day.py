#!/usr/bin/env python3
"""Fail a release whose CHANGELOG.md heading names another day than the tag.

    python3 tools/release/tag_day.py <X.Y.Z> <YYYY-MM-DD of the tag>

The heading `## [X.Y.Z] - YYYY-MM-DD` is dated on the tag day (CONTRIBUTING, "Cutting a release"),
and the release notes, the changelog and docs/example-run.md all repeat that day. A tag that slips a
day without a date pull request would publish them with a day that the tag does not have.
Standard library only, as tools/corpus.
"""

import os
import re
import sys
from typing import List, Optional

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..')
DAY = re.compile(r'^\d{4}-\d{2}-\d{2}$')


def heading_day(changelog: str, version: str) -> Optional[str]:
    heading = re.search(r'^## \[%s\] - (\d{4}-\d{2}-\d{2})$' % re.escape(version), changelog, re.M)
    return None if heading is None else heading.group(1)


def problems(changelog: str, version: str, day: str) -> List[str]:
    if DAY.match(day) is None:
        return ['the tag day "%s" is no YYYY-MM-DD date' % day]
    found = heading_day(changelog, version)
    if found is None:
        return ['CHANGELOG.md has no "## [%s] - YYYY-MM-DD" heading' % version]
    if found != day:
        return ['CHANGELOG.md dates %s on %s, but the tag is from %s: merge the date pull request and tag its commit' % (version, found, day)]
    return []


def main(argv: List[str]) -> int:
    if len(argv) != 3:
        print(__doc__.strip().splitlines()[2].strip(), file=sys.stderr)
        return 2
    with open(os.path.join(ROOT, 'CHANGELOG.md'), encoding='utf-8') as handle:
        found = problems(handle.read(), argv[1], argv[2])
    for problem in found:
        print('::error::' + problem)
    return 1 if found else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
