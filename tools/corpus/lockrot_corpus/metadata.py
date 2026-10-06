"""lockrot's date-trust layer, derived again from the p2 documents and not read off lockrot.

It mirrors src/Data/Repository/PackageMetadata.php as a specification and not as a port. That class
applies `SHARED_COMMIT_TAGS` through three filters at three scopes, and one shared set cannot serve
all three: `Metadata` keeps `shared_commit_versions`, `last_stable_release_at` and `times` apart.
"""

import datetime
import os
from typing import Callable, Sequence

from . import p2  # noqa: F401 - imported for the type of what Metadata is built from
from .jsonio import read_json
from .semver import SHARED_COMMIT_TAGS, order_key, release_branch, stability

# Only a `replace` pinned to the replacer's own version links a monorepo child. A range such as `*`
# means "do not install both". If it counts as a link, unrelated packages date each other.
SELF_VERSION = 'self.version'


def parse_time(value: object) -> 'datetime.datetime | None':
    """A p2 or lock timestamp as an aware datetime, or None.

    A comparison of raw strings is correct only while every time uses the same UTC suffix: a
    single `+02:00` picks the wrong newest release. Always parse them.
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
        self.unordered = 0  # tags with a suffix that order_key() cannot order

        on_commit = {}
        commit_of = {}
        commit_of_any = {}
        times = {}
        for version in provider.versions:
            normalized = version.get('version_normalized') or ''
            reference = (version.get('source') or {}).get('reference')
            if reference and stability(normalized) != 'dev':
                # The veto for the highest non-dev tag looks up its commit here, and that tag can
                # be a pre-release. A stable-only map answers "not shared" for a beta or an RC.
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
        # A parent hands over only a release's own dates, so a tag on a shared commit hands over
        # nothing.
        self.parent_dates = {
            normalized: released for normalized, released in times.items()
            if normalized not in self.shared_commit_versions
        }

        # The newest release date covers every non-dev tag, pre-releases included. A narrower set of
        # stable on-branch tags moves the date earlier for a package whose newest tag is an RC.
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
                # The highest tag is undated or shares a commit, so there is no newest release.
                # lockrot does not fall back to the next tag.
                self.last_stable_release_at = None
                self.last_stable_version = None

        # Union over every version entry: a monorepo declares its children only in the releases that
        # had them, so one end of the array can miss children.
        self.replaces = {
            target for version in provider.versions
            for target, constraint in (version.get('replace') or {}).items()
            if constraint == SELF_VERSION
        }


class Parents:
    """Which monorepo, if any, dates a package's releases.

    resources/monorepo-parents.json only lists the extra repositories that are worth a request
    (`MonorepoParents::missingCandidates()`). A child is any package for which a package in the
    batch declares `replace: <child> self.version` (`MonorepoParents::parentOf()`), so a gate on
    the file reads the children of an unlisted monorepo as unparented. The batch is the lock plus
    the candidates and never the whole cache: a parent that lockrot never loaded dates nothing.
    """

    def __init__(self, repo_root: str) -> None:
        path = os.path.join(repo_root, 'resources', 'monorepo-parents.json')
        document = read_json(path) or {}
        table = document.get('parents') or {}
        self.candidates = sorted(table) if isinstance(table, dict) else []

    def by_child(self, batch: 'Sequence[str]',
                 metadata_for: 'Callable[[str], Metadata | None]') -> 'dict[str, str]':
        """Every child in this batch, mapped to the package that replaces it at its own version.

        First match wins, over the lock's own packages before the fetched candidates. Where two
        packages replace the same child, this tool and lockrot can pick different parents.
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
