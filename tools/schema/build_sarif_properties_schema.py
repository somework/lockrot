"""critic round 2 (11.CR4): lockrot-sarif-properties-2.schema.json, the SARIF property bags of §7.5 (result and run), the test
fixture PR 7's SarifFormatterTest validates every SARIF fixture's bags against (tests/fixtures/sarif/; never a published file).

Built as explain-2 is (attack round 1): every report-2 definition it uses is copied byte for byte from the built report-2 file
(`term` is report-2's term, `fix` its `s9fix`), so the bags cannot drift from the finding they project; parity.py holds the
copies identical. The instance is a whole SARIF 2.1.0 log: objects stay open, so the same document is validated by the SARIF
schema (structure) and by this one (lockrot's bags and the per-result relations draft-04 can state):

- a result's bag is chosen by its `ruleId`: `lockrot/advisory/<id>`, a maintenance flag `lockrot/<flag>`, `lockrot/unchecked`,
  or another lockrot rule (`--all`'s `lockrot/ok`, `finished`, `unknown`), each a disjoint branch;
- every bag holds the common keys; `chain`, `direct_dependents` and `signal_ids` are all present or all absent (the generator,
  spec_flags_r3_sarif.py, asserts the cross-result rule: on a package's first result of each kind only);
- `level` is `error` exactly when `error_by` is not empty; `suppressions` (one shape) only on a `note`;
- an advisory bag carries `term` exactly when `deciding` is true; `baseline` (0.13's name for `baseline_status`) is never written.
"""
import copy
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from ship_text import ship_schema, leftovers  # noqa: E402

ROOT = os.path.join(HERE, '..', '..')
R = json.load(open(os.path.join(ROOT, 'resources', 'lockrot-report-2.schema.json')))
RD = R['definitions']
HYPHEN = '^[a-z][a-z0-9-]*$'
MAINT = RD['maintenanceFlag']['enum']


def ref(n):
    return {'$ref': '#/definitions/' + n}


def d(desc, schema):
    out = {'description': desc}
    out.update(schema)
    return out


def refs(node, out):
    if isinstance(node, dict):
        r = node.get('$ref')
        if isinstance(r, str) and r.startswith('#/definitions/'):
            out.add(r.split('/')[-1])
        for v in node.values():
            refs(v, out)
    elif isinstance(node, list):
        for v in node:
            refs(v, out)
    return out


def closure(roots):
    seen, todo = set(), list(roots)
    while todo:
        n = todo.pop()
        if n not in seen:
            seen.add(n)
            todo += list(refs(RD[n], set()) - seen)
    return seen


COPIED = sorted(closure(['termMaintenance', 'termSecurity', 's9fix', 'packageName', 'verdict', 'flag', 'maintenanceFlag', 'severity',
                         'advisoryId', 'signalId', 'dateTime',
                         # 0.14 sync: the age, depth, run gate, gate values and advisory lookup a bag carries, report-2's own definitions
                         'age', 'depth', 'rootGate', 'gateValue', 'advisoryLookup']))
defs = {n: copy.deepcopy(RD[n]) for n in COPIED}
FP = RD['finding']['properties']

