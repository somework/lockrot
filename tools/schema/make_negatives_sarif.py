"""critic round 2 (11.CR4): negative fixtures for lockrot-sarif-properties-2.schema.json, each a generated SARIF log
(r3/docs/sarif/B.sarif.json: monolog-bridge, two maintenance results and one advisory) with one planted defect and an
`$expect` naming the error it must produce (validate.php --expect-invalid)."""
import copy, glob, json, os

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.join(HERE, '..', '..')
BASE = json.load(open(os.path.join(ROOT, 'tests', 'fixtures', 'sarif', 'documents', 'B.sarif.json')))
OUT = os.path.join(ROOT, 'tests', 'fixtures', 'sarif', 'negative')
R = lambda d: d['runs'][0]['results']
M0 = lambda d: next(r for r in R(d) if r['ruleId'] == 'lockrot/left-behind')
M1 = lambda d: next(r for r in R(d) if r['ruleId'] == 'lockrot/old-promise')
A0 = lambda d: next(r for r in R(d) if r['ruleId'].startswith('lockrot/advisory/'))
CASES = []


def case(name, error, strict=False):
    def wrap(fn):
        CASES.append((name, fn, error, strict))
        return fn
    return wrap


@case('properties-baseline-written', 'properties: Matched a schema which it should not')
def _(d): M0(d)['properties']['baseline'] = 'known'  # 0.13's name; report-2 renames it baseline_status
@case('verdict-cause-word', 'verdict')
def _(d): M0(d)['properties']['verdict'] = 'left-behind'  # the grade moved here; the cause word is lead
@case('chain-without-signal-ids', 'properties')
def _(d): del M0(d)['properties']['signal_ids']  # first-of-kind keys come together
@case('first-of-kind-keys-on-a-second-result-alone', 'properties')
def _(d): M1(d)['properties']['chain'] = ['symfony/monolog-bridge']
@case('error-with-empty-error-by', 'error_by')
def _(d): M0(d)['properties']['error_by'] = []
@case('warning-with-error-by', 'error_by')
def _(d): M1(d)['properties']['error_by'] = ['high']
@case('suppression-on-a-warning', 'level')
def _(d): M1(d)['suppressions'] = [dict(kind='external', status='accepted', justification='covered by lockrot-baseline.json')]
@case('suppression-in-source', 'kind')
def _(d):
    r = M1(d); r['level'] = 'note'; r['suppressions'] = [dict(kind='inSource', status='accepted', justification='x')]
@case('maintenance-without-term', 'term')
def _(d): del M0(d)['properties']['term']
@case('maintenance-term-is-security', 'term')
def _(d): M0(d)['properties']['term'] = A0(d)['properties']['term']
@case('deciding-advisory-without-term', 'term')
def _(d): del A0(d)['properties']['term']
@case('advisory-fix-as-string', 'fix')
def _(d): A0(d)['properties']['fix'] = 'upgrade'  # `fix` is always the object, `fix_kind` the string
@case('advisory-severity-unknown', 'severity')
def _(d): A0(d)['properties']['severity'] = 'moderate'
@case('signals-old-name', 'signal_ids is required')  # chain and direct_dependents without signal_ids: the first-of-kind keys come together
def _(d): M0(d)['properties']['signals'] = M0(d)['properties'].pop('signal_ids')
@case('run-lockrot-schema-1', 'lockrot_schema')
def _(d): d['runs'][0]['properties']['lockrot_schema'] = 1
@case('run-without-score-text-grammar', 'score_text_grammar')
def _(d): del d['runs'][0]['properties']['score_text_grammar']
@case('rank-above-100', 'rank')
def _(d): M0(d)['rank'] = 125.0
@case('score-total-a-string', 'score_total')
def _(d): M0(d)['properties']['score_total'] = '36'
# 0.14 sync: C67's invocation (the exit code the gate decides), W12's depth and C72's age beside chain, C75's run lookup
INV = lambda d: d['runs'][0]['invocations'][0]
@case('exit-code-1-on-a-passing-gate', 'invocations[0]')
def _(d): INV(d)['properties']['gate']['fails'] = False; INV(d)['properties']['gate']['tripped_by'] = []
@case('exit-code-2', 'exitCode')
def _(d): INV(d)['exitCode'] = 2
@case('without-exit-code-description', 'exitCodeDescription')
def _(d): del INV(d)['exitCodeDescription']
@case('invocation-without-gate', 'gate')
def _(d): del INV(d)['properties']['gate']
@case('execution-not-successful', 'executionSuccessful')
def _(d): INV(d)['executionSuccessful'] = False
@case('age-on-a-second-result-alone', 'properties')
def _(d): M1(d)['properties']['age'] = M0(d)['properties']['age']
@case('chain-without-depth', 'depth is required')
def _(d): del M0(d)['properties']['depth']
@case('run-without-advisory-lookup', 'advisory_lookup')
def _(d): del d['runs'][0]['properties']['advisory_lookup']
@case('strict-bag-gains-a-key', 'later_key', strict=True)
def _(d): M0(d)['properties']['later_key'] = 1  # published valid: bags are open; the strict twin closes them


if __name__ == '__main__':
    os.makedirs(OUT, exist_ok=True)
    for old in glob.glob(os.path.join(OUT, '*.json')):
        os.remove(old)
    for i, (name, fn, err, strict) in enumerate(CASES, 1):
        d = copy.deepcopy(BASE)
        fn(d)
        d = {'$expect': {'strict': strict, 'error': err}, **d}
        with open(os.path.join(OUT, '%02d-%s%s.json' % (i, 'strict-' if strict and not name.startswith('strict-') else '', name)), 'w') as fh:
            json.dump(d, fh, indent=1, ensure_ascii=False)
    print('%d negative fixtures in %s' % (len(CASES), OUT))
