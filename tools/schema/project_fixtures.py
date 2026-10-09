#!/usr/bin/env python3
"""Projects the reference model's report-2 fixtures onto the draft that one pull request writes.

The model writes every 0.14 field. A pull request of the 0.14 train writes only the fields up to its
own, so its fixtures drop the keys a later pull request adds and revert the values a later pull
request changes. This script takes the pull request id and applies every drop and revert of the
pull requests after it.

Usage:
    python3 tools/schema/project_fixtures.py --pr 4b --source <model directory> [--run-date YYYY-MM-DD]

`<model directory>` holds the model's `cases.json` and `docs/` (its `cases/` and `explain-2/`
documents). The script writes, relative to the repository root:
    tests/fixtures/flags/cases.json        every case, projected, each with an `inputs` block when the
                                           case carries the release data a hydrator needs
    tests/fixtures/flags/composite.json    the composite report, projected
    tests/fixtures/flags/provenance.json   the source, every dropped key and every reverted value
    tests/fixtures/schema/documents/cases/<base>.json   the base documents of make_negatives.py
    tests/fixtures/schema/documents/explain/B.json      the base document of make_negatives_other.py
"""
import argparse
import copy
import hashlib
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.normpath(os.path.join(HERE, '..', '..'))
FLAGS_OUT = os.path.join(REPO, 'tests', 'fixtures', 'flags')
DOCS_OUT = os.path.join(REPO, 'tests', 'fixtures', 'schema', 'documents')

# The pull requests of the 0.14 train that change report-2, in merge order.
TRAIN = ['4b', '4c', '4d', '4e', '4f', '5', '6a', '6b']

# Each change a later pull request makes, keyed by that pull request. A fixture of pull request P
# undoes every change of a pull request after P.
FINDING_KEYS = {
    '4d': ['activity'],
    '4e': ['age', 'libyears_at_least', 'depth', 'depth_unmeasured', 'named_in'],
    '4f': ['abandoned_ignored'],
    '6a': ['unchecked_accepted'],
}
SECURITY_KEYS = {'4f': ['records', 'feeds']}
RUN_KEYS = {'4f': ['advisory_lookup']}
ROOT_KEYS = {'4e': ['rot_depth'], '6a': ['packages_unchecked_accepted']}
ROOT_GATE_KEYS = {'6b': ['failing_by', 'not_applied', 'text']}
NOTE_CODES = {
    '4d': ['repository_activity_host_not_supported'],
    '4f': ['abandoned_markings_ignored', 'abandoned_not_audited_by_composer', 'abandoned_ignore_unreadable'],
    '5': ['baseline_target_differs', 'baseline_fix_model_differs', 'ignore_does_not_cover_advisories'],
    '6a': ['verdict_rules_changed'],
}
# The libyears reasons report-1 writes, by the reasons that replace them (pull request 4e).
LIBYEARS_013 = {
    'not_from_composer_repository': 'not_from_composer_repository',
    'unavailable': 'metadata_unavailable',
    'not_found': 'metadata_unavailable',
    'branch_snapshot': 'branch_snapshot',
    'shared_commit': 'no_stable_release_date',
    'undated': 'no_stable_release_date',
    'no_stable_release': 'no_stable_release_date',
    'undated_releases': 'no_stable_release_date',
}
LIBYEARS_013_KEYS = ['branch_snapshot', 'no_stable_release_date', 'not_from_composer_repository', 'metadata_unavailable']

# The base documents make_negatives.py mutates, by file stem.
NEGATIVE_BASES = ['composite', 'A', 'A-baselined-dagger', 'A-blocked-dagger', 'B-baselined-dagger', 'B-dev-dagger', 'C-baselined-dagger', 'D-partial-dagger',
                  'E-ignored-dagger', 'baseline-read-score0', 'composite-generate-baseline', 'ok-unchecked', 'partial-op-dagger', 'psr-log', 'rounded-dev-dagger',
                  'routing', 'scope-registries-dagger', 'stale-only', 'unknown']


class Projection:
    """What one projection dropped and reverted, for provenance.json."""

    def __init__(self, pr):
        if pr not in TRAIN:
            raise SystemExit('unknown pull request %r; one of %s' % (pr, ', '.join(TRAIN)))
        self.pr = pr
        self.dropped = set()
        self.reverted = {}

    def after(self, pr):
        """Whether pull request `pr` comes after the one projected to."""
        return TRAIN.index(pr) > TRAIN.index(self.pr)

    def later(self, table):
        return [k for pr, keys in table.items() if self.after(pr) for k in keys]

    def drop(self, obj, keys, where):
        for k in keys:
            if k in obj:
                del obj[k]
                self.dropped.add(where + '.' + k)

    def revert(self, what):
        self.reverted[what] = self.reverted.get(what, 0) + 1


