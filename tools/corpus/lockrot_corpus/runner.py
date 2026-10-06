"""Runs one lockrot archive over the corpus and records enough to compare the result later.

A target is complete only when the manifest says so and its file still hashes to the recorded
digest, because a run killed at the timeout leaves a non-empty half-written file. The GitHub token
is asserted, and a run without one needs `--anon`: a tokenless run reports S10 across the corpus and
must not be compared with a run that had a token. Every run keeps its stderr beside its output. The
day is pinned, because lockrot's age-based verdicts change with the calendar and not with the code.
"""

import datetime
import os
import subprocess
import time
from typing import Sequence

from . import VERSION
from .jsonio import (CorpusDataError, read_json, sha256_file, write_json_atomic,
                     write_text_atomic)
from .report import note

DEFAULT_TIMEOUT = 900
EXPLAIN_TIMEOUT = 300
# A fixed width, because the line shape that the patterns in catalog.py read depends on where the
# text wraps.
EXPLAIN_COLUMNS = '200'


class RunError(Exception):
    """A reason the run cannot proceed. Turns into exit 2, never into a finding."""


def token_from_environment(anon: bool) -> 'str | None':
    """The GitHub token that this run uses, asserted and not assumed.

    Without a token, lockrot gives a different answer and not a slower one: it reports "repository
    activity not checked". `--anon` asks for that answer.
    """
    if anon:
        return None
    token = os.environ.get('GITHUB_TOKEN')
    if not token:
        try:
            token = subprocess.run(['gh', 'auth', 'token'], capture_output=True, text=True,
                                   timeout=30).stdout.strip()
        except (OSError, subprocess.SubprocessError):
            token = ''
    if not token:
        raise RunError('no GITHUB_TOKEN and `gh auth token` gave nothing. Set one, or pass --anon '
                       'to run tokenless on purpose — a tokenless run reports S10 across the corpus '
                       'and must not be compared against a run that had a token.')

    return token


# Every name that could give the child a credential or change what it does. Removal of GITHUB_TOKEN
# alone does not make a run tokenless: lockrot reads LOCKROT_GITHUB_TOKEN first (`Tokens`), then
# Composer's github-oauth from COMPOSER_AUTH or a global auth.json, and it has a GitLab pair of its
# own. Otherwise an `--anon` run could authenticate while its manifest says anonymous.
INHERITED = ('LOCKROT_GITHUB_TOKEN', 'GITHUB_TOKEN', 'LOCKROT_GITLAB_TOKEN', 'GITLAB_TOKEN',
             'COMPOSER_AUTH',
             # Not credentials: LOCKROT_DISABLE skips the command and LOCKROT_FAIL_ON changes
             # the exit code that the manifest records.
             'LOCKROT_DISABLE', 'LOCKROT_FAIL_ON')


def run_environment(token: 'str | None', cache_root: str, today: str) -> 'dict[str, str]':
    environment = dict(os.environ)
    environment['COMPOSER_CACHE_DIR'] = cache_root
    # A run-owned Composer home, so a global auth.json cannot reach the child either.
    environment['COMPOSER_HOME'] = os.path.join(cache_root, 'composer-home')
    os.makedirs(environment['COMPOSER_HOME'], exist_ok=True)
    environment['LOCKROT_TODAY'] = today
    for name in INHERITED:
        environment.pop(name, None)
    if token:
        environment['GITHUB_TOKEN'] = token

    return environment


def load_manifest(out_dir: str) -> 'dict | None':
    document = read_json(os.path.join(out_dir, 'run.json'))

    return document if isinstance(document, dict) else None


def new_manifest(kind: str, phar: str, today: str, cache_root: str, anon: bool,
                 corpus_digest: 'str | None' = None) -> dict:
    return {
        'tool_version': VERSION,
        'kind': kind,
        'phar': os.path.abspath(phar),
        'phar_sha256': sha256_file(phar),
        'lockrot_version': None,
        'today': today,
        'cache_root': os.path.abspath(cache_root),
        # Never record the token itself, only whether there was one.
        'token_mode': 'anonymous' if anon else 'token',
        # Recorded at the start of the run, so a run resumed after a re-pin does not claim a
        # membership that part of its output never saw.
        'corpus_digest': corpus_digest,
        # Real wall-clock time, unlike `today`, the pinned day. The differ reads these against
        # `ACTIVITY_TTL_SECONDS`.
        'started': _now(),
        'finished': None,
        # Written before any target runs. A target reaches `targets` only when the run reaches
        # it, so a killed run looks like a smaller corpus that finished.
        'intended': [],
        'targets': {},
    }


def _now() -> str:
    return datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat()


def _is_complete(manifest: dict, key: str, path: str) -> bool:
    recorded = manifest['targets'].get(key)
    if not recorded or recorded.get('status') != 'ok':
        return False

    return recorded.get('sha256') is not None and recorded['sha256'] == sha256_file(path)


