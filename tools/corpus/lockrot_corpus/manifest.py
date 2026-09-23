"""The pinned membership of the corpus.

The scratchpad fetcher pulled each project's composer.lock from its default branch through the
contents API and kept nothing — no commit, no digest, no timestamp. Six months later the same
script produces a different corpus under the same directory names, and nothing on disk says so. It
also fetched composer.json through a second, independent call, which can straddle a push and pair a
new manifest with an older lock.

So every project is pinned. Twenty-one of the thirty-nine are pinned already, by git: they are the
recorded fixtures this repository ships, and a design that treats the corpus as one homogeneous set
throws away the majority of the evidence that is reproducible today. The other eighteen carry a
commit SHA and a sha256 for each of the two files, both fetched from that one ref.

A project that has been renamed, archived, made private or has dropped its lock is marked, with a
date, and kept. Deleting it would make the corpus shrink silently — and the differ, which skips
anything missing on one side, reads a shrunken corpus as an unchanged one.
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

    The only code path allowed to ask GitHub what HEAD is. Everything else fetches a ref this file
    already names, which is what makes a run reproducible rather than merely repeatable.
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
            # Gone, not unreachable: _resolve_head raises on a transport failure, so reaching here
            # means GitHub answered and the repository is not there.
            if not project.get('unavailable'):
                project['unavailable'] = today
                note('%s: no longer resolves; marked unavailable and kept' % owner_repo)
                changed += 1
            continue
        if project.get('unavailable'):
            project.pop('unavailable')
            changed += 1
        digests = {}
        for name in FILES:
            body = _fetch_raw(owner_repo, commit, name)
            if body is None:
                digests = None
                break
            digests[name] = {'sha256': sha256_bytes(body), 'bytes': len(body)}
        if digests is None:
            # The commit resolved, so the repository is reachable; this file is genuinely not in it.
            project['unavailable'] = today
            note('%s: %s missing at %s; marked unavailable' % (owner_repo, ', '.join(FILES), commit[:12]))
            changed += 1
            continue
        if project.get('commit') != commit:
            note('%s: %s -> %s' % (owner_repo, (project.get('commit') or 'unpinned')[:12], commit[:12]))
            changed += 1
        project['commit'] = commit
        project['files'] = digests
    if changed:
        document['refreshed'] = today
        write_json_atomic(path_for(repo_root), document)
    # Nothing rewritten when nothing moved: a routine refresh that finds no change must leave no
    # diff, or the file stops being reviewable.
    note('%d project(s) changed' % changed)

    return changed


def _resolve_head(owner_repo: str) -> 'str | None':
    """The repository's current HEAD commit, or None when the repository is genuinely not there.

    A network that was not reachable is not an answer about the repository, and this raises rather
    than returning None for it — otherwise one flaky afternoon marks a third of the corpus
    `unavailable` and the next run audits a quarter of the evidence while reporting it clean. The
    rule is bin/record-fixtures': "recorded a failure" and "failed to record" are different facts.
    """
    branch = _gh(['api', 'repos/%s' % owner_repo, '--jq', '.default_branch'], owner_repo)
    if branch is None:
        return None
    commit = _gh(['api', 'repos/%s/commits/%s' % (owner_repo, branch.strip()), '--jq', '.sha'],
                 owner_repo)

    return commit.strip() if commit else None


# curl's exit status for "the server answered with an HTTP error", which with -f is what a 404 is.
# Anything else it returns is DNS, TLS, a refused connection or a timeout — not an answer.
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
