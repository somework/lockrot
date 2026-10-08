#!/usr/bin/env python3
"""Negative fixtures for the -2 schemas other than report-2: each is a valid document with one planted defect.

Each fixture's expected error is its `EXPECT.json` entry, a substring of the error the validator must
report. A file whose name ends in `.strict.json` is read against the strict twin (the closed reading of
`x-known-values` and of every object that lists its properties).

Usage: python3 tools/schema/make_negatives_other.py explain-2
    reads  tests/fixtures/schema/documents/explain/B.json
    writes tests/fixtures/schema/negative/explain-2/<name>.json and EXPECT.json
"""
import copy
import glob
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.normpath(os.path.join(HERE, '..', '..'))
DOCS = os.path.join(REPO, 'tests', 'fixtures', 'schema', 'documents')
NEG = os.path.join(REPO, 'tests', 'fixtures', 'schema', 'negative')


def dump(path, doc):
    with open(path, 'w') as fh:
        json.dump(doc, fh, indent=1, ensure_ascii=False)
        fh.write('\n')


def write_negatives(doc_kind, items):
    d = os.path.join(NEG, doc_kind)
    os.makedirs(d, exist_ok=True)
    for old in glob.glob(os.path.join(d, '*.json')):
        os.remove(old)
    expect = {}
    for name, (doc, want) in items.items():
        dump(os.path.join(d, name + '.json'), doc)
        expect[name + '.json'] = want
    with open(os.path.join(d, 'EXPECT.json'), 'w') as fh:
        json.dump(expect, fh, indent=2, ensure_ascii=False, sort_keys=True)
        fh.write('\n')
    return len(items)


def mutate(doc, fn):
    d = copy.deepcopy(doc)
    fn(d)
    return d


def s9(doc):
    return next(sg for sg in doc['finding']['signals'] if sg['id'] == 'S9')