def entry_words(signal):
    """S10's summary is one clause per `unchecked[]` entry, joined with `; `."""
    return signal['summary'].split('; ')


def project_s10(f, proj):
    """Moves the S10 entries a later pull request writes back to what this one writes."""
    s10 = next((s for s in f['signals'] if s['id'] == 'S10'), None)
    accepted = f.get('unchecked_accepted') or []
    skipped = list(f['checks_skipped'])
    entries = []
    if s10 is not None:
        words = entry_words(s10)
        entries = [(e, words[i] if i < len(words) else None) for i, e in enumerate(s10['data']['unchecked'])]
    if proj.after('6a'):
        # an accepted check is the S10 entry it stands for
        for a in accepted:
            entries.append(({'check': a['check'], 'reason': a['reason'], 'blocks': a['blocks']}, None))
            skipped = [c for c in skipped if not (c['reason'] == 'accepted_by_config' and c['check'] == a['check'])]
            proj.revert('checks_skipped accepted_by_config -> the S10 entry it accepted')
    kept = []
    for e, words in entries:
        if proj.after('4f') and e['check'] == 'advisories':
            proj.revert('S10 {check: advisories} -> dropped')
            continue
        if proj.after('4d') and e['reason'] == 'host_not_supported':
            skipped.append({'check': 'repository_activity', 'reason': 'no_repository', 'blocks': e['blocks']})
            proj.revert('S10 {repository_activity, host_not_supported} -> checks_skipped {repository_activity, no_repository}')
            continue
        if proj.after('4d') and e['reason'] == 'not_from_composer_repository':
            skipped.append({'check': e['check'], 'reason': 'not_from_composer_repository', 'blocks': e['blocks']})
            proj.revert('S10 {%s, not_from_composer_repository} -> checks_skipped' % e['check'])
            continue
        kept.append((e, words))
    if proj.after('4d'):
        for c in skipped:
            if c['reason'] == 'host_not_supported':
                c['reason'] = 'no_repository'
                proj.revert('checks_skipped {repository_activity, host_not_supported} -> no_repository')
        before = len(skipped)
        skipped = [c for c in skipped if c['check'] != 'repository_archived']
        if len(skipped) != before:
            proj.revert('checks_skipped repository_archived -> dropped')
        for c in skipped:
            if c['reason'] == 'repository_not_found':
                proj.revert('checks_skipped repository_not_found -> dropped')
        skipped = [c for c in skipped if c['reason'] != 'repository_not_found']
    order = ['repository_activity', 'release_metadata', 'release_branch', 'advisories']
    skipped.sort(key=lambda c: order.index(c['check']) if c['check'] in order else len(order))
    f['checks_skipped'] = skipped
    f['checks_missing'] = [e for e, _ in kept]
    signals = [s for s in f['signals'] if s['id'] != 'S10']
    if kept:
        blocks = []
        for e, _ in kept:
            blocks += [b for b in e['blocks'] if b not in blocks]
        words = [w for _, w in kept]
        s10 = copy.deepcopy(s10) if s10 is not None else {'id': 'S10', 'level': 'info', 'summary': '', 'data': {}}
        s10['data'] = {'unchecked': [e for e, _ in kept], 'blocks': blocks}
        if any(w is None for w in words):
            raise SystemExit('%s: an S10 entry has no summary clause: %r' % (f['package'], [e for e, w in kept if w is None]))
        s10['summary'] = '; '.join(words)
        signals.append(s10)
    f['signals'] = signals


def project_finding(f, proj, where='finding'):
    f = copy.deepcopy(f)
    project_s10(f, proj)
    proj.drop(f, proj.later(FINDING_KEYS), where)
    if 'security' in f:
        proj.drop(f['security'], proj.later(SECURITY_KEYS), where + '.security')
    if proj.after('4d'):
        for s in f['signals']:
            if s['id'] == 'S4' and 'event' in s['data']:
                d = dict(s['data'])
                event = d.pop('event')
                s['data'] = dict(d, activity=event)
                proj.revert('S4 data.event -> data.activity')
    if proj.after('4e') and f.get('libyears_unmeasured') is not None:
        old = LIBYEARS_013[f['libyears_unmeasured']]
        if old != f['libyears_unmeasured']:
            proj.revert('libyears_unmeasured %s -> %s' % (f['libyears_unmeasured'], old))
        f['libyears_unmeasured'] = old
    return f


