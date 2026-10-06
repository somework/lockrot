"""Turns a finished corpus run into the documents that the checks read.

Both loaders follow the run manifest and never a directory listing, because a project that lockrot
died on leaves no file. A target counts only when the manifest records it whole and its files still
hash to the recorded digests. A run writes the text before the JSON, so an interruption can leave a
fresh page beside a stale document.
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

        The memo key is `(name, notification_url)` and never the name alone. A package cached under
        two hosts is ambiguous only until a lock entry's `notification-url` names one, and a
        name-only memo answers the later question with the earlier answer, `None`.
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

    Returns (claims, missing). `missing` names each project that the run was meant to cover and did
    not finish: a crash, a timeout, a project never reached, an empty or unparsable report, or a
    report replaced after the run wrote it. The caller turns it into a run-level failure, because a
    check over part of the corpus does not answer the question.

    A report is read only when the run recorded it `ok` and it still hashes to the recorded digest.
    """
    if not os.path.isdir(reports_dir):
        raise LoadError('%s: no such run directory' % reports_dir)
    corpus = Corpus(cache_root, repo_root)
    claims = []
    missing = []
    for project, expected in _projects(reports_dir, manifest):
        recorded = _recorded(manifest, project)
        if recorded is not None and recorded.get('status') != 'ok':
            # This includes `unreadable output`: the run leaves that file on disk, and it is not a
            # report.
            missing.append('%s (%s)' % (project, expected))
            continue
        path = os.path.join(reports_dir, project + '.json')
        if not os.path.exists(path) or os.path.getsize(path) == 0:
            missing.append('%s (%s)' % (project, expected))
            continue
        if recorded is not None and sha256_file(path) != recorded.get('sha256'):
            missing.append('%s (replaced since the run wrote it)' % project)
            continue
        try:
            report = read_json(path)
        except CorpusDataError:
            # An unparsable report is one missing project and does not stop the audit of the others.
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

    The parent scan asks about every package in the lock. A call without the lock's
    `notification-url` settles a multi-host package as ambiguous for the rest of the run.
    """
    def metadata_for(package: str) -> 'Metadata | None':
        return corpus.metadata(package, (lock_entries.get(package) or {}).get('notification-url'))

    return metadata_for


def _recorded(manifest: 'dict | None', key: str) -> 'dict | None':
    """What the run manifest records for this target, or None when there is no manifest.

    None and an empty dict differ. None means that nothing records the run, so there is nothing to
    gate on. An empty record gates like any other target that is not `ok`.
    """
    if manifest is None:
        return None

    return manifest.get('targets', {}).get(key) or {}


def _projects(reports_dir: str, manifest: 'dict | None') -> 'list[tuple[str, str]]':
    """Every project that this run was meant to cover, with what the manifest records for it.

    Without a manifest this lists the directory, which shows only what survived, and each entry
    says so.
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
    """Every target that the run set out to cover and has no record of.

    A target reaches the manifest only when the run reaches it, so a run killed partway names the
    rest only in `intended`, which the run writes at its start.
    """
    return [(name, 'the run never reached it')
            for name in sorted(manifest.get('intended') or ()) if name not in recorded]


def _parent_date(corpus: Corpus, parent: 'str | None', lock_entry: 'dict | None',
                 lock_entries: 'dict[str, dict]') -> 'datetime.datetime | None':
    """The date the parent hands this exact version, or None.

    Only `parent_dates` count and not `times`: a parent tag on a commit that its siblings share
    dates nothing.
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

    Returns (pairs, incomplete). With a manifest, its targets are the target list, and a target not
    recorded complete is named in `incomplete`. This also keeps a slug from an ad-hoc run against
    another PHAR out of the current set. Without a manifest the directory is globbed, and the census
    says so, because a globbed directory cannot tell the tool which PHAR wrote what.
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
            incomplete.append('%s (replaced since the run wrote it)' % slug)
            continue
        try:
            document = read_json(json_path)
            text = read_text(text_path)
        except CorpusDataError:
            # Without a manifest no recorded status gates the target, so an unreadable file reaches
            # this point.
            document, text = None, None
        if not isinstance(document, dict) or 'finding' not in document or text is None:
            incomplete.append('%s (the document does not parse as one)' % slug)
            continue
        pairs.append(Pair(slug, text, document))

    return pairs, incomplete
