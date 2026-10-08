"""critic round 2 (11.CR5): the TypeScript contract of report-2, prototyped.

§8.4 plans `resources/lockrot-report-2.d.ts`: hand-written, every key required, discriminated unions, open sets as
`Known | (string & {})`. A hand-written file drifts from the schema in exactly the places a compiler cannot see (a property
typed `string` where the schema has a closed set, a known value the union lacks), and compiling recorded documents against
it proves only that they are assignable. So the contract is split in two:

  lockrot-report-2.known.d.ts   GENERATED at build time (tools/schema): one literal union per `enum` and `x-known-values` node
                                of report-2 (closed: the literals; open: `Known | (string & {})`, or `(number & {})` for the
                                three integer sets), named by the node; `known-map.json` records pointer -> name -> values
  lockrot-report-2.d.ts         the object shapes, which import every union from the file above and never spell a literal
                                set. This script emits the prototype PR 7 starts from (every report-2 definition an
                                interface or a union of interfaces, every required key required, `oneOf`/`anyOf` a union,
                                relation `allOf` groups ignored: they are rules, not shapes); after PR 7 it is hand-kept
  probes.generated.ts           one type-level equality per union pointer the shapes can reach by indexed access
                                (`Equal<NonNullable<Finding['reach']>, FindingReach>`), so `tsc --strict` fails when a
                                hand edit types a closed or open set as `string`, or the union and the schema disagree

union_parity.js (the PR 7 test) reads the generated unions back through the TypeScript compiler API and asserts each equals
its schema node, and dts_check.js compiles the d.ts, the probes and every report-2 document (`satisfies Report2`).

  python3 emit_dts.py      writes the three files and known-map.json next to it
"""
import json
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.normpath(os.path.join(HERE, '..', '..', '..'))
OUT = os.path.join(REPO, 'types')
S = json.load(open(os.path.join(REPO, 'resources', 'lockrot-report-2.schema.json')))
D = S['definitions']
BASE = '\0base'  # on a branch joined with its node: (the node's pointer, the keys the branch restates)
NAMES = {}       # pointer -> union type name
BY_KEY = {}
KNOWN = []       # (name, pointer, values, open, integer)
PROBES = []      # (ts expression, union name)
UNPROBED = []


def pascal(s):
    return ''.join(w[:1].upper() + w[1:] for w in re.split(r'[^A-Za-z0-9]+', s) if w)


def union_name(ptr):
    """a readable unique name from the pointer: definition + property names, branch indexes dropped."""
    segs = ptr.lstrip('#/').split('/')
    parts = []
    i = 0
    while i < len(segs):
        s = segs[i]
        if s in ('definitions', 'properties'):
            parts.append(segs[i + 1]); i += 2; continue
        if s in ('oneOf', 'anyOf'):
            i += 2; continue
        if s == 'items':
            parts.append('item'); i += 1; continue
        if s == 'patternProperties':
            parts.append('key'); i += 2; continue
        if s == 'additionalProperties':
            parts.append('value'); i += 1; continue
        parts.append(s); i += 1
    base = pascal('_'.join(parts)) or 'Root'
    name, n = base, 2
    while name in NAMES.values():
        name, n = base + str(n), n + 1
    return name


def lit(v):
    return json.dumps(v)


def union(node, ptr):
    if ptr in NAMES:
        return NAMES[ptr]
    vals, open_ = None, False
    if isinstance(node.get('enum'), list):
        vals = node['enum']
    elif isinstance(node.get('x-known-values'), list):
        vals, open_ = node['x-known-values'], True
    integer = all(isinstance(v, int) and not isinstance(v, bool) for v in vals)
    # a branch joined with its node repeats the node's own sets: one name per (property, values, openness)
    key = (ptr.split('/')[-1] if ptr.count('/') > 2 else ptr, json.dumps(vals), open_)
    if key in BY_KEY:
        name = BY_KEY[key]
        NAMES[ptr] = name
        next(k for k in KNOWN if k['name'] == name)['pointers'].append(ptr)
        return name
    name = pascal(ptr.split('/')[-1]) if ptr.count('/') == 2 else union_name(ptr)
    NAMES[ptr] = name
    BY_KEY[key] = name
    KNOWN.append(dict(name=name, pointer=ptr, pointers=[ptr], values=vals, open=open_, integer=integer))
    return name