COMMON = {
    'package': d('The package (`finding.package`).', ref('packageName')),
    'version': copy.deepcopy(FP['version']),
    'verdict': d("The finding's verdict, a grade or a score-0 word (`finding.verdict`; 0.13 wrote the cause word here, now `lead`).", ref('verdict')),
    'priority': copy.deepcopy(FP['priority']),
    'lead': copy.deepcopy(FP['lead']),
    'score_total': d('`finding.score.total`.', {'type': 'integer', 'minimum': 0}),
    'score_text': d('`finding.score.text` (ScoreText); prose, not contract.', {'type': 'string', 'minLength': 1}),
    'score_model_id': d('`finding.score.model`.', {'type': 'integer', 'minimum': 1}),
    'flag_ids': d("The finding's fired flags the allowlist does not accept, in flag order (`finding.flags[].id` where `role` is not `accepted`).",
                  {'type': 'array', 'items': ref('flag'), 'uniqueItems': True}),
    'direct': d('`finding.direct`.', {'type': 'boolean'}),
    'dev': d('`finding.dev`.', {'type': 'boolean'}),
    'data_date': copy.deepcopy(FP['data_date']),
    'baseline_status': d("`finding.baseline.status` (`known`, `new`, `worsened`), or null when the run read no baseline or the finding stands nowhere "
                         "against it. 0.13 named it `baseline`, which report-2 uses for the `{status, recorded, …}` object.",
                         {'oneOf': [copy.deepcopy(RD['findingBaseline']['oneOf'][0]['properties']['status']), {'type': 'null'}]}),
    'error_by': d("The active `run.gates[]` values whose `gate.by[].facts` name this result's fact; empty when the result is not `error`.",
                  {'type': 'array', 'items': {'type': 'string', 'pattern': HYPHEN}, 'uniqueItems': True}),
}
FIRST = {
    'chain': copy.deepcopy(FP['chain']),
    'depth': d('`finding.depth`: hops from the nearest composer.json requirement; null when unreached.', ref('depth')),
    'direct_dependents': copy.deepcopy(FP['direct_dependents']),
    'signal_ids': d("The finding's signal ids (`finding.signals[].id`).", {'type': 'array', 'items': ref('signalId'), 'uniqueItems': True}),
    'age': d("`finding.age`: how old the installed release, the newest release, the installed branch's newest release and the last push are.", ref('age')),
}
FIRST_ALL_OR_NONE = {'description': '`chain`, `depth`, `direct_dependents`, `signal_ids` and `age` come together: on the first result of each kind for a package, on no other.',
                     'anyOf': [{'required': list(FIRST)}, {'not': {'anyOf': [{'required': [k]} for k in FIRST]}}]}
NO_BASELINE = {'description': "`baseline` is 0.13's name for `baseline_status` and is never written.", 'not': {'required': ['baseline']}}


def bag(desc, extra, required_extra, relations=()):
    props = dict(COMMON, **FIRST, **extra)
    return {'description': desc, 'type': 'object', 'required': list(COMMON) + required_extra, 'properties': props,
            'allOf': [FIRST_ALL_OR_NONE, NO_BASELINE] + list(relations)}


defs['resultMaintenance'] = bag("The bag of a maintenance result, one per counted maintenance flag (`lockrot/<flag>`).",
                                {'term': d("A copy of the flag's `score.terms[]` entry; the level is read from `error_by`, never from it.", ref('termMaintenance'))},
                                ['term'])
defs['resultAdvisory'] = bag("The bag of an advisory result, one per (package, counted advisory) (`lockrot/advisory/<id>`).", {
    'advisory': d('The advisory id (the S9 row `id`).', ref('advisoryId')),
    'cve': copy.deepcopy(RD['s9row']['properties']['cve']),
    'severity': d('The S9 row `severity`.', ref('severity')),
    'fix': d("The S9 row's `fix` object (`fix` is always the object and `fix_kind` the string).", ref('s9fix')),
    'points': copy.deepcopy(RD['s9row']['properties']['points']),
    'deciding': copy.deepcopy(RD['s9row']['properties']['deciding']),
    'term': d('The security term, on the deciding advisory only.', ref('termSecurity')),
}, ['advisory', 'cve', 'severity', 'fix', 'points', 'deciding'], [
    {'description': '`term` exactly on the deciding advisory.',
     'anyOf': [{'properties': {'deciding': {'enum': [True]}}, 'required': ['term']},
               {'properties': {'deciding': {'enum': [False]}}, 'not': {'required': ['term']}}]}])
defs['resultUnchecked'] = bag("The bag of a `lockrot/unchecked` result: a finding with S10 and no counted fact, when `unchecked` is active.", {},
                              list(FIRST))
defs['resultOther'] = bag("The bag of another lockrot result (`--all`'s `lockrot/ok`, `lockrot/finished`, `lockrot/unknown`).", {}, [])