def project_note(n, proj):
    """Null when a later pull request writes the note."""
    if n['code'] in proj.later(NOTE_CODES):
        proj.dropped.add('note_details[code=%s]' % n['code'])
        return None
    n = copy.deepcopy(n)
    if proj.after('4f') and n['code'] == 'not_from_composer_repository' and 'advisories_asked' in n['data']:
        del n['data']['advisories_asked']
        count = n['data']['package_count']
        n['text'] = ('1 package is not from a Composer repository and was not checked' if count == 1
                     else '%d packages are not from a Composer repository and were not checked' % count)
        proj.dropped.add('note_details[code=not_from_composer_repository].data.advisories_asked')
        proj.revert('note not_from_composer_repository text -> report-1 words')
    return n


def project_notes(container, proj):
    kept_notes, kept_details = [], []
    for text, n in zip(container.get('notes') or [], container.get('note_details') or []):
        projected = project_note(n, proj)
        if projected is not None:
            kept_details.append(projected)
            kept_notes.append(projected['text'] if projected['text'] != n['text'] else text)
    container['notes'] = kept_notes
    container['note_details'] = kept_details


def project_run(run, proj, where='run'):
    run = copy.deepcopy(run)
    proj.drop(run, proj.later(RUN_KEYS), where)
    return run


def project_libyears(lib, proj):
    if not proj.after('4e'):
        return lib
    lib = copy.deepcopy(lib)
    unmeasured = {k: 0 for k in LIBYEARS_013_KEYS}
    for k, v in lib['unmeasured'].items():
        unmeasured[LIBYEARS_013[k]] += v
    lib['unmeasured'] = unmeasured
    proj.drop(lib, ['at_least_count'], 'libyears')
    if lib.get('furthest_behind'):
        proj.drop(lib['furthest_behind'], ['at_least'], 'libyears.furthest_behind')
    return lib


def project_report(doc, proj):
    doc = copy.deepcopy(doc)
    doc['run'] = project_run(doc['run'], proj)
    proj.drop(doc, proj.later(ROOT_KEYS), '(root)')
    proj.drop(doc['gate'], proj.later(ROOT_GATE_KEYS), 'gate')
    doc['libyears'] = project_libyears(doc['libyears'], proj)
    project_notes(doc, proj)
    doc['findings'] = [project_finding(f, proj, 'findings[]') for f in doc['findings']]
    return doc


def project_details(det, proj):
    det = copy.deepcopy(det)
    proj.drop(det, ['activity'], 'details') if proj.after('4d') else None
    return det


def explain_activity(f):
    """explain-1's top-level `activity`, from the model's `activity` and `age.push` (pull request 4d drops it)."""
    act, push = f.get('activity'), (f.get('age') or {}).get('push')
    if not act or act['status'] != 'read':
        return None
    label = {'github': 'GitHub', 'gitlab': 'GitLab', 'bitbucket': 'Bitbucket'}.get(act['forge'], act['forge'])
    return {'forge': label, 'repository': act['repo'], 'archived': bool(act['archived']), 'pushed_at': (push or {}).get('at'),
            'fetched_at': act['fetched_at'], 'from_cache': act['cached_at'] is not None}


def project_explain(doc, proj):
    out = copy.deepcopy(doc)
    activity = explain_activity(doc['finding']) if proj.after('4d') else None
    out['finding'] = project_finding(doc['finding'], proj)
    out['run'] = project_run(doc['run'], proj)
    project_notes(out, proj)
    if proj.after('4d'):
        ordered = {}
        for k, v in out.items():
            ordered[k] = v
            if k == 'metadata':
                ordered['activity'] = activity
        out = ordered
        proj.revert("explain-2's top-level activity -> explain-1's shape")
    return out