def merge(rest, b):
    """a branch joined with the rest of its node: `properties` and `required` merged, the branch's own keys winning."""
    out = dict(rest, **b)
    if 'properties' in rest and 'properties' in b:
        props = dict(rest['properties'])
        for k, v in b['properties'].items():
            rp = rest['properties'].get(k)
            if isinstance(rp, dict) and isinstance(v, dict) and '$ref' not in v and 'properties' in v and 'type' not in v:
                # a branch that narrows members of a referenced object (a flag's headline unit) narrows that object, it does not replace it
                rp = D[rp['$ref'].split('/')[-1]] if '$ref' in rp else rp
                props[k] = merge({kk: vv for kk, vv in rp.items() if kk not in ('description', 'allOf')}, v)
            elif isinstance(rp, dict) and isinstance(v, dict) and '$ref' not in v and '$ref' not in rp:
                props[k] = merge(rp, v)
            else:
                props[k] = v
        out['properties'] = props
    if 'required' in rest or 'required' in b:
        out['required'] = list(dict.fromkeys((rest.get('required') or []) + (b.get('required') or [])))
    return out


def T(node, ptr, expr):
    """the TS type of `node` at schema pointer `ptr`; `expr` is an indexed-access expression that reaches it (or None)."""
    if '$ref' in node:
        return pascal(node['$ref'].split('/')[-1])
    if 'enum' in node or 'x-known-values' in node:
        name = union(node, ptr)
        if expr is not None:
            PROBES.append((expr, name))
        else:
            UNPROBED.append(ptr)
        nul = ' | null' if (isinstance(node.get('type'), list) and 'null' in node['type']) or None in (node.get('enum') or []) else ''
        return 'K.' + name + nul
    for kw in ('oneOf', 'anyOf'):
        if kw in node:
            rest = {k: v for k, v in node.items() if k not in (kw, 'description', 'allOf')}
            parts = []
            branches = node[kw]
            nullable_pair = len(branches) == 2 and {'type': 'null'} in branches
            for k, sub in (rest.get('properties') or {}).items():  # the node's own sets get their unions even where every branch narrows them
                if isinstance(sub, dict) and ('enum' in sub or 'x-known-values' in sub) and ptr + '/properties/' + k not in NAMES:
                    union(sub, ptr + '/properties/' + k)
                elif isinstance(sub, dict) and 'oneOf' in sub:
                    for j, b in enumerate(sub['oneOf']):
                        if isinstance(b, dict) and ('enum' in b or 'x-known-values' in b) and '%s/properties/%s/oneOf/%d' % (ptr, k, j) not in NAMES:
                            union(b, '%s/properties/%s/oneOf/%d' % (ptr, k, j))
            for i, b in enumerate(branches):
                b2 = merge(rest, b) if rest and '$ref' not in b else b
                sub = expr if nullable_pair and b != {'type': 'null'} and expr is not None else None
                if sub is not None:
                    sub = 'Exclude<%s, null>' % sub
                if b2 is not b:  # a property the branch does not restate keeps the node's own pointer
                    b2 = dict(b2, **{BASE: (ptr, set((b.get('properties') or {})))})
                parts.append(T(b2, '%s/%s/%d' % (ptr, kw, i), sub))
            return ' | '.join(dict.fromkeys(parts))
    t = node.get('type')
    if isinstance(t, list):
        return ' | '.join(T(dict(node, type=x), ptr, expr) for x in t)
    if t == 'null':
        return 'null'
    if t in ('integer', 'number'):
        return 'number'
    if t == 'string':
        return 'string'
    if t == 'boolean':
        return 'boolean'
    if t == 'array':
        it = node.get('items')
        if isinstance(it, dict):
            inner = T(it, ptr + '/items', None if expr is None else '%s[number]' % expr)
            return '(%s)[]' % inner if '|' in inner else inner + '[]'
        return 'unknown[]'
    if t == 'object' or 'properties' in node or 'patternProperties' in node:
        return obj(node, ptr, expr)
    return 'unknown'


