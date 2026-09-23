"""The Composer repository cache, read as the run under audit read it.

Two properties of a p2 document decide everything downstream and both have already been got wrong:

Entries are minified. Each one carries only what differs from the entry before it, and a key it
means to delete carries the string `__unset`. Merge first, then drop the sentinels — in that order.
A package that keeps a `time`, a `source` or a `replace` a later version explicitly removed corrupts
the newest-release date, the shared-commit counts and monorepo detection at the same time.

Entries are newest first. A `replace: <other> self.version` link read off one end of the array gave
laravel/framework no children at all, which silently removed the parent-dating branch from three of
the five claims checks while the counters still looked busy.

Every repository host under the cache root is indexed, not just repo.packagist.org. The scratchpad
ancestor read one host and counted every drupal and wp-packages package as uncached, in the same
bucket as a package that genuinely has no document.
"""

import os
import re

from .jsonio import CorpusDataError, read_json

_PROVIDER = re.compile(r'^provider-(.+)\.json$')


class AmbiguousProvider(Exception):
    """A package cached under more than one repository, with nothing in the lock to settle it.

    Not a data error: the cache is fine and so is the lock. It is a package this tool declines to
    audit, by name, because auditing it against the wrong repository's releases would produce a
    finding lockrot does not have.
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

    A package present under more than one host is kept as a list rather than resolved: the lock's
    `notification_url` names the repository that served it, and where it does not settle the
    question the caller declines the package by name instead of guessing. Guessing is how a package
    gets audited against the wrong repository's idea of its releases.
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
            # name as a version list — packages.drupal.org serves one for drupal/core. It carries no
            # versions, so there is nothing here to audit a claim against, and that is an absence
            # rather than a corruption.
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

    A repository that is not Packagist may answer in Composer's older shape, where the package maps
    version strings to whole entries with nothing minified — repo.wp-packages.org does. Nothing
    downstream depends on the order, only on each entry being whole, so that shape is read as it is
    rather than converted.
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
    """The host out of a notification URL, which is all of it that identifies the repository.

    Composer names a cache directory after the whole repository URL with every non-alphanumeric run
    replaced by a dash, and a repository's notification URL is neither that URL nor a prefix of it:
    packagist.org notifies from `packagist.org` while serving p2 from `repo.packagist.org`, and
    drupal notifies from `/8/downloads` while its directory ends `-8`. The host is the part the two
    share, so it is the part compared.
    """
    match = re.match(r'^[a-z][a-z0-9+.-]*://([^/]+)', str(notification_url).lower())

    return match.group(1) if match else ''