def _begin(manifest: dict, intended: 'Sequence[str]') -> None:
    """Records what this invocation covers and clears `finished`, on a resume too.

    A killed resume must not keep the timestamp of an earlier completion and read as whole.
    """
    manifest['finished'] = None
    manifest['intended'] = list(intended)


def _record(manifest: dict, key: str, status: str, path: 'str | None' = None,
            exit_code: 'int | None' = None, seconds: 'float | None' = None) -> None:
    manifest['targets'][key] = {
        'status': status,
        'exit': exit_code,
        'sha256': sha256_file(path) if path else None,
        'seconds': round(seconds, 1) if seconds is not None else None,
    }


def run_reports(phar: str, projects_dir: str, out_dir: str, cache_root: str, today: str,
                anon: bool = False, timeout: int = DEFAULT_TIMEOUT, target_php: str = '8.4',
                interpreter: str = 'php', corpus_digest: 'str | None' = None) -> dict:
    """One JSON report per project, resumable, with every run's stderr kept beside it."""
    # Absolute, because each run uses the project directory as its working directory, where a
    # relative path does not exist.
    phar = os.path.abspath(phar)
    token = token_from_environment(anon)
    os.makedirs(out_dir, exist_ok=True)
    manifest = load_manifest(out_dir) or new_manifest('report', phar, today, cache_root, anon,
                                                      corpus_digest)
    _assert_same_run(manifest, phar, today, cache_root, anon, corpus_digest)
    environment = run_environment(token, cache_root, today)
    # The interpreter is a parameter so that a test can pass a stub that fails halfway through a
    # write.
    projects = sorted(name for name in os.listdir(projects_dir)
                      if os.path.isfile(os.path.join(projects_dir, name, 'composer.lock')))
    if not projects:
        raise RunError('%s holds no project with a composer.lock' % projects_dir)
    _begin(manifest, projects)
    write_json_atomic(os.path.join(out_dir, 'run.json'), manifest)

    for project in projects:
        output = os.path.join(out_dir, project + '.json')
        if _is_complete(manifest, project, output):
            continue
        started = time.time()
        command = [interpreter, phar, '--format=json', '--target-php=' + target_php]
        try:
            finished = subprocess.run(command, cwd=os.path.join(projects_dir, project),
                                      env=environment, capture_output=True, timeout=timeout)
        except subprocess.TimeoutExpired:
            _record(manifest, project, 'timeout', exit_code=124, seconds=time.time() - started)
            note('%s: timed out after %ds, will be retried on the next run' % (project, timeout))
            write_json_atomic(os.path.join(out_dir, 'run.json'), manifest)
            continue
        write_text_atomic(os.path.join(out_dir, project + '.err'),
                          finished.stderr.decode('utf-8', 'replace'))
        if finished.returncode not in (0, 1, 2, 3, 4):
            # Exit codes 0 to 4 are lockrot's own answers. Any other code means that the process
            # failed.
            _record(manifest, project, 'failed', exit_code=finished.returncode,
                    seconds=time.time() - started)
            note('%s: lockrot exited %d; stderr kept in %s.err' % (project, finished.returncode, project))
            write_json_atomic(os.path.join(out_dir, 'run.json'), manifest)
            continue
        write_text_atomic(output, finished.stdout.decode('utf-8', 'replace'))
        # An answer must still be a report. Record an unparsable output and go on, so that one
        # project does not stop the others.
        try:
            report = read_json(output)
        except CorpusDataError as error:
            report = None
            note('%s: %s' % (project, error))
        if not isinstance(report, dict) or not isinstance(report.get('findings'), list):
            _record(manifest, project, 'unreadable output', exit_code=finished.returncode,
                    seconds=time.time() - started)
            write_json_atomic(os.path.join(out_dir, 'run.json'), manifest)
            continue
        _record(manifest, project, 'ok', path=output, exit_code=finished.returncode,
                seconds=time.time() - started)
        if manifest['lockrot_version'] is None:
            manifest['lockrot_version'] = (report.get('lockrot') or {}).get('version')
        write_json_atomic(os.path.join(out_dir, 'run.json'), manifest)
        note('%s: %d findings' % (project, len(report['findings'])))

    manifest['finished'] = _now()
    write_json_atomic(os.path.join(out_dir, 'run.json'), manifest)

    return manifest


def _slug(project: str, package: str) -> str:
    return '%s@%s' % (project, package.replace('/', '_'))


