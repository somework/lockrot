"""The Composer repository cache, read as the run under audit read it.

Entries are minified: each carries only what differs from the entry before it, and a key to
delete carries the string `__unset`. Merge first and drop the sentinels second, or a package keeps
a `time`, `source` or `replace` that a later version removed. Entries are newest first. This
module indexes every repository host under the cache root, because a read of
repo.packagist.org alone counts every drupal and wp-packages package as uncached.
"""

import os
import re

from .jsonio import CorpusDataError, read_json

_PROVIDER = re.compile(r'^provider-(.+)\.json$')


class AmbiguousProvider(Exception):
    """A package cached under more than one repository, with nothing in the lock to settle it.

    It is not a data error. The caller declines the package, because the wrong repository's
    releases produce a finding that lockrot does not have.
    """

    def __init__(self, name: str, hosts: 'list[str]') -> None:
        super().__init__('%s is cached under %s' % (name, ', '.join(hosts)))
        self.name = name
        self.hosts = hosts


class Provider:
    """One package's version list, reconstructed, newest first as the document has it."""

    def __init__(self, name: str, host: str, versions: 'list[dict]') -> None:
        self.name = name
        self.host = host
        self.versions = versions


class ProviderCache:
    """Every `provider-*.json` under a Composer cache's `repo/` directory, indexed by package name.

    A package under more than one host is kept as a list and not resolved. The lock's
    `notification-url` can name the repository that served it, and where it does not, the caller
    declines the package, because a guess audits it against the wrong repository.
    """

    def __init__(self, cache_root: str) -> None:
        self.cache_root = cache_root
        self._index = {}
        self._parsed = {}
        self._scan()

    def _scan(self) -> None:
        repo_root = os.path.join(self.cache_root, 'repo')
        if not os.path.isdir(repo_root):
            raise CorpusDataError(repo_root, 'no repo/ directory: this is not a Composer cache')
        for host in sorted(os.listdir(repo_root)):
            host_dir = os.path.join(repo_root, host)
            if not os.path.isdir(host_dir):
                continue
            for entry in os.listdir(host_dir):
                match = _PROVIDER.match(entry)
                if match is None:
                    continue
                name = match.group(1).replace('~', '/')
                self._index.setdefault(name, []).append((host, os.path.join(host_dir, entry)))

    def hosts_for(self, name: str) -> 'list[str]':
        return [host for host, _ in self._index.get(name, [])]

    def get(self, name: str, notification_url: 'str | None' = None) -> 'Provider | None':
        """The reconstructed provider for `name`, or None when no host cached one.

        Raises CorpusDataError when a document exists and does not parse: a corrupt cache entry is
        a reason the run cannot answer, not a package to pass over.
        """
        entries = self._index.get(name)
        if not entries:
            return None
        if len(entries) > 1 and notification_url:
            host = _netloc(notification_url)
            narrowed = [item for item in entries if host and host in item[0]]
            if narrowed:
                entries = narrowed
        if len(entries) > 1:
            raise AmbiguousProvider(name, [host for host, _ in entries])
        host, path = entries[0]
        if path in self._parsed:
            return self._parsed[path]
        document = read_json(path)
        if document is None:
            return None
        if not isinstance(document, dict):
            raise CorpusDataError(path, 'is not a JSON object')
        if 'security-advisories' in document and 'packages' not in document:
            # Composer files an advisories answer under the same `provider-<vendor>~<package>.json`
            # name as a version list, and packages.drupal.org does so for drupal/core. It holds no
            # versions, so it is an absence and not a corruption.
            self._parsed[path] = None

            return None
        packages = document.get('packages')
        if not isinstance(packages, dict) or name not in packages:
            raise CorpusDataError(path, 'holds no packages[%s] entry' % name)
        provider = Provider(name, host, _expand(packages[name], path))
        self._parsed[path] = provider

        return provider


def _expand(entries: object, path: str) -> 'list[dict]':
    """Minified entries folded into whole ones, in the document's own order (newest first).

    A repository other than Packagist can answer in Composer's older shape, where the package maps
    version strings to whole entries. Nothing depends on the order, only on whole entries, so that
    shape is read as it is.
    """
    if isinstance(entries, dict):
        return [dict(entry) for entry in entries.values() if isinstance(entry, dict)]
    if not isinstance(entries, list):
        raise CorpusDataError(path, 'packages entry is neither a list nor a version map')
    expanded = []
    current = {}
    for entry in entries:
        if not isinstance(entry, dict):
            raise CorpusDataError(path, 'a version entry is not an object')
        current = dict(current)
        current.update(entry)
        current = {key: value for key, value in current.items() if value != '__unset'}
        expanded.append(dict(current))

    return expanded


def _netloc(notification_url: object) -> str:
    """The host of a notification URL, which is the part that identifies the repository.

    Composer names a cache directory after the whole repository URL with every non-alphanumeric run
    replaced by a dash, and a notification URL is neither that URL nor a prefix of it: packagist.org
    notifies from `packagist.org` while it serves p2 from `repo.packagist.org`. The host is the part
    that the two share.
    """
    match = re.match(r'^[a-z][a-z0-9+.-]*://([^/]+)', str(notification_url).lower())

    return match.group(1) if match else ''