def explain_negatives():
    with open(os.path.join(DOCS, 'explain', 'B.json')) as fh:
        base = json.load(fh)

    def f(fn):
        return mutate(base, fn)
    items = {
        'verdict-cause-word': (f(lambda d: d['finding'].__setitem__('verdict', 'left-behind')), 'finding.verdict'),
        'lead-vulnerable': (f(lambda d: d['finding'].__setitem__('lead', 'vulnerable')), 'finding.lead'),
        'rank-null': (f(lambda d: d['finding'].__setitem__('rank', None)), 'finding.rank'),
        'score-band-missing': (f(lambda d: d['finding']['score'].pop('band')), 'finding.score'),
        'score-exact-quarter': (f(lambda d: d['finding']['score'].__setitem__('exact', 36.25)), 'finding.score'),
        'term-maintenance-with-the-security-shape': (f(lambda d: (d['finding']['score']['terms'][0].pop('divisor'),
                                                                  d['finding']['score']['terms'][0].update(advisory='PKSA-4wjj-gy1p-ft3r', severity='high', fix_kind='upgrade', multiplier=1))), 'finding.score'),
        'term-security-key-on-a-maintenance-term.strict': (f(lambda d: d['finding']['score']['terms'][0].update(advisory='PKSA-4wjj-gy1p-ft3r')), 'finding.score'),
        'next-step-renamed-do': (f(lambda d: d['finding'].__setitem__('do', d['finding'].pop('next_step'))), 'finding.next_step'),
        'commands-posix-string': (f(lambda d: d['finding']['next_step'].__setitem__('commands', ["composer require 'symfony/monolog-bridge:^5.4.52'"])), 'finding.next_step'),
        'composer-moves-boolean': (f(lambda d: d['finding']['next_step'].__setitem__('composer_moves_because', True)), 'finding.next_step'),
        'security-move-not-move-in': (f(lambda d: d['finding']['security'].__setitem__('move', d['finding']['security'].pop('move_in'))), 'finding.security'),
        'security-status-not-run-hyphen': (f(lambda d: d['finding']['security'].__setitem__('status', 'not-run')), 'finding.security'),
        'headline-unit-mismatch': (f(lambda d: d['finding']['flags'][1]['headline'].update(unit='years', value=10.9, source='release')), 'finding.flags'),
        'flag-weight-revision-2': (f(lambda d: (d['finding']['flags'][0].pop('signal_ids'), d['finding']['flags'][0].update(points=16))), 'finding.flags'),
        's9-fixed-by-revision-2': (f(lambda d: (s9(d)['data']['advisories'][0].pop('fix'), s9(d)['data']['advisories'][0].update(fixed_by='v8.1.6', fixed_on_branch=False))), 'finding.signals'),
        's9-empty-advisories': (f(lambda d: s9(d)['data'].__setitem__('advisories', [])), 'finding.signals'),
        'baseline-state-accepted': (f(lambda d: d['finding'].__setitem__('baseline', {'status': 'accepted', 'recorded': None, 'advisories_now': {'known': 0, 'new': 1, 're_rated': 0, 'fix_lost': 0}, 'worsened_by': []})), 'finding.baseline'),
        'gate-by-facts-empty': (f(lambda d: d['finding']['gate']['by'][0].__setitem__('facts', [])), 'finding.gate.by'),
        'allowlist-by-user': (f(lambda d: d['finding'].__setitem__('allowlist', {'by': 'user', 'pattern': 'x/y', 'version': None, 'reason': 'r', 'reason_id': None, 'reason_by': 'user', 'expires': None, 'flag_ids': None})), 'finding.allowlist'),
        'replacement-text-revision-3': (f(lambda d: d['finding']['next_step'].__setitem__('replacement', {'package': None, 'text': 'Symfony', 'url': None, 'named_by': 'repository', 'finding': None})), 'finding.next_step'),
        'run-missing-score-model': (f(lambda d: d['run'].pop('score_model')), 'run.score_model'),
        'explain-1-root-target-php-no-run': (f(lambda d: (d.pop('run'), d.update(target_php='8.4', thresholds={}))), 'run'),
        'branch-row-without-fixes': (f(lambda d: d['metadata']['branches'][0].pop('fixes')), 'metadata'),
        'branch-row-fixes-clears-revision-3': (f(lambda d: d['metadata']['branches'][0].__setitem__('fixes', {'clears': 1, 'of': 1, 'kind': 'raise-php', 'lowest': 'v8.0.12'})), 'metadata'),
        'legacy-missing': (f(lambda d: d.pop('legacy')), 'legacy'),
        'schema-number-1': (f(lambda d: d['lockrot'].__setitem__('schema', 1)), 'lockrot.schema'),
        'note-advisories-not-checked-without-reason': (f(lambda d: (d['note_details'].append({'code': 'advisories_not_checked', 'text': 't', 'docs_url': None, 'sets_network_failures': False, 'data': {'composer_repositories_checked': 0}}), d['notes'].append('t'))), 'note_details'),
        'fix-kind-held-revision-1.strict': (f(lambda d: d['finding']['security'].__setitem__('fix_kind', 'held')), 'finding.security'),
        'next-step-kind-none-revision-2.strict': (f(lambda d: d['finding']['next_step'].__setitem__('kind', 'none')), 'finding.next_step'),
        'next-step-kind-unknown-on-a-require-move.strict': (f(lambda d: d['finding']['next_step'].__setitem__('kind', 'zz-new-kind')), 'finding.next_step'),
        'score-text-long-revision-2.strict': (f(lambda d: d['finding']['score'].__setitem__('text_long', 'x')), 'finding.score'),
        'ignored-matched-the-value': (f(lambda d: (d['finding']['security']['ignored'].append(
            {'id': 'PKSA-ign0-0000-0000', 'cve': None, 'title': 't', 'severity': 'medium', 'by': 'audit.ignore', 'matched': 'PKSA-ign0-0000-0000', 'reason': None}),
            d['finding']['security'].__setitem__('ignored_count', 1))), 'finding.security'),
        'ignored-by-config-prefix.strict': (f(lambda d: (d['finding']['security']['ignored'].append(
            {'id': 'PKSA-ign0-0000-0000', 'cve': None, 'title': 't', 'severity': 'medium', 'by': 'config.audit.ignore', 'matched': 'id', 'reason': None}),
            d['finding']['security'].__setitem__('ignored_count', 1))), 'finding.security'),
        'points-from-missing': (f(lambda d: d['run']['score_model']['flags'][0].pop('points_from')), 'run.score_model'),
        'clears-kind-signal': (f(lambda d: d['finding']['next_step']['clears'][0].__setitem__('kind', 'signal')), 'finding.next_step'),
        'maintenance-judged-without-metadata': (f(lambda d: d['finding']['metadata'].__setitem__('status', 'not_from_composer_repository')), 'finding'),
        'gates-hold-none': (f(lambda d: d['run'].__setitem__('gates', [{'value': 'none', 'kind': 'grade', 'threshold': None}])), 'run.gates'),
        'schema-url-missing': (f(lambda d: d.pop('$schema')), '$schema'),
        'activity-missing': (f(lambda d: d.pop('activity')), 'activity'),
        'branch-row-fixes-without-if-applied': (f(lambda d: d['metadata']['branches'][0]['fixes'].pop('if_applied')), 'metadata.branches[0].fixes'),
        'branch-row-fixing-nothing-held': (f(lambda d: d['metadata']['branches'][-1]['fixes'].update(
            held_by=[dict(source='root', package=None, version=None, link='require', constraint='^2.6', holder=None)])),
            'metadata.branches[%d].fixes' % (len(base['metadata']['branches']) - 1)),
    }
    return write_negatives('explain-2', items)


GENERATORS = {'explain-2': explain_negatives}


def main(argv):
    if len(argv) != 2 or argv[1] not in GENERATORS:
        sys.stderr.write('usage: make_negatives_other.py %s\n' % '|'.join(sorted(GENERATORS)))
        return 2
    n = GENERATORS[argv[1]]()
    print('%d negative fixtures for %s' % (n, argv[1]))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