def obj(node, ptr, expr):
    req = set(node.get('required') or [])
    base = node.get(BASE)
    lines = []
    for k, sub in (node.get('properties') or {}).items():
        e = None if expr is None else '%s[%s]' % (expr, lit(k))
        p = (base[0] if base and k not in base[1] else ptr) + '/properties/' + k
        lines.append('%s%s: %s;' % (lit(k) if not re.match(r'^[A-Za-z_$][A-Za-z0-9_$]*$', k) else k, '' if k in req else '?', T(sub, p, e)))
    idx = []
    for rx, sub in (node.get('patternProperties') or {}).items():
        idx.append(T(sub, ptr + '/patternProperties/' + rx, None))
    if isinstance(node.get('additionalProperties'), dict):
        idx.append(T(node['additionalProperties'], ptr + '/additionalProperties', None))
    if idx:
        lines.append('[key: string]: %s;' % ' | '.join(dict.fromkeys(idx)))
    return '{ ' + ' '.join(lines) + ' }'


out = ['// The TypeScript contract of lockrot report-2 (https://lockrot.dev/schema/report-2.json): the object shapes.',
       '// Prototype emitted by r3/schema/ts/emit_dts.py; every literal set is imported from lockrot-report-2.known.d.ts,',
       '// which is generated from the schema at build time. Relation rules (the schema\'s allOf groups) are not types.',
       "import type * as K from './lockrot-report-2.known';", '']
body = []
for name, node in D.items():
    tn = pascal(name)
    ty = T(node, '#/definitions/' + name, 'R.' + tn)
    body.append('export type %s = %s;' % (tn, ty))
root = T({k: v for k, v in S.items() if k not in ('definitions', 'allOf')}, '#', 'R.Report2')
body.append('export type Report2 = %s;' % root)
open(os.path.join(OUT, 'lockrot-report-2.d.ts'), 'w').write('\n'.join(out + body) + '\n')

known = ['// GENERATED from lockrot-report-2.schema.json by r3/schema/ts/emit_dts.py (tools/schema in lockrot): do not edit.',
         '// One literal union per enum (closed) and x-known-values (open: a later release may add a value) node.', '']
for k in KNOWN:
    lits = ' | '.join(lit(v) for v in k['values'])
    tail = (' | (number & {})' if k['integer'] else ' | (string & {})') if k['open'] else ''
    known.append('/** %s %s */\nexport type %s = %s%s;' % ('open set at' if k['open'] else 'closed set at', k['pointer'], k['name'], lits, tail))
open(os.path.join(OUT, 'lockrot-report-2.known.d.ts'), 'w').write('\n'.join(known) + '\n')
json.dump(KNOWN, open(os.path.join(OUT, 'known-map.json'), 'w'), indent=1)

probes = ["import type * as K from './lockrot-report-2.known';", "import type * as R from './lockrot-report-2';",
          '// GENERATED: each union pointer the shapes reach by indexed access must be exactly its generated union.',
          'type Equal<X, Y> = (<T>() => T extends X ? 1 : 2) extends (<T>() => T extends Y ? 1 : 2) ? true : false;', '']
for i, (e, name) in enumerate(PROBES):
    probes.append('export const p%d: Equal<Exclude<%s, null>, K.%s> = true;' % (i, e, name))
open(os.path.join(OUT, 'probes.generated.ts'), 'w').write('\n'.join(probes) + '\n')
probed_names = {n for _, n in PROBES}
print(json.dumps(dict(definitions=len(D), schema_nodes=len(NAMES), unions=len(KNOWN), closed=sum(not k['open'] for k in KNOWN), open=sum(k['open'] for k in KNOWN),
                      probes=len(PROBES), unions_probed=len(probed_names), unions_reached_only_inside_a_branch=len(KNOWN) - len(probed_names))))