def run_explains(phar: str, projects_dir: str, targets: 'Sequence[tuple[str, str]]', out_dir: str,
                 cache_root: str, today: str, anon: bool = False,
                 timeout: int = EXPLAIN_TIMEOUT, target_php: str = '8.4',
                 interpreter: str = 'php', corpus_digest: 'str | None' = None) -> dict:
    """Two renderings per target, the page and the document. A target is done only when both are.

    The page is written before the document, so an interruption can leave a fresh page beside a
    stale document, and the contradiction checks assume that pair cannot exist.
    """
    phar = os.path.abspath(phar)
    token = token_from_environment(anon)
    os.makedirs(out_dir, exist_ok=True)
    manifest = load_manifest(out_dir) or new_manifest('explain', phar, today, cache_root, anon,
                                                      corpus_digest)
    _assert_same_run(manifest, phar, today, cache_root, anon, corpus_digest)
    environment = run_environment(token, cache_root, today)
    environment['COLUMNS'] = EXPLAIN_COLUMNS
    _begin(manifest, [_slug(project, package) for project, package in targets])
    write_json_atomic(os.path.join(out_dir, 'run.json'), manifest)

    for project, package in targets:
        slug = _slug(project, package)
        text_path = os.path.join(out_dir, slug + '.txt')
        json_path = os.path.join(out_dir, slug + '.json')
        recorded = manifest['targets'].get(slug)
        if (recorded and recorded.get('status') == 'ok'
                and recorded.get('sha256') == sha256_file(json_path)
                and recorded.get('text_sha256') == sha256_file(text_path)):
            continue
        project_dir = os.path.join(projects_dir, project)
        if not os.path.isdir(project_dir):
            manifest['targets'][slug] = {'status': 'no such project', 'exit': None}
            continue
        started = time.time()
        try:
            page = subprocess.run([interpreter, phar, '--explain=' + package,
                                   '--target-php=' + target_php],
                                  cwd=project_dir, env=environment, capture_output=True, timeout=timeout)
            document = subprocess.run([interpreter, phar, '--explain=' + package, '--format=json',
                                       '--target-php=' + target_php],
                                      cwd=project_dir, env=environment, capture_output=True,
                                      timeout=timeout)
        except subprocess.TimeoutExpired:
            manifest['targets'][slug] = {'status': 'timeout', 'exit': 124}
            write_json_atomic(os.path.join(out_dir, 'run.json'), manifest)
            continue
        if not page.stdout or not document.stdout:
            manifest['targets'][slug] = {'status': 'empty rendering', 'exit': document.returncode}
            write_json_atomic(os.path.join(out_dir, 'run.json'), manifest)
            continue
        write_text_atomic(text_path, page.stdout.decode('utf-8', 'replace'))
        write_text_atomic(json_path, document.stdout.decode('utf-8', 'replace'))
        # Parse the output before the run records `ok`. A target recorded `ok` is never retried on
        # a resume, so an unreadable one stays unreadable.
        try:
            parsed = read_json(json_path)
        except CorpusDataError as error:
            parsed = None
            note('%s: %s' % (slug, error))
        if not isinstance(parsed, dict) or 'finding' not in parsed:
            manifest['targets'][slug] = {'status': 'unreadable rendering',
                                         'exit': document.returncode}
            write_json_atomic(os.path.join(out_dir, 'run.json'), manifest)
            continue
        manifest['targets'][slug] = {
            'status': 'ok',
            'exit': document.returncode,
            'sha256': sha256_file(json_path),
            'text_sha256': sha256_file(text_path),
            'seconds': round(time.time() - started, 1),
        }
        write_json_atomic(os.path.join(out_dir, 'run.json'), manifest)

    manifest['finished'] = _now()
    write_json_atomic(os.path.join(out_dir, 'run.json'), manifest)

    return manifest


def read_targets(path: str) -> 'list[tuple[str, str]]':
    """The committed target list: two tab-separated columns, `#` comments, blank lines ignored."""
    targets = []
    with open(path, encoding='utf-8') as handle:
        for number, line in enumerate(handle, 1):
            line = line.rstrip('\n')
            if not line.strip() or line.lstrip().startswith('#'):
                continue
            parts = line.split('\t')
            if len(parts) != 2:
                raise RunError('%s line %d: expected two tab-separated columns' % (path, number))
            targets.append((parts[0].strip(), parts[1].strip()))
    if not targets:
        raise RunError('%s names no targets. An explain run over nothing is not a smaller run — it '
                       'is a run whose checks will all report an empty population.' % path)

    return targets


def _assert_same_run(manifest: dict, phar: str, today: str, cache_root: str, anon: bool,
                     corpus_digest: 'str | None' = None) -> None:
    """A resumed run must be the same run. Otherwise its manifest describes output it did not write."""
    mode = 'anonymous' if anon else 'token'
    digest = sha256_file(phar)
    fields = [('phar_sha256', manifest.get('phar_sha256'), digest),
              ('today', manifest.get('today'), today),
              ('token_mode', manifest.get('token_mode'), mode),
              ('cache_root', manifest.get('cache_root'), os.path.abspath(cache_root))]
    if corpus_digest is not None:
        fields.append(('corpus_digest', manifest.get('corpus_digest'), corpus_digest))
    for field, was, now in fields:
        if was is not None and was != now:
            raise RunError('this output directory was written with %s=%s and you are now passing %s; '
                           'use a different --out rather than mixing two runs in one directory'
                           % (field, was, now))
