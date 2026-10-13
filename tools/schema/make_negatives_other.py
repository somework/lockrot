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
        'finding-activity-missing': (f(lambda d: d['finding'].pop('activity')), 'finding.activity'),
        'explain-1-activity-at-the-top.strict': (f(lambda d: d.__setitem__('activity', {'forge': 'GitHub', 'repository': 'symfony/monolog-bridge', 'archived': False,
                                                                                       'pushed_at': None, 'fetched_at': None, 'from_cache': False})), 'activity'),
        'run-without-advisory-lookup': (f(lambda d: d['run'].pop('advisory_lookup')), 'run.advisory_lookup'),
        'finding-without-age': (f(lambda d: d['finding'].pop('age')), 'finding.age'),
        'finding-without-unchecked-accepted': (f(lambda d: d['finding'].pop('unchecked_accepted')), 'finding.unchecked_accepted'),
        'branch-row-fixes-without-if-applied': (f(lambda d: d['metadata']['branches'][0]['fixes'].pop('if_applied')), 'metadata.branches[0].fixes'),
        'branch-row-blocked-with-if-applied': (f(lambda d: d['metadata']['branches'][0]['fixes'].update(fix_kind='blocked')), 'metadata.branches[0].fixes'),
        'branch-row-fixing-nothing-held': (f(lambda d: d['metadata']['branches'][-1]['fixes'].update(
            held_by=[dict(source='root', package=None, version=None, link='require', constraint='^2.6', holder=None)])),
            'metadata.branches[%d].fixes' % (len(base['metadata']['branches']) - 1)),
    }
    return write_negatives('explain-2', items)


class _Delete:
    pass


DELETE = _Delete()


def baseline_negatives():
    base = json.load(open(os.path.join(DOCS, 'baseline', 'corpus', 'matomo-v4.json')))
    pkg = 'symfony/monolog-bridge'
    adv = next(iter(base['findings'][pkg]['advisories']))

    def setp(path, value):
        def fn(d):
            cur = d
            for k in path[:-1]:
                cur = cur[k]
            if value is DELETE:
                del cur[path[-1]]
            else:
                cur[path[-1]] = value
        return fn
    E = ['findings', pkg]
    items = {
        'schema-number-1': (mutate(base, setp(['lockrot', 'schema'], 1)), 'lockrot.schema'),
        'schema-url-baseline-1': (mutate(base, setp(['$schema'], 'https://lockrot.dev/schema/baseline-1.json')), '$schema'),
        'target-php-missing': (mutate(base, setp(['target_php'], DELETE)), 'target_php'),
        'target-php-object-revision-3': (mutate(base, setp(['target_php'], {'version': '8.4', 'source': 'option'})), 'target_php'),
        'target-php-source-unknown': (mutate(base, setp(['target_php_source'], 'cli')), 'target_php_source'),
        'score-model-old-name': (mutate(base, lambda d: (d.pop('score_model_id'), d.__setitem__('score_model', 1))), 'score_model_id'),
        'fix-model-zero': (mutate(base, setp(['fix_model'], 0)), 'fix_model'),
        'findings-array': (mutate(base, setp(['findings'], [])), 'findings'),
        'flag-vulnerable-stored': (mutate(base, setp(E + ['flags', 'vulnerable'], {'known_since': '2026-10-01'})), 'flags'),
        'flag-unknown-id': (mutate(base, setp(E + ['flags', 'outdated'], {'known_since': '2026-10-01'})), 'flags'),
        'flag-known-since-not-a-date': (mutate(base, setp(E + ['flags', 'left-behind', 'known_since'], '2026/10/01')), 'known_since'),
        'flag-known-since-null': (mutate(base, setp(E + ['flags', 'left-behind', 'known_since'], None)), 'known_since'),
        'advisory-severity-moderate': (mutate(base, setp(E + ['advisories', adv, 'severity'], 'moderate')), 'severity'),
        'advisory-fix-old-name': (mutate(base, lambda d: d['findings'][pkg]['advisories'][adv].__setitem__('fix', d['findings'][pkg]['advisories'][adv].pop('fix_kind'))), 'fix_kind'),
        'advisory-fix-kind-not-a-token': (mutate(base, setp(E + ['advisories', adv, 'fix_kind'], 'raise PHP')), 'fix_kind'),
        'entry-first-seen-missing': (mutate(base, setp(E + ['first_seen'], DELETE)), 'first_seen'),
        'entry-verdict-only-baseline-1-shape': (mutate(base, setp(E, {'version': 'v2.6.13', 'verdict': 'left-behind', 'first_seen': '2026-09-14'})), 'flags'),
        'generated-at-not-a-datetime': (mutate(base, setp(['generated_at'], 'yesterday')), 'generated_at'),
        'schema-url-missing': (mutate(base, setp(['$schema'], DELETE)), '$schema'),
        'findings-key-not-a-package-name': (mutate(base, setp(['findings', 'symfony'], base['findings'][pkg])), 'findings'),
        # the writer never writes an entry with no fact
        'entry-stores-no-fact': (mutate(base, setp(E, {'version': 'v2.6.13', 'first_seen': '2026-09-14', 'flags': {}, 'advisories': {}})), 'findings.symfony/monolog-bridge'),
        # r3 attack round 1: an empty map is never written (the key is left out), so `{}` beside a non-empty map is rejected too
        'entry-advisories-empty-map': (mutate(base, setp(E + ['advisories'], {})), 'advisories'),
        'entry-no-fact-key': (mutate(base, setp(E, {'version': 'v2.6.13', 'first_seen': '2026-09-14'})), 'findings.symfony/monolog-bridge'),
    }
    items['flag-headline-stored.strict'] = (mutate(base, setp(E + ['flags', 'left-behind', 'headline'], {'unit': 'years', 'value': 6.9, 'source': 'branch'})), 'headline')
    return write_negatives('baseline-2', items)


