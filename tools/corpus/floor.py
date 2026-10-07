#!/usr/bin/env python3
"""Writes tests/fixtures/corpus/report-1-floor.json.gz from lockrot.dev's watch reports.

Usage: python3 tools/corpus/floor.py <lockrot.dev checkout> <commit> <out.json.gz>

The tool reads `data/reports/watch/*.json` at <commit> with `git show`, so the checkout's working
tree stays as it is. Every report must be report-1. Each finding keeps the facts the 0.14 engine
reads and what 0.13 recorded: its signals without their summaries, its verdict and its priority.
The gzip header holds no file name and no time, so a rebuild from the same commit with the same
zlib gives the same bytes. Beside the output, <out>.provenance.json names the source, the counts and
the sha256 of the output. Standard library only.
"""
import gzip
import hashlib
import io
import json
import subprocess
import sys

REPOSITORY = 'https://github.com/somework/lockrot.dev'
WATCH = 'data/reports/watch/'
REPORT_1 = 'https://lockrot.dev/schema/report-1.json'
FINDING_KEYS = ('package', 'version', 'direct', 'dev', 'chain', 'verdict', 'priority')


def git(checkout, *args):
    return subprocess.run(['git', '-C', checkout] + list(args), check=True, capture_output=True).stdout


def report_names(checkout, commit):
    paths = git(checkout, 'ls-tree', '--name-only', commit, WATCH).decode('utf-8').split('\n')
    return sorted(path for path in paths if path.endswith('.json') and not path.endswith('/manifest.json'))


def reduce_report(name, page):
    report = page['data']['report']
    if report.get('$schema') != REPORT_1:
        raise SystemExit('%s is not a report-1 document' % name)
    findings = []
    for finding in report['findings']:
        reduced = {key: finding[key] for key in FINDING_KEYS}
        reduced['signals'] = [{'id': s['id'], 'level': s['level'], 'data': s['data']} for s in finding['signals']]
        reduced['allowlist_reason'] = finding['allowlist_reason']
        findings.append(reduced)
    return {
        'name': name,
        'lockrot': report['lockrot']['version'],
        'generated_at': report['generated_at'],
        'target_php': report['run']['target_php'],
        'findings': findings,
    }


def provenance_path(out):
    return (out[:-len('.json.gz')] if out.endswith('.json.gz') else out) + '.provenance.json'


def main(argv):
    if len(argv) != 3:
        raise SystemExit(__doc__.split('\n\n')[1])
    checkout, commit, out = argv
    commit = git(checkout, 'rev-parse', '--verify', commit + '^{commit}').decode('ascii').strip()
    paths = report_names(checkout, commit)
    if not paths:
        raise SystemExit('no watch report under %s at %s' % (WATCH, commit))
    reports = []
    for path in paths:
        name = path[len(WATCH):-len('.json')]
        reports.append(reduce_report(name, json.loads(git(checkout, 'show', commit + ':' + path))))
    floor = {
        'generated_from': {'repository': REPOSITORY, 'commit': commit, 'path': WATCH, 'tool': 'tools/corpus/floor.py'},
        'reports': reports,
    }
    body = json.dumps(floor, ensure_ascii=False, separators=(',', ':')).encode('utf-8') + b'\n'
    buffer = io.BytesIO()
    with gzip.GzipFile(filename='', mode='wb', fileobj=buffer, mtime=0, compresslevel=9) as handle:
        handle.write(body)
    data = buffer.getvalue()
    with open(out, 'wb') as handle:
        handle.write(data)
    provenance = dict(floor['generated_from'])
    provenance.update({
        'reports': len(reports),
        'findings': sum(len(r['findings']) for r in reports),
        'sha256': hashlib.sha256(data).hexdigest(),
    })
    with open(provenance_path(out), 'w', encoding='utf-8') as handle:
        handle.write(json.dumps(provenance, indent=4) + '\n')
    print('%d reports, %d findings' % (provenance['reports'], provenance['findings']))



if __name__ == '__main__':
    main(sys.argv[1:])
