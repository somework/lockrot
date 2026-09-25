"""lockrot's date-trust layer, derived again from the p2 documents rather than read off lockrot.

Which dates lockrot is willing to call a release is the whole subject of the claims checks, so it
is the one thing that cannot be taken from lockrot. What follows mirrors
src/Data/Repository/PackageMetadata.php as a specification, not as a port.

The trap, and the reason three sets live here instead of one: lockrot applies SHARED_COMMIT_TAGS
through three different filters at three different scopes, and they are not interchangeable.

  * `shared_commit_versions` marks every stable on-branch tag sitting on a commit that carries
    three or more of them — dated or not (PackageMetadata.php:305-310).
  * `last_stable_release_at` is the newest date among *non-dev* tags, pre-releases included, and is
    discarded entirely when the *highest* non-dev tag is undated or sits on a shared commit
    (PackageMetadata.php:286). It is not "the newest dated tag": it is nothing, when the top of the
    list cannot be trusted.
  * `times`, and `parent_dates` which is `times` minus the shared set, count stable on-branch tags
    only — a parent hands a child only the dates that are a release's.

Computing one "is this tag commit-dated" set and reusing it for all three gets the illuminate/* and
the scheb/2fa cases wrong in opposite directions. Narrowing the newest-release set to stable
on-branch tags moves the date earlier on every package whose newest tag is an RC, which turns two
checks into false accusations against correct output.
"""

import datetime
import os
from typing import Callable, Sequence

from . import p2  # noqa: F401 - imported for the type of what Metadata is built from
from .jsonio import read_json
from .semver import SHARED_COMMIT_TAGS, order_key, release_branch, stability

# `replace` links only hand dates over when they are pinned to the replacer's own version. A range
# replace — symplify/easy-coding-standard replacing symfony/polyfill-ctype at `*` — says "do not
# install that one as well", and reading it as a monorepo link starts unrelated packages dating
# each other (PackageMetadata.php:43).
SELF_VERSION = 'self.version'


def parse_time(value: object) -> 'datetime.datetime | None':
    """A p2 or lock timestamp as an aware datetime, or None.

    Comparing these as raw strings is correct only while every cached time is spelled as UTC with
    the same suffix; a single `+02:00` picks the wrong newest release and turns the libyears checks
    into false accusations. So they are parsed, always.
    """
    if not value:
        return None
    text = str(value)
    if text.endswith('Z'):
        text = text[:-1] + '+00:00'
    try:
        parsed = datetime.datetime.fromisoformat(text)
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=datetime.timezone.utc)

    return parsed


class Metadata:
    """What a package's p2 document says about its releases, read the way lockrot reads it."""

    def __init__(self, provider: 'p2.Provider') -> None:
        self.name = provider.name
        self.unordered = 0  # tags whose suffix this tool cannot order; see order_key()

        on_commit = {}
        commit_of = {}
        commit_of_any = {}
        times = {}
        for version in provider.versions:
            normalized = version.get('version_normalized') or ''
            reference = (version.get('source') or {}).get('reference')
            if reference and stability(normalized) != 'dev':
                # Every non-dev tag's commit, counted nowhere. The count is over stable on-branch
                # tags only, but the *lookup* at the veto below is for the highest non-dev tag,
                # pre-release included — that is the pairing PackageMetadata.php:286 uses, and
                # looking the highest tag up in a stable-only map silently answers "not shared"
                # for every package whose newest tag is a beta or an RC.
                commit_of_any[normalized] = reference
        for version in provider.versions:
            normalized = version.get('version_normalized') or ''
            if stability(normalized) != 'stable' or release_branch(normalized) is None:
                continue
            reference = (version.get('source') or {}).get('reference')
            if reference:
                on_commit[reference] = on_commit.get(reference, 0) + 1
                commit_of[normalized] = reference
            released = parse_time(version.get('time'))
            if released is not None:
                times[normalized] = released

        self.shared_commit_versions = {
            normalized for normalized, reference in commit_of.items()
            if on_commit[reference] >= SHARED_COMMIT_TAGS
        }
        self.times = times
        # A parent hands over only the dates that are a release's: a tag its siblings share dates
        # nothing, least of all somebody else's version of the same number.
        self.parent_dates = {
            normalized: released for normalized, released in times.items()
            if normalized not in self.shared_commit_versions
        }

        non_dev = []
        for version in provider.versions:
            normalized = version.get('version_normalized') or ''
            if stability(normalized) == 'dev':
                continue
            if order_key(normalized) is None:
                self.unordered += 1
                continue
            non_dev.append(version)
        self.has_stable = bool(non_dev)

        highest = max(non_dev, key=lambda v: order_key(v['version_normalized'])) if non_dev else None
        dated = [(v, parse_time(v.get('time'))) for v in non_dev]
        dated = [(v, when) for v, when in dated if when is not None]
        newest = max(dated, key=lambda pair: pair[1]) if dated else None
        self.last_stable_release_at = newest[1] if newest else None
        self.last_stable_version = newest[0]['version_normalized'] if newest else None

        if highest is not None:
            highest_normalized = highest['version_normalized']
            reference = commit_of_any.get(highest_normalized)
            shared = reference is not None and on_commit.get(reference, 0) >= SHARED_COMMIT_TAGS
            if not highest.get('time') or shared:
                # The top of the list is undated, or dated only by a commit three tags share. There
                # is no newest release to measure from, and lockrot says so rather than reaching one
                # tag further down.
                self.last_stable_release_at = None
                self.last_stable_version = None

        # Unioned over every version entry, because p2 lists newest first and a monorepo declares
        # its children in whichever releases had them. Reading one end of the array gave
        # laravel/framework no children at all.
        self.replaces = {
            target for version in provider.versions
            for target, constraint in (version.get('replace') or {}).items()
            if constraint == SELF_VERSION
        }


class Parents:
    """Which monorepo, if any, dates a package's releases.

    The file is not the map. resources/monorepo-parents.json decides only which extra repository
    request is worth making for a lock that does not already contain the parent
    (MonorepoParents::missingCandidates(), :113); what makes a package a child is that *some package
    in the batch* declares `replace: <child> self.version`, and MonorepoParents::parentOf() (:152)
    scans the whole batch for one. Gating on the file inverts that, and the inversion is not
    theoretical: sylius/sylius has 44 children in a real lock and is not in the file, so every one
    of them would be read as unparented and audited against dates lockrot never used.

    The batch here is the same batch lockrot has: every package of the project's own lock, plus the
    candidates the file would have made it fetch. Not the whole cache — a parent lockrot never
    loaded cannot have dated anything.
    """

    def __init__(self, repo_root: str) -> None:
        path = os.path.join(repo_root, 'resources', 'monorepo-parents.json')
        document = read_json(path) or {}
        table = document.get('parents') or {}
        self.candidates = sorted(table) if isinstance(table, dict) else []

    def by_child(self, batch: 'Sequence[str]',
                 metadata_for: 'Callable[[str], Metadata | None]') -> 'dict[str, str]':
        """Every child in this batch, mapped to the package that replaces it at its own version.

        First match wins, over the lock's own packages before the fetched candidates. lockrot takes
        the first in its own batch order and its docblock says a parent that is itself a child does
        not happen; where two packages replace the same child this and lockrot could disagree, and
        neither is more right than the other.
        """
        parent_of = {}
        for name in list(batch) + self.candidates:
            metadata = metadata_for(name)
            if metadata is None:
                continue
            for child in metadata.replaces:
                if child != name:
                    parent_of.setdefault(child, name)

        return parent_of