class _Delete:
    pass


DELETE = _Delete()


def config_negatives():
    items = {
        'fail-on-vulnerable-low': ({'fail-on': 'vulnerable-low'}, 'fail-on'),
        'fail-on-unknown-value': ({'fail-on': 'warn'}, 'fail-on'),
        'fail-on-empty-string': ({'fail-on': ''}, 'fail-on'),
        'fail-on-empty-array': ({'fail-on': []}, 'fail-on'),
        'fail-on-list-inside-an-array-item': ({'fail-on': ['left-behind,high']}, 'fail-on'),
        'fail-on-semicolon': ({'fail-on': 'high;medium'}, 'fail-on'),
        'fail-on-trailing-comma': ({'fail-on': 'high,'}, 'fail-on'),
        'fail-on-number': ({'fail-on': 1}, 'fail-on'),
        'fail-on-array-duplicate': ({'fail-on': ['high', 'high']}, 'fail-on'),
        'fail-on-security-prefix-spec-md': ({'fail-on': 'security-high'}, 'fail-on'),
        'ignore-flags-vulnerable': ({'ignore': [{'package': 'acme/fork', 'flags': ['vulnerable'], 'reason': 'internal fork'}]}, 'ignore[0].flags[0]'),
        'ignore-flags-unknown': ({'ignore': [{'package': 'acme/fork', 'flags': ['outdated'], 'reason': 'x'}]}, 'ignore[0].flags[0]'),
        'ignore-flags-empty': ({'ignore': [{'package': 'acme/fork', 'flags': [], 'reason': 'x'}]}, 'ignore[0].flags'),
        'ignore-flags-string': ({'ignore': [{'package': 'acme/fork', 'flags': 'stale', 'reason': 'x'}]}, 'ignore[0].flags'),
        'ignore-flags-duplicate': ({'ignore': [{'package': 'acme/fork', 'flags': ['stale', 'stale'], 'reason': 'x'}]}, 'ignore[0].flags'),
        'ignore-flags-upper-case': ({'ignore': [{'package': 'acme/fork', 'flags': ['Stale'], 'reason': 'x'}]}, 'ignore[0].flags[0]'),
        'ignore-missing-reason': ({'ignore': [{'package': 'acme/fork', 'flags': ['stale']}]}, 'ignore[0].reason'),
        'ignore-expires-not-a-date': ({'ignore': [{'package': 'acme/fork', 'reason': 'x', 'expires': '31.03.2027'}]}, 'ignore[0].expires'),
        'format-unknown.strict': ({'format': 'checkstyle'}, 'format'),
        # accept-unchecked
        'accept-unchecked-object': ({'accept-unchecked': {'check': 'advisories', 'package': 'acme/*', 'reason': 'x'}}, 'accept-unchecked'),
        'accept-unchecked-no-scope': ({'accept-unchecked': [{'check': 'advisories', 'reason': 'x'}]}, 'accept-unchecked[0]'),
        'accept-unchecked-two-scopes': ({'accept-unchecked': [{'check': 'repository_activity', 'host': 'codeberg.org', 'package': 'acme/*', 'reason': 'x'}]},
                                        'accept-unchecked[0]'),
        'accept-unchecked-three-scopes': ({'accept-unchecked': [{'check': 'repository_activity', 'host': 'codeberg.org', 'repository': 'https://codeberg.org/acme/x',
                                                                 'package': 'acme/x', 'reason': 'x'}]}, 'accept-unchecked[0]'),
        'accept-unchecked-missing-reason': ({'accept-unchecked': [{'check': 'advisories', 'package': 'acme/*'}]}, 'accept-unchecked[0].reason'),
        'accept-unchecked-empty-reason': ({'accept-unchecked': [{'check': 'advisories', 'package': 'acme/*', 'reason': ''}]}, 'accept-unchecked[0].reason'),
        'accept-unchecked-missing-check': ({'accept-unchecked': [{'host': 'codeberg.org', 'reason': 'x'}]}, 'accept-unchecked[0].check'),
        'accept-unchecked-check-releases': ({'accept-unchecked': [{'check': 'releases', 'package': 'acme/*', 'reason': 'x'}]}, 'accept-unchecked[0].check'),
        'accept-unchecked-check-a-reason': ({'accept-unchecked': [{'check': 'host_not_supported', 'host': 'codeberg.org', 'reason': 'x'}]}, 'accept-unchecked[0].check'),
        'accept-unchecked-check-a-signal': ({'accept-unchecked': [{'check': 'S10', 'package': 'acme/*', 'reason': 'x'}]}, 'accept-unchecked[0].check'),
        'accept-unchecked-host-with-scheme': ({'accept-unchecked': [{'check': 'repository_activity', 'host': 'https://codeberg.org', 'reason': 'x'}]}, 'accept-unchecked[0].host'),
        'accept-unchecked-host-upper-case': ({'accept-unchecked': [{'check': 'repository_activity', 'host': 'Codeberg.org', 'reason': 'x'}]}, 'accept-unchecked[0].host'),
        'accept-unchecked-host-with-path': ({'accept-unchecked': [{'check': 'repository_activity', 'host': 'codeberg.org/acme', 'reason': 'x'}]}, 'accept-unchecked[0].host'),
        'accept-unchecked-repository-not-a-url': ({'accept-unchecked': [{'check': 'release_metadata', 'repository': 'acme/fork', 'reason': 'x'}]}, 'accept-unchecked[0].repository'),
        # no userinfo in a scheme URL, and the advisories check is never scoped by host
        'accept-unchecked-repository-userinfo': ({'accept-unchecked': [{'check': 'advisories', 'repository': 'https://deploy:s3cr3t@mirror.acme.internal', 'reason': 'x'}]},
                                                 'accept-unchecked[0].repository'),
        'accept-unchecked-advisories-by-host': ({'accept-unchecked': [{'check': 'advisories', 'host': 'mirror.acme.internal', 'reason': 'x'}]}, 'accept-unchecked[0]'),
        'accept-unchecked-package-empty': ({'accept-unchecked': [{'check': 'release_metadata', 'package': '', 'reason': 'x'}]}, 'accept-unchecked[0].package'),
        'accept-unchecked-expires-not-a-date': ({'accept-unchecked': [{'check': 'advisories', 'package': 'acme/*', 'reason': 'x', 'expires': '31.03.2027'}]},
                                                'accept-unchecked[0].expires'),
    }
    return write_negatives('config-2', items)


GENERATORS = {'explain-2': explain_negatives, 'baseline-2': baseline_negatives, 'config-2': config_negatives}


def main(argv):
    if len(argv) != 2 or argv[1] not in GENERATORS:
        sys.stderr.write('usage: make_negatives_other.py %s\n' % '|'.join(sorted(GENERATORS)))
        return 2
    n = GENERATORS[argv[1]]()
    print('%d negative fixtures for %s' % (n, argv[1]))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