ESC = lambda s: s.replace('/', '\\/')
MAINT_RX = ESC('^lockrot/(' + '|'.join(MAINT) + ')$')
ADV_RX = ESC('^lockrot/advisory/')
UNC = 'lockrot/unchecked'
P = lambda **kv: {'properties': kv}
defs['suppression'] = {'description': 'The one suppression lockrot writes: a fact the baseline knows or covers.', 'type': 'object',
                       'required': ['kind', 'status', 'justification'], 'properties': {
                           'kind': d('Closed.', {'type': 'string', 'enum': ['external']}),
                           'status': d('Closed.', {'type': 'string', 'enum': ['accepted']}),
                           'justification': d('"covered by <baseline path> (known since <date>)"; prose, not contract.', {'type': 'string', 'minLength': 1})}}
defs['result'] = {
    'description': "A SARIF result lockrot writes; SARIF 2.1.0's schema checks the rest of it.",
    # the SARIF envelope says `additionalProperties: true` so the tests' strict twin closes only lockrot's bags, never SARIF's own keys
    'type': 'object', 'required': ['ruleId', 'level', 'properties'], 'additionalProperties': True,
    'properties': {
        'ruleId': d('`lockrot/<flag>`, `lockrot/advisory/<id>`, `lockrot/unchecked`, or a score-0 word under `--all`.', {'type': 'string', 'pattern': ESC('^lockrot/')}),
        'level': d("`error` (a fact `gate.by[].facts` names), `note` (a fact the baseline knows or covers, or a score-0 result), else `warning`. Closed.",
                   {'type': 'string', 'enum': ['error', 'warning', 'note']}),
        'rank': d("A maintenance result: its term's band × 25 (25 below low's floor); an advisory result: its severity (95 80 55 55 20).",
                  {'type': 'number', 'minimum': 0, 'maximum': 100}),
        'suppressions': d('Present only on a `note` for a fact the baseline knows or covers.', {'type': 'array', 'minItems': 1, 'maxItems': 1, 'items': ref('suppression')}),
        'properties': d('The property bag, chosen by `ruleId`.', {'type': 'object'}),
    },
    'allOf': [
        {'description': 'The bag by `ruleId` (the four branches exclude each other).', 'anyOf': [
            P(ruleId={'pattern': ADV_RX}, properties=ref('resultAdvisory')),
            P(ruleId={'pattern': MAINT_RX}, properties=ref('resultMaintenance')),
            P(ruleId={'enum': [UNC]}, properties=ref('resultUnchecked')),
            {'properties': {'ruleId': {'not': {'anyOf': [{'pattern': ADV_RX}, {'pattern': MAINT_RX}, {'enum': [UNC]}]}}, 'properties': ref('resultOther')}}]},
        {'description': '`level` is `error` exactly when `error_by` is not empty.', 'anyOf': [
            P(level={'enum': ['error']}, properties=P(error_by={'minItems': 1})),
            P(level={'not': {'enum': ['error']}}, properties=P(error_by={'maxItems': 0}))]},
        {'description': '`suppressions` only on a `note`.', 'anyOf': [{'not': {'required': ['suppressions']}}, P(level={'enum': ['note']})]},
    ],
}
defs['runProperties'] = {'description': "The SARIF run's bag: what a consumer guards on, as a jq user guards on `.lockrot.schema`.", 'type': 'object',
                         'required': ['lockrot_schema', 'text_grammar', 'score_text_grammar', 'score_model_id', 'fix_model', 'advisory_lookup'], 'properties': {
                             'lockrot_schema': d('The report schema number this SARIF projects: 2.', {'type': 'integer', 'enum': [2]}),
                             'text_grammar': d('`run.text_grammar`: every `message` and rule text.', {'type': 'integer', 'minimum': 1}),
                             'score_text_grammar': d('`run.score_model.score_text_grammar`: every `score_text`.', {'type': 'integer', 'minimum': 1}),
                             'score_model_id': d('`run.score_model.id`.', {'type': 'integer', 'minimum': 1}),
                             'fix_model': d('`run.fix_model`.', {'type': 'integer', 'minimum': 1}),
                             'advisory_lookup': d("`run.advisory_lookup`: the run's advisory lookup, typed as report-2 types it.", ref('advisoryLookup'))}}
