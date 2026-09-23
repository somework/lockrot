"""Turning a finished corpus run into the documents the checks read.

Both halves are driven by the run manifest, not by a directory listing, and that is the whole of the
design here. A project lockrot died on leaves *no file at all* — so a reader that lists the directory
cannot tell a corpus of 39 from the 30 that survived, and the checks then pass over 77% of the
evidence and report a clean census. The manifest is the only record that the other nine were ever
attempted.

The same for a pair: a target is judged only when the manifest records both of its files whole and
both still hash to what was recorded. The text is written before the JSON, so an interruption leaves
a fresh page beside a stale document — the one pairing every contradiction check assumes cannot
happen — and a file replaced after the run is not evidence about that run at all.
"""

import datetime
import os

from .claims import Claim
from .explain import Pair
from .jsonio import read_json, read_text, sha256_file
from .metadata import Metadata, Parents
from .p2 import AmbiguousProvider, ProviderCache
from .semver import normalize


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
        if name in self._metadata:
            return self._metadata[name]
        try:
            provider = self.cache.get(name, notification_url)
        except AmbiguousProvider:
            self._ambiguous.add(name)
            self._metadata[name] = None

            return None
        result = Metadata(provider) if provider is not None else None
        self._metadata[name] = result

        return result

    def is_ambiguous(self, name: str) -> bool:
        return name in self._ambiguous


MANIFEST = 'run.json'


def load_claims(reports_dir: str, projects_dir: str, cache_root: str, repo_root: str,
                manifest: 'dict | None' = None) -> 'tuple[list[Claim], list[str]]':
    """Every finding of every project report, with its lock entry and its derived metadata.

    Returns (claims, missing). `missing` names every project the run was supposed to cover and did
    not finish: one lockrot crashed on, one that timed out, one whose report is empty or will not
    parse. It is not a diagnostic — the caller turns it into a run-level failure, because a check
    that ran over three quarters of the corpus has not answered the question that was asked.
    """
    if not os.path.isdir(reports_dir):
        raise LoadError('%s: no such run directory' % reports_dir)
    corpus = Corpus(cache_root, repo_root)
    claims = []
    missing = []
    for project, expected in _projects(reports_dir, manifest):
        path = os.path.join(reports_dir, project + '.json')
        if not os.path.exists(path) or os.path.getsize(path) == 0:
            missing.append('%s (%s)' % (project, expected))
            continue
        report = read_json(path)
        if not isinstance(report, dict) or not isinstance(report.get('findings'), list):
            missing.append('%s (the report does not parse as one)' % project)
            continue
        lock_entries = _lock_entries(os.path.join(projects_dir, project, 'composer.lock'))
        parent_of = corpus.parents.by_child(list(lock_entries), corpus.metadata)
        for finding in report['findings']:
            if not isinstance(finding, dict):
                continue
            name = finding.get('package')
            lock_entry = lock_entries.get(name)
            metadata = corpus.metadata(name, (lock_entry or {}).get('notification-url'))
            parent = parent_of.get(name)
            claims.append(Claim(project, finding, lock_entry, metadata,
                                _parent_date(corpus, parent, lock_entry), parent=parent,
                                ambiguous=corpus.is_ambiguous(name)))

    return claims, missing


def _projects(reports_dir: str, manifest: 'dict | None') -> 'list[tuple[str, str]]':
    """Every project this run was meant to cover, with what the manifest says became of it.

    Without a manifest the directory is listed, which can only see what survived — so the caller is
    told that too, rather than being left to assume the corpus was whole.
    """
    if manifest is not None:
        return [(project, (recorded or {}).get('status') or 'no status recorded')
                for project, recorded in sorted(manifest.get('targets', {}).items())]

    return [(entry[:-len('.json')], 'no run manifest, so nothing records whether it ran')
            for entry in sorted(os.listdir(reports_dir))
            if entry.endswith('.json') and entry != MANIFEST]


def _parent_date(corpus: Corpus, parent: 'str | None',
                 lock_entry: 'dict | None') -> 'datetime.datetime | None':
    """The date the parent hands this exact version, or None.

    Only dates that are a release's are handed over — `parent_dates` rather than `times` — because a
    parent tag sitting on a commit three of its siblings share dates nothing, least of all somebody
    else's version of the same number.
    """
    if parent is None or lock_entry is None:
        return None
    parent_metadata = corpus.metadata(parent)
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
        slugs = sorted(manifest.get('targets', {}))
    else:
        slugs = sorted(name[:-len('.json')] for name in os.listdir(explain_dir)
                       if name.endswith('.json') and name != MANIFEST)
    pairs = []
    incomplete = []
    for slug in slugs:
        recorded = manifest['targets'].get(slug, {}) if manifest is not None else None
        if recorded is not None and recorded.get('status') != 'ok':
            incomplete.append('%s (%s)' % (slug, recorded.get('status') or 'never recorded'))
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
