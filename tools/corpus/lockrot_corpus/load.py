"""Turning a finished corpus run into the documents the checks read.

Both halves are driven by the run manifest, not by a directory listing, and that is the whole of the
design here. A project lockrot died on leaves *no file at all* — so a reader that lists the directory
cannot tell a corpus of 39 from the 30 that survived, and the checks then pass over 77% of the
evidence and report a clean census. The manifest is the only record that the other nine were ever
attempted, and its `intended` list is the only record that a run killed at the 35th project was
ever going to reach the other four.

The same for a pair: a target is judged only when the manifest records both of its files whole and
both still hash to what was recorded. The text is written before the JSON, so an interruption leaves
a fresh page beside a stale document — the one pairing every contradiction check assumes cannot
happen — and a file replaced after the run is not evidence about that run at all.
"""

import datetime
import os
from typing import TYPE_CHECKING

from .claims import Claim
from .explain import Pair
from .jsonio import CorpusDataError, read_json, read_text, sha256_file
from .metadata import Metadata, Parents
from .p2 import AmbiguousProvider, ProviderCache
from .semver import normalize

if TYPE_CHECKING:
    from collections.abc import Callable


class LoadError(Exception):
    """The inputs are not a corpus run. Never a finding — a reason the run cannot answer."""


class Corpus:
    """The p2 cache plus the monorepo table, memoised across every finding of every project."""

    def __init__(self, cache_root: str, repo_root: str) -> None:
        self.cache = ProviderCache(cache_root)
        self.parents = Parents(repo_root)
        self._metadata = {}
        self._ambiguous = set()

    def metadata(self, name: str, notification_url: 'str | None' = None) -> 'Metadata | None':
        """The metadata for `name` as the repository that serves it holds it.

        Memoised on the pair, never on the name alone. A package cached under two hosts is only
        ambiguous until something says which host the lock names, and the parent scan asks about
        every package in the lock before the findings do — so a memo keyed on the name would answer
        the second question with the first question's `None` and decline the package as AMBIGUOUS
        with its `notification-url` sitting right there in the lock entry. It would also carry the
        first project's answer into every later one.
        """
        key = (name, notification_url)
        if key in self._metadata:
            return self._metadata[key]
        try:
            provider = self.cache.get(name, notification_url)
        except AmbiguousProvider:
            self._ambiguous.add(key)
            self._metadata[key] = None

            return None
        result = Metadata(provider) if provider is not None else None
        self._metadata[key] = result

        return result

    def is_ambiguous(self, name: str, notification_url: 'str | None' = None) -> bool:
        return (name, notification_url) in self._ambiguous


MANIFEST = 'run.json'


def load_claims(reports_dir: str, projects_dir: str, cache_root: str, repo_root: str,
                manifest: 'dict | None' = None) -> 'tuple[list[Claim], list[str]]':
    """Every finding of every project report, with its lock entry and its derived metadata.

    Returns (claims, missing). `missing` names every project the run was supposed to cover and did
    not finish: one lockrot crashed on, one that timed out, one the run never reached at all, one
    whose report is empty or will not parse, one whose report was replaced after the run wrote it.
    It is not a diagnostic — the caller turns it into a run-level failure, because a check that ran
    over three quarters of the corpus has not answered the question that was asked.

    Every one of those is decided from the manifest before the file is opened, on the same status
    and digest `load_pairs` holds a pair to. A report is read only when the run recorded it `ok` and
    it still hashes to what was recorded.
    """
    if not os.path.isdir(reports_dir):
        raise LoadError('%s: no such run directory' % reports_dir)
    corpus = Corpus(cache_root, repo_root)
    claims = []
    missing = []
    for project, expected in _projects(reports_dir, manifest):
        recorded = _recorded(manifest, project)
        if recorded is not None and recorded.get('status') != 'ok':
            # Including `unreadable output`, whose file the run deliberately leaves on disk beside
            # its stderr. Reading it back here would be reading exactly what the run already
            # refused to call a report.
            missing.append('%s (%s)' % (project, expected))
            continue
        path = os.path.join(reports_dir, project + '.json')
        if not os.path.exists(path) or os.path.getsize(path) == 0:
            missing.append('%s (%s)' % (project, expected))
            continue
        if recorded is not None and sha256_file(path) != recorded.get('sha256'):
            # The same rule load_pairs holds a pair to, and for the same reason: a file the run
            # wrote and something else changed afterwards is not evidence about that run.
            missing.append('%s (replaced since the run wrote it)' % project)
            continue
        try:
            report = read_json(path)
        except CorpusDataError:
            # A report that will not parse is one project's answer missing, which the docstring
            # above promises, and not a reason the other thirty-eight go unaudited.
            report = None
        if not isinstance(report, dict) or not isinstance(report.get('findings'), list):
            missing.append('%s (the report does not parse as one)' % project)
            continue
        lock_entries = _lock_entries(os.path.join(projects_dir, project, 'composer.lock'))
        parent_of = corpus.parents.by_child(list(lock_entries), _resolver(corpus, lock_entries))
        for finding in report['findings']:
            if not isinstance(finding, dict):
                continue
            name = finding.get('package')
            lock_entry = lock_entries.get(name)
            url = (lock_entry or {}).get('notification-url')
            metadata = corpus.metadata(name, url)
            parent = parent_of.get(name)
            claims.append(Claim(project, finding, lock_entry, metadata,
                                _parent_date(corpus, parent, lock_entry, lock_entries), parent=parent,
                                ambiguous=corpus.is_ambiguous(name, url)))

    return claims, missing


