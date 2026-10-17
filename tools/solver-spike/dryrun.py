#!/usr/bin/env python3
"""Spike: run `composer ... --dry-run` for each move that verify.php solved, and compare.

Usage: python3 -I tools/solver-spike/dryrun.py <project dir> <solver.json> <out.json> <forms> [composer] [extra flags]
<forms> is a comma list of as-is, inline, inline-w, inline-W, update-now.
The runs touch the network, so they run one at a time, each in a fresh copy of the two files.
"""
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time

OP = re.compile(r'^\s+- (Upgrading|Downgrading|Locking|Removing|Installing) (\S+) \((.+?)\)$')


def command(move, form):
    name, constraint = move['package'], move['constraint']
    if move['kind'] == 'require':
        cmd = ['require', f'{name}:{constraint}']
    elif form == 'as-is':
        cmd = ['update', name]
    else:
        cmd = ['update', f'{name}:{constraint}']
    if form == 'inline-w':
        cmd.append('-w')
    if form == 'inline-W':
        cmd.append('-W')
    return cmd


def parse(output):
    changes, in_lock = {}, False
    for line in output.splitlines():
        if line.startswith('Lock file operations:'):
            in_lock = True
            continue
        if in_lock and not line.startswith('  '):
            in_lock = False
        m = OP.match(line) if in_lock else None
        if not m:
            continue
        verb, name, versions = m.groups()
        if verb in ('Upgrading', 'Downgrading'):
            a, b = versions.split(' => ')
            changes[name] = [a.split(' ')[0], b.split(' ')[0]]
        elif verb in ('Locking', 'Installing'):
            changes[name] = [None, versions.split(' ')[0]]
        else:
            changes[name] = [versions.split(' ')[0], None]
    return changes


def inside(work, relative):
    """Returns the path of `relative` under `work`, and refuses a path that leaves it: a lock is third-party data."""
    base = os.path.realpath(work)
    target = os.path.realpath(os.path.join(base, relative))
    if os.path.commonpath([target, base]) != base:
        raise ValueError(f'path repository leaves the work directory: {relative}')
    return target


def stub_paths(work):
    """Recreates each path package from its lock entry, and the parent of each glob path repository.

    A fixture holds only the two files, and Composer refuses a path repository that does not exist.
    """
    lock = json.load(open(f'{work}/composer.lock'))
    for package in lock['packages'] + lock.get('packages-dev', []):
        dist = package.get('dist') or {}
        if dist.get('type') == 'path':
            target = inside(work, dist['url'])
            os.makedirs(target, exist_ok=True)
            entry = {k: v for k, v in package.items() if k not in ('dist', 'source', 'transport-options', 'notification-url', 'installation-source')}
            json.dump(entry, open(os.path.join(target, 'composer.json'), 'w'))
    repositories = json.load(open(f'{work}/composer.json')).get('repositories') or []
    for repo in repositories if isinstance(repositories, list) else repositories.values():
        url = repo.get('url', '') if isinstance(repo, dict) and repo.get('type') == 'path' else ''
        while any(c in url for c in '*{}'):
            url = os.path.dirname(url)
        if url:
            os.makedirs(inside(work, url), exist_ok=True)


def run(project, args, composer, extra):
    with tempfile.TemporaryDirectory() as work:
        for f in ('composer.json', 'composer.lock'):
            shutil.copy(f'{project}/{f}', f'{work}/{f}')
        stub_paths(work)
        cmd = [*composer, *args, '--dry-run', '--no-plugins', '--no-scripts', '--no-interaction', '--no-ansi', *extra]
        start = time.time()
        # GITHUB_TOKEN reaches Composer through the process environment only, never through a file.
        env = dict(os.environ)
        if env.get('GITHUB_TOKEN'):
            env['COMPOSER_AUTH'] = json.dumps({'github-oauth': {'github.com': env['GITHUB_TOKEN']}})
        p = subprocess.run(cmd, cwd=work, capture_output=True, text=True, timeout=900, env=env)
        out = p.stdout + p.stderr
        ok = p.returncode == 0 and 'could not be resolved' not in out
        problem = None
        if not ok:
            i = out.find('Problem 1')
            problem = out[i:i + 4000] if i >= 0 else out[-2000:]
        return {'cmd': ' '.join(args), 'exit': p.returncode, 'ok': ok, 'changes': parse(out) if ok else {},
                'problem': problem, 'seconds': round(time.time() - start, 2)}


def main():
    project, solver_path, out_path, forms = sys.argv[1:5]
    composer = (sys.argv[5] if len(sys.argv) > 5 else 'composer').split(' ')
    extra = sys.argv[6].split(' ') if len(sys.argv) > 6 and sys.argv[6] else []
    forms = forms.split(',')
    solver = json.load(open(solver_path))
    results = []
    for move in solver['moves']:
        for form in forms:
            if form == 'update-now':
                continue
            r = run(project, command(move, form), composer, extra)
            r.update(package=move['package'], form=form)
            results.append(r)
            print(move['package'], form, r['ok'], r['changes'].get(move['package']), r['seconds'], flush=True)
    if 'update-now' in forms and solver.get('update_now'):
        members = solver['update_now']['members']
        inline = {m['package']: m['constraint'] for m in solver['moves'] if m['package'] in members}
        for form, args in (('as-is', members), ('inline', [f'{n}:{inline[n]}' if n in inline else n for n in members])):
            r = run(project, ['update', *args], composer, extra)
            r.update(package='update_now', form=form)
            results.append(r)
            print('update_now', form, r['ok'], r['seconds'], flush=True)
    json.dump(results, open(out_path, 'w'), indent=1)


main()
