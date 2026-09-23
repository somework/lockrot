"""Running one lockrot archive over the corpus, and recording enough to compare the result later.

This replaces eight near-identical shell loops that differed by one literal each, so a fix to one
never reached the others. Four things they got wrong are fixed here by construction:

  * `[ -s file ] && continue` accepts any non-empty file. A run killed at the 900-second timeout
    leaves a half-written JSON of non-zero size, and the target is then never retried again — one
    downstream reader aborts on it, another counts it unreadable, and none of them is right. A
    target is complete here only when the manifest says so *and* the file still hashes to what was
    recorded.
  * `gh auth token` failing yields an empty string, which `set -u` does not catch. The run then
    completes tokenless and reports S10 across the whole corpus, which is the single most likely
    way to make a diff show a large regression that is not there. So the token is asserted, and
    running without one is something you ask for.
  * Most of those loops sent stderr to /dev/null, so a PHP fatal left no evidence beyond an exit
    code in a log nobody read. Every run keeps its stderr beside its output.
  * Most of them did not create the output directory. The redirect then failed inside the subshell,
    php never ran, and 39 exit codes looked like a uniformly failing run rather than a total no-op.

The day is pinned. lockrot's age-based verdicts move at the three- and five-year boundaries with no
code change, so a comparison whose two halves ran on different days attributes the calendar to the
code. libyears itself is clock-free by design and is unaffected either way.
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
# The text is rendered at a fixed width because every anchored pattern in catalog.py reads a line
# whose shape depends on where it wrapped.
EXPLAIN_COLUMNS = '200'


class RunError(Exception):
    """A reason the run cannot proceed. Turns into exit 2, never into a finding."""


def token_from_environment(anon: bool) -> 'str | None':
    """The GitHub token this run will use, asserted rather than assumed.

    Anonymous GitHub allows 60 requests an hour, so a tokenless run does not produce a slower
    version of the same answer — it produces a different one, full of "repository activity not
    checked". That is a legitimate thing to want, and `--anon` is how you say you want it.
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


# Every name that could hand the child a credential or change what it does, cleared before the run
# decides what to put back. Removing GITHUB_TOKEN alone is not a tokenless run: lockrot reads
# LOCKROT_GITHUB_TOKEN first (src/Data/Forge/Tokens.php), falls back to Composer's github-oauth out
# of COMPOSER_AUTH or a global auth.json, and has its own GitLab pair. On any machine where
# `composer config --global github-oauth.github.com …` has ever been run, a `--anon` run would be
# fully authenticated while the manifest recorded it as anonymous — and two such runs would then
# pass every comparability check and differ by S10 across the whole corpus.
INHERITED = ('LOCKROT_GITHUB_TOKEN', 'GITHUB_TOKEN', 'LOCKROT_GITLAB_TOKEN', 'GITLAB_TOKEN',
             'COMPOSER_AUTH',
             # Not credentials: LOCKROT_DISABLE short-circuits the command entirely and
             # LOCKROT_FAIL_ON changes the exit code the manifest records, since the runner passes
             # no --fail-on of its own.
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
        'lockrot_version': None,  # filled from the first report that names it
        'today': today,
        'cache_root': os.path.abspath(cache_root),
        # The token itself is never recorded anywhere — only whether there was one, which is the
        # part that makes two runs comparable or not.
        'token_mode': 'anonymous' if anon else 'token',
        # Recorded when the run starts, not stamped on afterwards: a run resumed after the corpus
        # was re-pinned would otherwise claim a membership half of its output never saw.
        'corpus_digest': corpus_digest,
        # Real wall-clock, unlike `today`, which is the pinned day lockrot is told to believe in.
        # Two runs only read the same upstream while they are inside one activity cache lifetime.
        'started': _now(),
        'finished': None,
        # What this invocation set out to cover, written before it covers any of it. A target only
        # reaches `targets` once the run reaches it, so without this a run killed partway through
        # is indistinguishable from a smaller corpus that finished.
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
    """Open this invocation: record what it means to cover and unsay any earlier completion.

    `finished` is cleared on every pass, resume included. It used to survive a resume, so a run that
    completed, had one output edited, and was then killed on the way back through still carried the
    first run's timestamp and read as whole.
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
    # Absolute, because every invocation runs with the project directory as its working directory:
    # a relative path here reaches php as a file that is not there, and the whole run writes empty
    # reports whose stderr nobody looks at until the checks report a corpus of nothing.
    phar = os.path.abspath(phar)
    token = token_from_environment(anon)
    os.makedirs(out_dir, exist_ok=True)
    manifest = load_manifest(out_dir) or new_manifest('report', phar, today, cache_root, anon,
                                                      corpus_digest)
    _assert_same_run(manifest, phar, today, cache_root, anon, corpus_digest)
    environment = run_environment(token, cache_root, today)
    # The interpreter is a parameter so the resume contract can be proved against a stub that fails
    # on purpose: a real archive cannot be asked to die halfway through a write.
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
            # lockrot's documented exit codes are the verdict levels; anything else is the process
            # failing rather than the tool answering.
            _record(manifest, project, 'failed', exit_code=finished.returncode,
                    seconds=time.time() - started)
            note('%s: lockrot exited %d; stderr kept in %s.err' % (project, finished.returncode, project))
            write_json_atomic(os.path.join(out_dir, 'run.json'), manifest)
            continue
        write_text_atomic(output, finished.stdout.decode('utf-8', 'replace'))
        # An exit code of 0-4 is lockrot answering, but an answer still has to be a report. A
        # project whose output will not parse is recorded as failed and the run carries on: one
        # unreadable file must not take the other 38 with it, and its stderr is already on disk.
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
    """Two renderings per target — the page and the document — and a target is done only when both are.

    The text is written before the JSON, so an interruption used to leave a fresh page beside a
    stale document: exactly the pairing the contradiction checks assume cannot happen, since their
    whole subject is the two disagreeing.
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