# 0.14 sync (C67 §3.4): the one invocation lockrot writes, its exit code the gate's, and the gate and run facts as report-2 types them
defs['invocationProperties'] = {'description': "The invocation's bag: the run gate and the run keys it reads, each typed as report-2 types it.", 'type': 'object',
                                'required': ['gate', 'fail_on', 'fail_on_source', 'gates', 'mode', 'strict_network'], 'properties': {
                                    'gate': d("The report's root `gate`.", ref('rootGate')),
                                    'fail_on': copy.deepcopy(RD['run']['properties']['fail_on']),
                                    'fail_on_source': copy.deepcopy(RD['run']['properties']['fail_on_source']),
                                    'gates': d('`run.gates`.', {'type': 'array', 'items': ref('gateValue')}),
                                    'mode': copy.deepcopy(RD['run']['properties']['mode']),
                                    'strict_network': copy.deepcopy(RD['run']['properties']['strict_network'])}}
defs['invocation'] = {'description': 'The invocation lockrot writes.', 'type': 'object', 'additionalProperties': True,
                      'required': ['executionSuccessful', 'exitCode', 'exitCodeDescription', 'properties'], 'properties': {
                          'executionSuccessful': d('lockrot ran to completion: always true (the exit code is the gate\'s).', {'enum': [True]}),
                          'exitCode': d('1 when `properties.gate.fails`, else 0.', {'type': 'integer', 'enum': [0, 1]}),
                          'exitCodeDescription': d('`properties.gate.text`, the gate: line; prose, not contract.', {'type': 'string', 'minLength': 1}),
                          'properties': ref('invocationProperties')},
                      'anyOf': [{'description': 'Exit code 1 exactly when the gate fails.', 'properties': {'exitCode': {'enum': [1]}, 'properties': {'properties': {'gate': {'properties': {'fails': {'enum': [True]}}}}}}},
                                {'properties': {'exitCode': {'enum': [0]}, 'properties': {'properties': {'gate': {'properties': {'fails': {'enum': [False]}}}}}}}]}

schema = {
    '$schema': 'http://json-schema.org/draft-04/schema#',
    'id': 'https://lockrot.dev/schema/sarif-properties-2.json',
    'title': 'lockrot SARIF property bags',
    'description': ("The property bags lockrot 0.14.0 writes into its SARIF 2.1.0 output (`--format=sarif`), for report schema 2: each result's "
                    "`properties` and the run's. A test fixture of lockrot's SarifFormatterTest, not a published schema: the instance is the "
                    "whole SARIF log, whose structure SARIF 2.1.0's own schema checks; objects stay open."),
    'type': 'object', 'required': ['runs'], 'additionalProperties': True,
    'properties': {'runs': d('The runs; lockrot writes one.', {'type': 'array', 'minItems': 1, 'items': {
        'description': 'A run.', 'type': 'object', 'required': ['properties', 'invocations', 'results'], 'additionalProperties': True, 'properties': {
            'properties': ref('runProperties'),
            'invocations': d('lockrot writes one invocation.', {'type': 'array', 'minItems': 1, 'maxItems': 1, 'items': ref('invocation')}),
            'results': d('The results.', {'type': 'array', 'items': ref('result')})}}})},
    'definitions': dict(sorted(defs.items())),
}
ship_schema({k: v for k, v in schema['definitions'].items() if k not in COPIED})
for n in COPIED:
    assert json.dumps(schema['definitions'][n], sort_keys=True) == json.dumps(RD[n], sort_keys=True), n
lo = leftovers(schema)
assert not lo, lo[:3]
out = os.path.join(ROOT, 'tests', 'fixtures', 'sarif', 'lockrot-sarif-properties-2.schema.json')
os.makedirs(os.path.dirname(out), exist_ok=True)
with open(out, 'w') as fh:
    json.dump(schema, fh, indent=2, ensure_ascii=False)
    fh.write('\n')
print('written %s: %d definitions (%d copied from report-2 byte for byte)' % (out, len(schema['definitions']), len(COPIED)))
