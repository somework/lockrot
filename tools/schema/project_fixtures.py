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
    tests/fixtures/flags/coverage/<variant>.json        the coverage variants with a failed feed, from PR 4f on
    tests/fixtures/schema/documents/cases/<base>.json   the base documents of make_negatives.py
    tests/fixtures/schema/documents/explain/B.json      the base document of make_negatives_other.py
From pull request 7a on it also writes every model document, without the keys of a later pull request:
    tests/fixtures/schema/documents/{cases,variants,explain,explain/coverage,baseline/*,config/*,page}/   plain JSON
    tests/fixtures/schema/documents/{corpus,coverage/baselined,coverage/variants}/   gzipped (`.json.gz`)
    tests/fixtures/schema/documents/provenance.json      the source, its run date and suite, the count of each set
    tests/fixtures/contract/page-goldens.json            the page goldens, byte for byte: the model's, or those of --page-goldens
"""
import argparse
import copy
import glob
import gzip
import hashlib
import json
import os
import re
import shutil
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.normpath(os.path.join(HERE, '..', '..'))
FLAGS_OUT = os.path.join(REPO, 'tests', 'fixtures', 'flags')
DOCS_OUT = os.path.join(REPO, 'tests', 'fixtures', 'schema', 'documents')
PAGE_GOLDENS_OUT = os.path.join(REPO, 'tests', 'fixtures', 'contract', 'page-goldens.json')

# The pull requests of the 0.14 train that change report-2, in merge order.
TRAIN = ['4b', '4c', '4d', '4e', '4f', '5', '6a', '6b', '7a', '7b', '7c', '7d']

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
PHP_WINDOW_KEYS = {'7c': ['target_fix']}
ROOT_KEYS = {'4e': ['rot_depth'], '6a': ['packages_unchecked_accepted']}
ROOT_GATE_KEYS = {'6b': ['failing_by', 'not_applied', 'text']}
NOTE_CODES = {
    '4d': ['repository_activity_host_not_supported'],
    '4f': ['abandoned_markings_ignored', 'abandoned_not_audited_by_composer', 'abandoned_ignore_unreadable'],
    '5': ['baseline_target_differs', 'baseline_fix_model_differs', 'ignore_does_not_cover_advisories'],
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

# The coverage variants with a failed feed, projected to this pull request's keys (pull request 6b
# replaces them with the final documents).
COVERAGE_VARIANTS = ['lookup-not-run-lookup-failed', 'feed-invalid-response', 'clear-partial-lookup', 'vulnerable-partial-lookup']

# The base documents make_negatives.py mutates, by file stem.
NEGATIVE_BASES = ['composite', 'A', 'A-baselined-dagger', 'A-blocked-dagger', 'B-baselined-dagger', 'B-dev-dagger', 'C-baselined-dagger', 'D-partial-dagger',
                  'E-ignored-dagger', 'baseline-read-score0', 'composite-generate-baseline', 'ok-unchecked', 'partial-op-dagger', 'psr-log', 'rounded-dev-dagger',
                  'routing', 'scope-registries-dagger', 'stale-only', 'unknown', 'host-unsupported', 'not-found-dagger', 'gitlab-anon-dagger', 'local-path-advisory',
                  'ignored-marking-archived-dagger', 'ignored-marking-stale-dagger', 'no-feed-dagger',
                  'B-gate-none', 'host-accepted-dagger']


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


def s10_words(entry, f):
    """S10's clause for one entry, in NotCheckedRule's words. None for a check that NotCheckedRule does not word."""
    if entry['reason'] == 'host_not_supported':
        return 'repository activity not checked (lockrot cannot ask %s), so S3 and S4 could not be read' % f['activity']['host']
    if entry['reason'] == 'not_from_composer_repository' and entry['check'] in ('release_metadata', 'repository_activity'):
        cause = 'lockrot cannot tell which repository serves it' if f['origin']['kind'] == 'unknown' else 'not installed from a Composer repository'
        what, blocks = {'release_metadata': ('release metadata', 'S2 and S8'), 'repository_activity': ('repository activity', 'S3 and S4')}[entry['check']]
        return '%s not checked (%s), so %s could not be read' % (what, cause, blocks)
    if entry['check'] == 'advisories' and entry['reason'] in ADVISORY_CAUSES:
        return 'security advisories not checked (%s), so S9 could not be read' % ADVISORY_CAUSES[entry['reason']]
    return None


# NotCheckedRule's cause words for an advisories entry.
ADVISORY_CAUSES = {
    'offline': 'the run is offline',
    'composer_too_old': 'Composer is older than 2.4',
    'install_time_budget': 'the install-time budget ran out',
    'lookup_failed': 'an advisory repository failed',
    'no_feed': 'no configured repository publishes advisories',
}

# NotCheckedRule writes the origin and activity entries before the others.
S10_FIRST = ['release_metadata', 'repository_activity']


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
            entry = {'check': a['check'], 'reason': a['reason'], 'blocks': a['blocks']}
            entries.append((entry, s10_words(entry, f)))
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
    kept.sort(key=lambda pair: S10_FIRST.index(pair[0]['check']) if pair[0]['check'] in S10_FIRST else len(S10_FIRST))
    f['checks_skipped'] = skipped
    f['checks_missing'] = [e for e, _ in kept]
    signals = [s for s in f['signals'] if s['id'] != 'S10']
    if kept:
        blocks = sorted({b for e, _ in kept for b in e['blocks']}, key=lambda b: int(b[1:]))
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
    if isinstance(run.get('php_window'), dict):
        proj.drop(run['php_window'], proj.later(PHP_WINDOW_KEYS), where + '.php_window')
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


def package_facts(f, rows):
    """The facts of one package that the hydrator turns into its lock entry, its releases, its advisories and its
    repository's answer, from its finding and its branch rows. `releases` is absent for a package that lockrot reads no
    release of."""
    s9 = next((s for s in f['signals'] if s['id'] == 'S9'), None)
    advisories = (s9 or {}).get('data', {}).get('advisories', [])
    releases = {}

    def release(version, at, php, shared_commit=False):
        if version and version not in releases:
            releases[version] = dict({'version': version, 'time': at, 'php': php}, **({'shared_commit': True} if shared_commit else {}))
        elif version and releases[version]['time'] is None:
            # A version that a fix names before the date is known (the installed one) keeps that date.
            releases[version]['time'] = at
    for r in rows:
        # A highest tag on a commit that other tags share is dated by that commit only (lockrot's shared-commit rule).
        shared = r['highest_released'] is None and r['highest_commit_date'] is not None
        release(r['highest'], r['highest_commit_date'] if shared else r['highest_released'], r.get('highest_php', r['php']), shared)
        # A backport on a lower minor can be the branch's newest dated release: it carries the row's php.
        if r['newest_dated'] != r['highest']:
            release(r['newest_dated'], r['newest_dated_released'], r['php'])
        # The model dates a branch by its newest release only, so a lower release stays undated.
        lowest = (r.get('fixes') or {}).get('lowest')
        release(lowest, None, r['php'])
    by_branch = {r['branch']: r for r in rows}
    for a in advisories:
        fx = a['fix']
        row = by_branch.get(fx['to_branch'])
        if fx['version'] and row is not None:
            release(fx['version'], None, row['php'])
    # A move whose release the model does not know (version null) admits its constraint's lowest release, which the model
    # cannot date: an undated release there leaves what the move clears unverified, as the model leaves it.
    # shortcut: assumes an undated `X.Y.0` release, which a real branch can lack or date; upgrade when the model holds each branch's releases.
    ns = f.get('next_step') or {}
    for mv in [ns] + (ns.get('also') or []):
        row = by_branch.get(mv.get('to_branch'))
        lower = re.match(r'^\^(\d+\.\d+)$', mv.get('constraint') or '')
        if mv.get('version') is None and row is not None and lower:
            release(('v' if (row['highest'] or '').startswith('v') else '') + lower.group(1) + '.0', None, row['php'])
    age = f.get('age') or {}
    s6 = next((sg['data'] for sg in f['signals'] if sg['id'] == 'S6'), None) or {}
    # A snapshot has no release date: its lock time is the snapshot's commit time.
    installed_at = (age.get('installed') or {}).get('at') or s6.get('snapshot_time')
    release(f['version'], installed_at, f['installed_php']['requires'])
    # The newest release that S2 measures can be a pre-release, which no branch row names.
    newest = age.get('release') or {}
    release(newest.get('version'), newest.get('at'), None)
    s1 = next((sg['data'] for sg in f['signals'] if sg['id'] == 'S1'), None)
    marking = None if s1 is None else {'by': s1['marked_by'], 'replacement': s1['replacement']}
    if marking is None and f.get('abandoned_ignored'):
        # S1 does not fire on a marking that Composer's ignore list covers: the marking is in abandoned_ignored.
        held = f['abandoned_ignored']['marking']
        marking = {'by': held['marked_by'], 'replacement': held['replacement']}
    # A split package's tag shares its commit with its other spellings, so lockrot dates it by the monorepo's tag of the
    # same version (`dated_by`). The split tag keeps its own time: S5 reads it.
    parent = None
    for part, version in (('installed', f['version']), ('branch_release', (age.get('branch_release') or {}).get('version'))):
        dated = age.get(part) or {}
        if dated.get('dated_by') and version in releases:
            parent = parent or {'name': dated['dated_by'], 'releases': []}
            parent['releases'].append({'version': version, 'time': dated['at']})
            releases[version]['shared_commit'] = True
    s5 = next((sg['data'] for sg in f['signals'] if sg['id'] == 'S5'), None) or {}
    if age.get('installed_unmeasured') == 'shared_commit' and f['version'] in releases:
        # The installed tag shares its commit, and no parent dates it: lockrot sets the lock's date aside as a commit date.
        # The model holds that commit's date only for a branch's highest tag, so a lower tag stays undated.
        row = next((r for r in rows if r['installed'] and r['highest'] == f['version']), {})
        own = releases[f['version']]['time'] or s5.get('released') or row.get('highest_commit_date')
        releases[f['version']].update(shared_commit=True, time=own)
        installed_at = installed_at or own
    if parent and f['version'] in [r['version'] for r in parent['releases']] and s5.get('released'):
        releases[f['version']]['time'] = s5['released']
        installed_at = s5['released']
    act = f.get('activity') or {}
    push = age.get('push') or {}
    facts = {
        'lock': {'name': f['package'], 'version': f['version'], 'time': installed_at, 'php': f['installed_php']['requires'], 'dev': f['dev'],
                 'origin': f['origin']['kind']},
        'releases': sorted(releases.values(), key=lambda r: r['version']),
        'advisories': [{'id': a['id'], 'cve': a['cve'], 'title': a['title'], 'link': a['link'], 'reported_at': a['reported_at'],
                        'severity': a['severity_published'], 'affected_versions': a['affected_versions']} for a in advisories],
        # The feeds' records count every advisory of the name, on any version: more than the counted ones.
        'records': f['security'].get('records'),
        # The advisories that affect the installed version and that the project's audit policy ignores.
        'ignored': f['security']['ignored'],
        'abandoned': marking,
        # The lock's `source` names the repository, and the hydrator answers as the forge answered the model: `status` and
        # `reason` say how. A package that lockrot never asks still names its repository there.
        'activity': {'forge': act['forge'], 'host': act['host'], 'repo': act['repo'], 'status': act['status'], 'reason': act['reason'],
                     'pushed_at': push.get('at'), 'archived': act['archived']} if act.get('host') else None,
    }
    if parent:
        facts['parent'] = parent
    if f['metadata']['status'] == 'not_from_composer_repository':
        # lockrot reads no release of a package that is not from a Composer repository.
        del facts['releases']
    return facts


def rows_of(details):
    return (((details or {}).get('metadata') or {}).get('branches')) or []


def inputs_of(case):
    """What a hydrator feeds the Analyzer to rebuild the case: null when the case carries no branch rows and
    lockrot reads its releases, or when it needs a run input that the hydrator does not take (see unhydrated()).

    The lock links the case's chain and its other direct dependents to the package, and holds every holder that a fix, a
    move or a branch row names. `dependents` holds the `require` names of each direct dependent that C08 measured, and
    `abandoned` the marking that S1 reads. `accept`, when present, holds the flags of the case's project allowlist entry.
    `packages` holds the facts of each package that the case's finding grades by name (a holder, the replacement, an S7
    package), with the chain that reaches it from the root and the one from the case's package."""
    f, run = case['finding'], case['run']
    rows = rows_of(case.get('details'))
    unread = f['metadata']['status'] == 'not_from_composer_repository'
    if (not rows and not unread) or f['metadata']['status'] not in ('read', 'not_from_composer_repository') or unhydrated(case):
        return None
    facts = package_facts(f, rows)
    advisories = (next((s for s in f['signals'] if s['id'] == 'S9'), None) or {}).get('data', {}).get('advisories', [])
    root_require = {f['package']: '*'} if f['direct'] and 'require' in f['named_in'] else {}
    # A name that only require-dev holds counts for the walk only with --dev.
    root_dev = {f['package']: '*'} if 'require-dev' in f['named_in'] else {}
    lock = {}
    through = {e['package']: e for e in (f.get('next_step') or {}).get('through') or []}

    def entry(name):
        """A lock entry of another package: a link of the case's package, a holder or a dependent."""
        if name not in lock:
            e = through.get(name) or {}
            lock[name] = {'name': name, 'version': e.get('installed_version') or '1.0.0', 'dev': False,
                          'origin': 'vcs' if e.get('newest_requires_unmeasured') == 'not_from_composer_repository' else 'packagist'}
        return lock[name]
    chain = f['chain']
    if len(chain) >= 2:
        root_require.setdefault(chain[0], '*')
        for parent, child in zip(chain, chain[1:]):
            entry(parent).setdefault('require', {})[child] = '*'
    for name in f['direct_dependents']:
        if name != f['package'] and name not in lock:
            root_require.setdefault(name, '*')
            requires_it = (through.get(name) or {}).get('installed_requires') is not False or len(chain) < 3
            entry(name).setdefault('require', {})[f['package'] if requires_it else chain[-2]] = '*'
    moves = [m for m in [f.get('next_step')] if m] + [m for m in ((f.get('next_step') or {}).get('also') or [])]
    held = ([h for a in advisories for h in a['fix']['held_by']] + [h for m in moves for h in m['held_by']]
            + [h for r in rows for h in ((r.get('fixes') or {}).get('held_by') or [])])
    root_links = {}
    for h in held:
        # A hold with a target is a link to that name. A `root-require` hold is the root's `require` of it.
        name = h['target'] or f['package']
        if h['source'] == 'root':
            root_links.setdefault('require' if h['link'] == 'root-require' else h['link'], {})[name] = h['constraint']
        elif h['package'] != f['package']:
            # The moved package's own hold is a link of the release that the move asks for, not of a lock entry.
            e = entry(h['package'])
            e['version'] = h['version']
            e.setdefault(h['link'], {})[name] = h['constraint']
    dependents = {}
    for name, e in through.items():
        newest = e.get('newest_stable')
        unlisted = e['newest_requires_unmeasured'] == 'installed_unlisted'
        if (e['installed_requires'] is None and not unlisted) or e['installed_version'] is None:
            continue
        own = [] if unlisted else [{'version': e['installed_version'], 'time': newest['released'] if newest and newest['version'] == e['installed_version'] else None,
                                    'require': {f['package']: '*'} if e['installed_requires'] else {}}]
        if newest and newest['version'] != e['installed_version']:
            own.append({'version': newest['version'], 'time': newest['released'], 'require': {f['package']: '*'} if e['newest_requires'] else {}})
        dependents[name] = own
    root = dict({'name': run['root_package'], 'require': dict({'php': run['project_php']} if run['project_php'] else {}, **dict(root_require, **root_links.pop('require', {})))}, **root_links)
    if root_dev:
        root['require-dev'] = dict(root_dev, **root.get('require-dev', {}))
    inputs = dict({
        # A run that read no composer.json has no root.
        'root': None if f['depth_unmeasured'] == 'no_composer_json' else root,
        'lock': [facts['lock']] + list(lock.values()),
    }, **{k: v for k, v in facts.items() if k != 'lock'})
    inputs['dependents'] = dependents
    inputs['run'] = {'target_php': run['target_php'], 'project_php': run['project_php'], 'fail_on': run['fail_on'], 'include_dev': run['include_dev'],
                     'advisories_unchecked': f['security']['unchecked_reason']}
    s7 = next((sg['data'] for sg in f['signals'] if sg['id'] == 'S7'), None) or {}
    below = {p['package']: p['chain'] for p in s7.get('packages', [])}
    related = case.get('related') or {}
    if related:
        inputs['packages'] = {name: dict(package_facts(r['finding'], rows_of(r.get('details'))), chain=r['finding']['chain'], below=below.get(name))
                              for name, r in sorted(related.items())}
    # Composer's abandoned ignore list of the project, as `policy.abandoned.ignore` or `audit.ignore-abandoned` holds it.
    ignored = f.get('abandoned_ignored')
    if ignored is not None:
        inputs['abandoned_ignore'] = {'by': ignored['by'], 'rules': ignored['rules']}
    # A builtin entry comes from lockrot's own allowlist. A project entry is the case's config: null accepts every flag.
    allowlist = f.get('allowlist')
    if allowlist is not None and allowlist['by'] == 'project':
        inputs['accept'] = allowlist['flag_ids']
        inputs['allowlist'] = {k: allowlist[k] for k in ('pattern', 'version', 'reason', 'expires')}
    # The lock facts that the case's findings do not carry: the model's ‡ run inputs, in the case's `run_inputs` block.
    run_inputs = case.get('run_inputs') or {}
    # The move checks that Composer's solver answered, keyed as lockrot keys each request (MoveQuery::key()).
    checks = merged_checks(recorded_checks(f, rows), run_inputs)
    world = case.get('solver_world')
    if world is not None:
        # Composer's answer to every request that the model asked, the baseline and the combined update included:
        # the hydrator serves only these, and the real-solver test serves the world itself.
        checks = {k: dict(a, reason=None) for k, a in world['answers'].items()}
        inputs['solver_world'] = world
        if world['check_passes'] is not None:
            inputs['check_passes'] = world['check_passes']
    if checks:
        inputs['checks'] = checks
        # The PHP that Composer solved at: the world's `config.platform.php`, which the run notes quote.
        inputs['move_check'] = run['move_check'] if world is None else dict(run['move_check'], php=world['world']['platform_php'])
    if run_inputs.get('_php_lock') is not None:
        inputs['php_lock'] = run_inputs['_php_lock']
    if run_inputs.get('_config_platform_php') is not None:
        inputs['config_platform_php'] = run_inputs['_config_platform_php']
    act = f.get('activity') or {}
    if f['origin']['kind'] == 'vcs' and act.get('host') and inputs['root'] is not None:
        # The project's vcs repository serves the package: lockrot names its origin from it.
        inputs['root']['repositories'] = [{'type': 'vcs', 'url': 'https://%s/%s.git' % (act['host'], act['repo'])}]
    return inputs


def merged_checks(recorded, run_inputs):
    """The recorded checks and the answers of the run inputs (`_checks`), which no finding carries: the baseline and
    `target_fix`. An answer of the run inputs replaces a recorded one of the same request."""
    return dict(recorded, **(run_inputs.get('_checks') or {}))


def recorded_checks(f, rows):
    """Each checked move of the finding and each checked branch row, by request key: `verb package constraint php`."""
    pkg, direct = f['package'], bool(f['direct'])
    verb = 'require' if direct else 'update'
    out = {}

    def keep(key, check, held=()):
        out[key] = dict(check, holds=[{k: h[k] for k in ('source', 'package', 'version', 'link', 'target', 'constraint')} for h in held]
                        if check['result'] == 'refused' else [])
    for m in [m for m in [f.get('next_step')] if m] + ((f.get('next_step') or {}).get('also') or []):
        # A move with no release read names no version, so it sends no request.
        if m.get('check') is None or m['check'].get('reason') == 'no_release_read':
            continue
        # Every kind sends the raise of its php_check (§5.6, the commands).
        raise_to = (m.get('php_check') or {}).get('raise_to') or ''
        if m['kind'] == 'update':
            key = 'update %s ^%s %s' % (pkg, m['version'].lstrip('vV'), raise_to)
        elif m['kind'] in ('require', 'raise-php'):
            key = '%s %s %s %s' % (verb, pkg, m['constraint'], raise_to)
        else:
            key = '%s %s ^%s %s' % (verb, pkg, m['latest']['version'].lstrip('vV'), raise_to)
        keep(key, m['check'], m['held_by'])
    for r in rows:
        fx = r.get('fixes') or {}
        if fx.get('check') is None:
            continue
        on_installed = r['branch'] == f['branch'] and fx['fix_kind'] == 'update'
        keep('update %s ^%s ' % (pkg, fx['lowest'].lstrip('vV')) if on_installed else '%s %s ^%s ' % (verb, pkg, fx['lowest'].lstrip('vV')), fx['check'], fx['held_by'])
    return out


def unhydrated(case):
    """Why the hydrator cannot rebuild the case, or None: an advisory lookup scope other than `all`, an accept-unchecked
    entry of the project, or registry dates on a package that no registry serves."""
    if (case['run'].get('advisory_lookup') or {}).get('scope', 'all') != 'all':
        return 'advisory-lookup'
    if any(c['reason'] == 'accepted_by_config' for c in case['finding'].get('checks_skipped') or []):
        return 'accept-unchecked'
    if case['finding']['metadata']['status'] == 'not_from_composer_repository' and ((case['finding'].get('age') or {}).get('installed') or {}).get('dated_by'):
        # A variant that keeps a registry's dates on a package that no registry serves: no lock gives both.
        return 'registry-dates'
    return None


def dump(path, doc):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w') as fh:
        json.dump(doc, fh, indent=1, ensure_ascii=False)
        fh.write('\n')


def dump_gz(path, doc):
    """The document gzipped with no name and no time in the header, as `gzip -9n` writes it."""
    os.makedirs(os.path.dirname(path), exist_ok=True)
    data = (json.dumps(doc, indent=1, ensure_ascii=False) + '\n').encode()
    with open(path, 'wb') as raw, gzip.GzipFile(filename='', mode='wb', fileobj=raw, compresslevel=9, mtime=0) as fh:
        fh.write(data)


# Every model document set: the model's directory under docs/, the set under documents/, gzipped or not.
DOCUMENT_SETS = [('report-2', 'corpus', True), ('report-2-variants', 'variants', False), ('cases', 'cases', False),
                 (os.path.join('coverage', 'baselined'), os.path.join('coverage', 'baselined'), True),
                 (os.path.join('coverage', 'variants'), os.path.join('coverage', 'variants'), True),
                 ('explain-2', 'explain', False), (os.path.join('explain-2', 'coverage'), os.path.join('explain', 'coverage'), False)]
DOCUMENT_SETS += [(os.path.join('baseline-2', sub), os.path.join('baseline', sub), False)
                  for sub in ('cases', 'corpus', 'earlier', 'gate-rows', 'regenerate', 'regenerate-from-baseline-1')]
DOCUMENT_SETS += [('config-2', 'config', False)] + [(os.path.join('config-2', sub), os.path.join('config', sub), False) for sub in ('coverage', 'repo-docs')]
# The page goldens' own documents: values no closed list holds, so no strict schema reads them.
DOCUMENT_SETS += [('page', 'page', False)]
# Files of the model's directories that are not documents.
NOT_DOCUMENTS = ('INDEX.json', 'EXPECT.json', 'corrections.json')


def write_documents(source, run_date, suite, proj, page_goldens):
    counts = {}
    for model_dir, out_dir, zipped in DOCUMENT_SETS:
        paths = sorted(p for p in glob.glob(os.path.join(source, 'docs', model_dir, '*.json')) if not p.endswith(NOT_DOCUMENTS))
        for path in paths:
            with open(path) as fh:
                doc = json.load(fh)
            if isinstance(doc, dict) and isinstance(doc.get('run'), dict) and 'findings' in doc:
                doc['run'] = project_run(doc['run'], proj, 'documents.run')
            stem = os.path.basename(path)
            if zipped:
                dump_gz(os.path.join(DOCS_OUT, out_dir, stem + '.gz'), doc)
            else:
                dump(os.path.join(DOCS_OUT, out_dir, stem), doc)
        counts[out_dir] = len(paths)
    dump(os.path.join(DOCS_OUT, 'provenance.json'), {'source': 'security-axis/r3/docs', 'model_run_date': run_date, 'suite': suite, 'documents': counts})
    # A page golden must never lag the page document that it reads.
    shutil.copyfile(page_goldens, PAGE_GOLDENS_OUT)
    return counts


def sha256(path):
    with open(path, 'rb') as fh:
        return hashlib.sha256(fh.read()).hexdigest()


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('--pr', required=True, help='the pull request id: ' + ', '.join(TRAIN))
    ap.add_argument('--source', required=True, help="the model directory with cases.json and docs/")
    ap.add_argument('--run-date', default=None, help='the date the model ran, for provenance.json')
    ap.add_argument('--suite', default=None, help="the model suite's result on that run, for provenance.json")
    ap.add_argument('--page-goldens', default=None, help="the page goldens to copy, rendered by the model for this pull request's fields "
                                                         "(default: the model directory's page-goldens.json)")
    args = ap.parse_args()
    proj = Projection(args.pr)
    # From pull request 7a on, every model document is written, without the keys of a later pull request.
    documents = not proj.after('7a')
    if documents and args.page_goldens is None and proj.later(PHP_WINDOW_KEYS):
        # The model's goldens render every run.php_window key, so they disagree with documents that drop one.
        sys.stderr.write('--pr %s drops run.php_window.%s: pass --page-goldens with the goldens rendered without it\n'
                         % (args.pr, ', run.php_window.'.join(proj.later(PHP_WINDOW_KEYS))))
        return 2
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
        # The related packages' findings and the ‡ run inputs feed the inputs only.
        c.pop('related', None)
        c.pop('run_inputs', None)
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
    for stem in COVERAGE_VARIANTS if not proj.after('4f') else []:
        with open(os.path.join(source, 'docs', 'coverage', 'variants', stem + '.json')) as fh:
            dump(os.path.join(FLAGS_OUT, 'coverage', stem + '.json'), project_report(json.load(fh), proj))
    with open(os.path.join(source, 'docs', 'explain-2', 'B.json')) as fh:
        dump(os.path.join(DOCS_OUT, 'explain', 'B.json'), project_explain(json.load(fh), proj))
    page_goldens = os.path.abspath(args.page_goldens) if args.page_goldens else os.path.join(source, 'page-goldens.json')
    counts = write_documents(source, args.run_date, args.suite or model.get('validation'), proj, page_goldens) if documents else None
    dump(os.path.join(FLAGS_OUT, 'provenance.json'), {
        'pr': args.pr,
        'source': {'cases.json': sha256(cases_path), 'model_run_date': args.run_date},
        'suite': args.suite or model.get('validation'),
        'page_goldens': {'sha256': sha256(page_goldens), 'rendered': "the model's goldens" if args.page_goldens is None
                         else "the model's goldens, rendered again by the model with " + ', '.join('run.php_window.' + k for k in proj.later(PHP_WINDOW_KEYS)) + ' null'}
        if documents else None,
        'dropped': sorted(proj.dropped),
        'reverted': dict(sorted(proj.reverted.items())),
        'cases_without_inputs': without_inputs,
        'negative_bases': bases,
    })
    print('%d cases (%d without inputs), %d base documents, %d dropped keys, %d kinds of reverted values'
          % (len(cases), len(without_inputs), len(bases), len(proj.dropped), len(proj.reverted)))
    if counts is not None:
        print('documents: ' + ', '.join('%s %d' % kv for kv in counts.items()))
    return 0


if __name__ == '__main__':
    sys.exit(main())
