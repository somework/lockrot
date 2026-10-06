"""The pinned membership of the corpus.

Every project is pinned: a fixture project by git, and a GitHub project by a commit SHA and a sha256
for each of its two files, both fetched from that one ref. A project that is renamed, archived, made
private or without its lock gets an `unavailable` date and stays in the manifest. A deletion
shrinks the corpus silently, and the differ reads a smaller corpus as unchanged.
"""

import hashlib
import json
import os
import subprocess
from typing import Sequence

from .jsonio import read_json, sha256_bytes, write_json_atomic
from .report import note

FILES = ('composer.lock', 'composer.json')


class ManifestError(Exception):
    pass


def path_for(repo_root: str) -> str:
    return os.path.join(repo_root, 'tools', 'corpus', 'corpus.lock.json')


def load(repo_root: str) -> dict:
    document = read_json(path_for(repo_root))
    if document is None:
        raise ManifestError('%s: no corpus manifest; run `corpus refresh` to create one'
                            % path_for(repo_root))
    if not isinstance(document.get('projects'), list):
        raise ManifestError('%s: no projects list' % path_for(repo_root))

    return document


def available(document: dict) -> 'list[dict]':
    return [project for project in document['projects'] if not project.get('unavailable')]


def digest_of(document: dict) -> str:
    """A digest over the pinned membership, so two runs can be told to be over the same corpus."""
    material = json.dumps(
        [[project.get('name'), project.get('kind'), project.get('commit'),
          project.get('files', {}).get('composer.lock', {}).get('sha256')]
         for project in available(document)],
        sort_keys=True)
    return hashlib.sha256(material.encode('utf-8')).hexdigest()[:16]


def refresh(repo_root: str, today: str, only: 'Sequence[str] | None' = None) -> int:
    """Re-resolve each GitHub project's default branch to a commit and re-record the two digests.

    This is the only code path that asks GitHub for HEAD. Everything else fetches a ref that the
    manifest already names, so a run is reproducible.
    """
    document = load(repo_root)
    changed = 0
    for project in document['projects']:
        if project.get('kind') != 'github':
            continue
        if only and project['name'] not in only:
            continue
        owner_repo = project['repo']
        commit = _resolve_head(owner_repo)
        if commit is None:
            # `_resolve_head` raises on a transport failure, so None means that GitHub answered and
            # the repository is gone.
            if not project.get('unavailable'):
                project['unavailable'] = today
                note('%s: no longer resolves; marked unavailable and kept' % owner_repo)
                changed += 1
            continue
        # Keep the `unavailable` marker until both files are in hand. If the code drops it early
        # and sets it again, every refresh rewrites the date.
        digests = {}
        absent = None
        for name in FILES:
            body = _fetch_raw(owner_repo, commit, name)
            if body is None:
                digests = None
                absent = name
                break
            digests[name] = {'sha256': sha256_bytes(body), 'bytes': len(body)}
        if digests is None:
            # The commit resolved, so the file is not in the repository. An existing marker keeps
            # the first day that the file went missing.
            if not project.get('unavailable'):
                project['unavailable'] = today
                note('%s: %s missing at %s; marked unavailable' % (owner_repo, absent, commit[:12]))
                changed += 1
            continue
        if project.get('unavailable'):
            note('%s: back, and both files are there again' % owner_repo)
            project.pop('unavailable')
            changed += 1
        if project.get('commit') != commit:
            note('%s: %s -> %s' % (owner_repo, (project.get('commit') or 'unpinned')[:12], commit[:12]))
            changed += 1
        project['commit'] = commit
        project['files'] = digests
    # Write only on a change, so a routine refresh leaves no diff.
    if changed:
        document['refreshed'] = today
        write_json_atomic(path_for(repo_root), document)
    note('%d project(s) changed' % changed)

    return changed


def _resolve_head(owner_repo: str) -> 'str | None':
    """The repository's HEAD commit, or None when the repository is not there.

    This raises on a network failure, which is not an answer about the repository. A None return
    marks projects `unavailable` after a failed request, and the next run audits a smaller corpus
    and reports it clean.
    """
    branch = _gh(['api', 'repos/%s' % owner_repo, '--jq', '.default_branch'], owner_repo)
    if branch is None:
        return None
    commit = _gh(['api', 'repos/%s/commits/%s' % (owner_repo, branch.strip()), '--jq', '.sha'],
                 owner_repo)

    return commit.strip() if commit else None


# curl's exit status for an HTTP error answer, which `-f` gives for a 404. Any other failure status
# is DNS, TLS, a refused connection or a timeout, and is not an answer.
CURL_HTTP_ERROR = 22


def _fetch_raw(owner_repo: str, commit: str, name: str) -> 'bytes | None':
    """The file as it was at that one commit, from the raw host, so both files share a ref."""
    url = 'https://raw.githubusercontent.com/%s/%s/%s' % (owner_repo, commit, name)
    try:
        finished = subprocess.run(['curl', '-fsSL', url], capture_output=True, timeout=120)
    except (OSError, subprocess.SubprocessError) as error:
        raise ManifestError('%s: %s could not be fetched (%s). Nothing was re-pinned.'
                            % (owner_repo, name, error))
    if finished.returncode == 0:
        return finished.stdout
    if finished.returncode == CURL_HTTP_ERROR:
        return None

    raise ManifestError('%s: fetching %s failed with curl status %d, which is not an HTTP answer. '
                        'Nothing was re-pinned; a refresh that cannot reach the network must not '
                        'decide a project is gone.' % (owner_repo, name, finished.returncode))


def _gh(arguments: 'Sequence[str]', owner_repo: str) -> 'str | None':
    """What `gh` answered, None for a repository it says is not there, and a raised error otherwise."""
    try:
        finished = subprocess.run(['gh'] + arguments, capture_output=True, text=True, timeout=60)
    except (OSError, subprocess.SubprocessError) as error:
        raise ManifestError('%s: `gh %s` could not run (%s). Nothing was re-pinned.'
                            % (owner_repo, arguments[0], error))
    if finished.returncode == 0:
        return finished.stdout
    combined = (finished.stderr or '').lower()
    if 'not found' in combined or 'could not resolve to a repository' in combined:
        return None

    raise ManifestError('%s: `gh %s` failed and did not say the repository is missing: %s. '
                        'Nothing was re-pinned.'
                        % (owner_repo, arguments[0], (finished.stderr or '').strip()[:200]))
