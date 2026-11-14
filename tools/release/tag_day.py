#!/usr/bin/env python3
"""Fail a release whose CHANGELOG.md heading names another day than the tag.

    python3 tools/release/tag_day.py <X.Y.Z> <YYYY-MM-DD or unix seconds of the tag> [--warning]

The tag day is the UTC day of the tag: pass `git for-each-ref refs/tags/vX.Y.Z --format='%(taggerdate:unix)'`.
A YYYY-MM-DD argument is taken as the day already.
With --warning a mismatch is annotated as a warning and the exit code stays 0.

The heading `## [X.Y.Z] - YYYY-MM-DD` is dated on the tag day (CONTRIBUTING, "Cutting a release"),
and the release notes, the changelog and docs/example-run.md all repeat that day. A tag that slips a
day without a date pull request would publish them with a day that the tag does not have.
Standard library only, as tools/corpus.
"""

import datetime
import os
import re
import sys
from typing import List, Optional

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..')
DAY = re.compile(r'^\d{4}-\d{2}-\d{2}$')


def utc_day(value: str) -> str:
    if re.match(r'^\d+$', value) is None:
        return value
    return datetime.datetime.fromtimestamp(int(value), datetime.timezone.utc).strftime('%Y-%m-%d')


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
    warning = argv[3:] == ['--warning']
    if len(argv) != (4 if warning else 3):
        print(__doc__.strip().splitlines()[2].strip(), file=sys.stderr)
        return 2
    with open(os.path.join(ROOT, 'CHANGELOG.md'), encoding='utf-8') as handle:
        found = problems(handle.read(), argv[1], utc_day(argv[2]))
    for problem in found:
        print('::%s::%s' % ('warning' if warning else 'error', problem))
    return 1 if found and not warning else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