def _resolver(corpus: Corpus, lock_entries: 'dict[str, dict]') -> 'Callable[[str], Metadata | None]':
    """Metadata for a package as *this lock* names its repository.

    The parent scan is offered every package in the lock, so this is the first thing that asks the
    cache about most of them — and asking without the lock's own `notification-url` is what used to
    settle a multi-host package as ambiguous for the rest of the run.
    """
    def metadata_for(package: str) -> 'Metadata | None':
        return corpus.metadata(package, (lock_entries.get(package) or {}).get('notification-url'))

    return metadata_for


def _recorded(manifest: 'dict | None', key: str) -> 'dict | None':
    """What the run manifest says became of this target, or None when there is no manifest at all.

    None and an empty dict are different answers. None means nothing records what this run did, so
    there is nothing to gate on and the caller is told as much; an empty record is a record, and it
    gates like any other target that is not `ok`.
    """
    if manifest is None:
        return None

    return manifest.get('targets', {}).get(key) or {}


def _projects(reports_dir: str, manifest: 'dict | None') -> 'list[tuple[str, str]]':
    """Every project this run was meant to cover, with what the manifest says became of it.

    Without a manifest the directory is listed, which can only see what survived — so the caller is
    told that too, rather than being left to assume the corpus was whole.
    """
    if manifest is not None:
        targets = manifest.get('targets', {})
        listed = [(project, (recorded or {}).get('status') or 'no status recorded')
                  for project, recorded in sorted(targets.items())]

        return listed + _never_reached(manifest, targets)

    return [(entry[:-len('.json')], 'no run manifest, so nothing records whether it ran')
            for entry in sorted(os.listdir(reports_dir))
            if entry.endswith('.json') and entry != MANIFEST]


def _never_reached(manifest: dict, recorded: dict) -> 'list[tuple[str, str]]':
    """Every target the run set out to cover and has no record of at all.

    A target is written to the manifest when the run reaches it, so a run killed after 35 of 39
    projects holds 35 records and the other four are named by nothing — not by the status list, not
    by a file on disk, not by the directory. The intended membership is written when the run starts
    precisely so that an interruption is a countable absence rather than a smaller corpus.
    """
    return [(name, 'the run never reached it')
            for name in sorted(manifest.get('intended') or ()) if name not in recorded]


def _parent_date(corpus: Corpus, parent: 'str | None', lock_entry: 'dict | None',
                 lock_entries: 'dict[str, dict]') -> 'datetime.datetime | None':
    """The date the parent hands this exact version, or None.

    Only dates that are a release's are handed over — `parent_dates` rather than `times` — because a
    parent tag sitting on a commit three of its siblings share dates nothing, least of all somebody
    else's version of the same number.
    """
    if parent is None or lock_entry is None:
        return None
    parent_metadata = corpus.metadata(parent, (lock_entries.get(parent) or {}).get('notification-url'))
    if parent_metadata is None:
        return None
    normalized = normalize(lock_entry.get('version') or '')
    if normalized is None:
        return None

    return parent_metadata.parent_dates.get(normalized)


def _lock_entries(path: str) -> 'dict[str, dict]':
    document = read_json(path)
    if not isinstance(document, dict):
        return {}
    entries = {}
    for section in ('packages', 'packages-dev'):
        for package in document.get(section) or []:
            if isinstance(package, dict) and package.get('name'):
                entries[package['name']] = package

    return entries


def load_pairs(explain_dir: str, manifest: 'dict | None' = None) -> 'tuple[list[Pair], list[str]]':
    """Every explain target rendered whole, as a (text, JSON) pair.

    Returns (pairs, incomplete). With a run manifest, the target list is the manifest's and a target
    recorded as anything but complete is named in `incomplete` — which is also how a slug left over
    from an ad-hoc single-target run against a different PHAR stops being judged as current. Without
    one the directory is globbed, and the census says so, because a globbed directory cannot tell
    the tool which PHAR wrote what.
    """
    if not os.path.isdir(explain_dir):
        raise LoadError('%s: no such explain directory' % explain_dir)
    if manifest is not None:
        slugs = sorted(set(manifest.get('targets', {})) | set(manifest.get('intended') or ()))
    else:
        slugs = sorted(name[:-len('.json')] for name in os.listdir(explain_dir)
                       if name.endswith('.json') and name != MANIFEST)
    pairs = []
    incomplete = []
    for slug in slugs:
        recorded = _recorded(manifest, slug)
        if recorded is not None and recorded.get('status') != 'ok':
            incomplete.append('%s (%s)' % (slug, recorded.get('status') or 'the run never reached it'))
            continue
        json_path = os.path.join(explain_dir, slug + '.json')
        text_path = os.path.join(explain_dir, slug + '.txt')
        if not os.path.exists(json_path) or os.path.getsize(json_path) == 0:
            incomplete.append('%s (no document)' % slug)
            continue
        if not os.path.exists(text_path) or os.path.getsize(text_path) == 0:
            incomplete.append('%s (no page)' % slug)
            continue
        if recorded is not None and (sha256_file(json_path) != recorded.get('sha256')
                                     or sha256_file(text_path) != recorded.get('text_sha256')):
            # Written by the run and changed since. Whatever it is now, it is not evidence about
            # that run, and the digests are recorded precisely so this is not a judgement call.
            incomplete.append('%s (replaced since the run wrote it)' % slug)
            continue
        document = read_json(json_path)
        text = read_text(text_path)
        if not isinstance(document, dict) or 'finding' not in document or text is None:
            incomplete.append('%s (the document does not parse as one)' % slug)
            continue
        pairs.append(Pair(slug, text, document))

    return pairs, incomplete
