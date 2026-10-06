"""Materialises the corpus from the pinned manifest and never from the upstream HEAD.

A digest mismatch is not a new pin. It means that the bytes at that commit differ from the recorded
bytes, so a person must look. A transport failure is never recorded as a fact.
"""

import os
import shutil
import subprocess

from .jsonio import sha256_bytes, write_text_atomic
from .manifest import FILES, available
from .report import note


class FetchError(Exception):
    pass


def materialise(document: dict, repo_root: str,
                projects_dir: str) -> 'tuple[int, int, list[str]]':
    """Every pinned project on disk under `projects_dir`. Returns (written, skipped, failures)."""
    os.makedirs(projects_dir, exist_ok=True)
    written, skipped, failures = 0, 0, []
    for project in available(document):
        target = os.path.join(projects_dir, project['name'])
        if project['kind'] == 'fixture':
            if _copy_fixture(project, repo_root, target):
                written += 1
            else:
                failures.append('%s: %s is not in the tree' % (project['name'], project['path']))
            continue
        if _already_correct(project, target):
            skipped += 1
            continue
        try:
            _download(project, target)
            written += 1
        except FetchError as error:
            failures.append(str(error))

    note('%d project(s) written, %d already correct' % (written, skipped))

    return written, skipped, failures


def _copy_fixture(project: dict, repo_root: str, target: str) -> bool:
    source = os.path.join(repo_root, project['path'])
    if not os.path.isdir(source):
        return False
    os.makedirs(target, exist_ok=True)
    for name in FILES:
        origin = os.path.join(source, name)
        if os.path.isfile(origin):
            shutil.copyfile(origin, os.path.join(target, name))

    return os.path.isfile(os.path.join(target, 'composer.lock'))


def _already_correct(project: dict, target: str) -> bool:
    files = project.get('files') or {}
    if not files:
        # A project without recorded files is unpinned, not already correct: a skip leaves
        # the corpus one project short.
        return False
    for name, recorded in files.items():
        path = os.path.join(target, name)
        try:
            with open(path, 'rb') as handle:
                if sha256_bytes(handle.read()) != recorded['sha256']:
                    return False
        except FileNotFoundError:
            return False

    return True


def _download(project: dict, target: str) -> None:
    """Fetches and verifies both files before it writes either.

    A run that writes the first file before it verifies the second can leave a pair that exists
    in no upstream tree, and the next run audits it.
    """
    commit = project.get('commit')
    if not commit:
        raise FetchError('%s: no commit pinned; run `corpus refresh`' % project['name'])
    bodies = {}
    for name, recorded in (project.get('files') or {}).items():
        url = 'https://raw.githubusercontent.com/%s/%s/%s' % (project['repo'], commit, name)
        try:
            finished = subprocess.run(['curl', '-fsSL', url], capture_output=True, timeout=120)
        except (OSError, subprocess.SubprocessError) as error:
            raise FetchError('%s: %s could not be fetched (%s)' % (project['name'], name, error))
        if finished.returncode != 0:
            raise FetchError('%s: %s is not at %s' % (project['name'], name, commit[:12]))
        digest = sha256_bytes(finished.stdout)
        if digest != recorded['sha256']:
            raise FetchError(
                '%s: %s at %s hashes to %s, and the manifest records %s. Nothing was written. '
                'Either the history was rewritten or the download is damaged; `corpus refresh` is '
                'how a new pin is taken, deliberately.'
                % (project['name'], name, commit[:12], digest[:12], recorded['sha256'][:12]))
        bodies[name] = finished.stdout
    os.makedirs(target, exist_ok=True)
    for name, body in bodies.items():
        write_text_atomic(os.path.join(target, name), body.decode('utf-8'))