def inputs_of(case):
    """What a hydrator feeds the Analyzer to rebuild the case: null when the case carries no branch rows."""
    f, det, run = case['finding'], case.get('details') or {}, case['run']
    rows = ((det.get('metadata') or {}).get('branches')) or []
    if not rows or f['metadata']['status'] != 'read':
        return None
    s9 = next((s for s in f['signals'] if s['id'] == 'S9'), None)
    advisories = (s9 or {}).get('data', {}).get('advisories', [])
    releases = {}

    def release(version, at, php):
        if version and version not in releases:
            releases[version] = {'version': version, 'time': at, 'php': php}
    for r in rows:
        release(r['highest'], r['highest_released'], r['php'])
        lowest = (r.get('fixes') or {}).get('lowest')
        release(lowest, r['highest_released'], r['php'])
    by_branch = {r['branch']: r for r in rows}
    for a in advisories:
        fx = a['fix']
        row = by_branch.get(fx['to_branch'])
        if fx['version'] and row is not None:
            release(fx['version'], row['highest_released'], row['php'])
    age = f.get('age') or {}
    installed_at = (age.get('installed') or {}).get('at')
    release(f['version'], installed_at, f['installed_php']['requires'])
    holders = {}
    root_require = {f['package']: '*'} if f['direct'] else {}
    for a in advisories:
        for h in a['fix']['held_by']:
            if h['source'] == 'root':
                root_require[f['package']] = h['constraint']
            else:
                holders[h['package']] = {'name': h['package'], 'version': h['version'], h['link']: {f['package']: h['constraint']}}
    act = f.get('activity') or {}
    push = age.get('push') or {}
    return {
        'root': {'name': run['root_package'], 'require': dict({'php': run['project_php']} if run['project_php'] else {}, **root_require)},
        'lock': [{'name': f['package'], 'version': f['version'], 'time': installed_at, 'php': f['installed_php']['requires'], 'dev': f['dev'],
                  'origin': f['origin']['kind']}] + [dict(v, dev=False) for v in holders.values()],
        'releases': sorted(releases.values(), key=lambda r: r['version']),
        'advisories': [{'id': a['id'], 'cve': a['cve'], 'title': a['title'], 'link': a['link'], 'reported_at': a['reported_at'],
                        'severity': a['severity_published'], 'affected_versions': a['affected_versions']} for a in advisories],
        'activity': None if act.get('status') != 'read' else {'host': act['host'], 'repo': act['repo'], 'pushed_at': push.get('at'), 'archived': act['archived']},
        'run': {'target_php': run['target_php'], 'project_php': run['project_php'], 'fail_on': run['fail_on'], 'include_dev': run['include_dev']},
    }


def dump(path, doc):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w') as fh:
        json.dump(doc, fh, indent=1, ensure_ascii=False)
        fh.write('\n')


def sha256(path):
    with open(path, 'rb') as fh:
        return hashlib.sha256(fh.read()).hexdigest()


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('--pr', required=True, help='the pull request id: ' + ', '.join(TRAIN))
    ap.add_argument('--source', required=True, help="the model directory with cases.json and docs/")
    ap.add_argument('--run-date', default=None, help='the date the model ran, for provenance.json')
    ap.add_argument('--suite', default=None, help="the model suite's result on that run, for provenance.json")
    args = ap.parse_args()
    proj = Projection(args.pr)
    source = os.path.abspath(args.source)
    cases_path = os.path.join(source, 'cases.json')
    with open(cases_path) as fh:
        model = json.load(fh)
    cases = []
    without_inputs = []
    for case in model['cases']:
        c = copy.deepcopy(case)
        c['run'] = project_run(case['run'], proj, 'cases[].run')
        c['finding'] = project_finding(case['finding'], proj, 'cases[].finding')
        if c.get('details') is not None:
            c['details'] = project_details(case['details'], proj)
        c['inputs'] = inputs_of(case)
        if c['inputs'] is None:
            without_inputs.append(case['id'])
        cases.append(c)
    dump(os.path.join(FLAGS_OUT, 'cases.json'), {'pr': args.pr, 'cases': cases})
    dump(os.path.join(FLAGS_OUT, 'composite.json'), project_report(model['composite'], proj))
    bases = []
    for stem in NEGATIVE_BASES:
        with open(os.path.join(source, 'docs', 'cases', stem + '.json')) as fh:
            dump(os.path.join(DOCS_OUT, 'cases', stem + '.json'), project_report(json.load(fh), proj))
        bases.append(stem)
    with open(os.path.join(source, 'docs', 'explain-2', 'B.json')) as fh:
        dump(os.path.join(DOCS_OUT, 'explain', 'B.json'), project_explain(json.load(fh), proj))
    dump(os.path.join(FLAGS_OUT, 'provenance.json'), {
        'pr': args.pr,
        'source': {'cases.json': sha256(cases_path), 'model_run_date': args.run_date},
        'suite': args.suite or model.get('validation'),
        'dropped': sorted(proj.dropped),
        'reverted': dict(sorted(proj.reverted.items())),
        'cases_without_inputs': without_inputs,
        'negative_bases': bases,
    })
    print('%d cases (%d without inputs), %d base documents, %d dropped keys, %d kinds of reverted values'
          % (len(cases), len(without_inputs), len(bases), len(proj.dropped), len(proj.reverted)))
    return 0


if __name__ == '__main__':
    sys.exit(main())
