#!/usr/bin/env python3
"""The sweep row stream of score model 1, which the PHP sweep differential compares row by row.

tools/score/README.md gives the axes, the 14 fields of a row and the commands. Standard library only.
"""
import hashlib
import itertools
import os
import sys

# Safe-path mode (python3 -I or -P) leaves the script's directory off sys.path.
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import model as M  # noqa: E402

ARGS = sys.argv[1:]

CTX = dict(allowlisted=False, maint_judged=True, check='complete', liveness_complete=True)


def j(items):
    return ','.join(items) if items else '-'


def vv(x):
    return x if x else '-'


def row(axis, fl, adv, reach, dv, under, acc, E, s):
    G0 = M.gate_basis(E, None, CTX)
    gate = ''.join('1' if M.gate_obj(G0, s, [(g, 'grade')])['fails'] else '0' for g in M.GRADES)
    wo = [f"{w['remove']['id']}:{w['total']}:{vv(w['verdict'])}" + ('+' if w.get('at_least') else '') for w in s.get('without', [])]
    ic = [f"{a['flag']}:{a['if_counted']['total']}:{vv(a['if_counted']['verdict'])}" + ('+' if a['if_counted'].get('at_least') else '')
          for a in s.get('accepted', []) if a.get('if_counted')]
    ex = s['exact'] * 2
    assert ex == int(ex), (axis, fl, adv, s['exact'])
    return '|'.join([axis, j(list(fl)), j([f'{sv}:{fx}' for sv, fx in adv]), reach, '1' if dv else '0', under or '-', acc or '-',
                     str(int(ex)), str(s['total']), M.band(s['total']) if s['total'] else '-', vv(s.get('decided_by')), j(wo), j(ic), gate])


def gate_row(fl, adv, reach, dv, first, known, E, s):
    G = M.gate_basis(E, dict(first=first, acc_advs=set(known)), CTX)
    marks = []
    for value in M.GRADES:
        g = M.gate_obj(G, s, [(value, 'grade')])
        facts = [f"{x['kind']}:{x['id']}" for b in g['by'] for x in b['facts']]
        marks.append(j(facts) if g['fails'] and facts else ('!' if g['fails'] else '-'))
    ex = M.Fr(G['new']['exact']) * 2
    assert ex.denominator == 1, (fl, adv, first, known)
    return '|'.join(['gate', j(list(fl)), j([f'{sv}:{fx}' for sv, fx in adv]), reach, '1' if dv else '0', first or '-', j(list(known)),
                     str(s['total']), str(int(ex)), str(G['new']['total']), str(G['score']), G['limited_by']] + marks)


def combinations():
    """Every flag set, advisory set, reach and dev value of the base and unreached axes, in the sweep's order."""
    live = [None, 'abandoned', 'silent', 'stale']
    one = [(sv, fx) for sv in M.SEVS for fx in M.FIXES]
    advsets = [()] + [(a,) for a in one] + list(itertools.combinations_with_replacement(one, 2))
    for lv, pn, lb, op, adv, d, dv in itertools.product(live, [0, 1], [0, 1], [0, 1], advsets, [True, False], [False, True]):
        fl = [x for x in (lv, 'pinned' if pn else None, 'left-behind' if lb else None, 'old-promise' if op else None) if x]
        if (not fl and not adv) or (pn and lb):
            continue
        yield fl, adv, d, dv


def gate_rows():
    """One row per direct or transitive combination, recorded lead (none or a maintenance flag) and known advisory set."""
    for fl, adv, d, dv in combinations():
        advs = [dict(id='A%d' % i, sev=s, fix=fx) for i, (s, fx) in enumerate(adv)]
        ids = [a['id'] for a in advs]
        reach = 'direct' if d else 'transitive'
        E = M.engine(fl, advs, reach, dv)
        s = M.score_obj(E, CTX)
        for first in [None] + M.MAINT:
            for k in range(len(ids) + 1):
                for known in itertools.combinations(ids, k):
                    yield gate_row(fl, adv, reach, dv, first, known, E, s)


def rows():
    for fl, adv, d, dv in combinations():
        advs = [dict(id='A%d' % i, sev=s, fix=fx) for i, (s, fx) in enumerate(adv)]
        for reach in (['direct'] if d else ['transitive', 'unreached']):
            for under in ([None, 'silent', 'stale'] if fl and fl[0] == 'abandoned' else [None]):
                E = M.engine(fl, advs, reach, dv, under=under)
                s = M.score_obj(E, CTX)
                if under:
                    yield row('under', fl, adv, reach, dv, under, None, E, s)
                    E3 = M.engine(fl, advs, reach, dv, accepted=(under,), under=under)
                    yield row('under_entry', fl, adv, reach, dv, under, under, E3, M.score_obj(E3, CTX))
                    continue
                yield row('unreached' if reach == 'unreached' else 'base', fl, adv, reach, dv, None, None, E, s)
                if reach == 'unreached':
                    continue
                for a in fl:
                    E2 = M.engine(fl, advs, reach, dv, accepted=(a,))
                    yield row('accepted', fl, adv, reach, dv, None, a, E2, M.score_obj(E2, CTX))


if __name__ == '__main__':
    stream = gate_rows if ARGS[:2] == ['--axis', 'gate'] else rows
    if '--sample' in ARGS:
        for i, r in enumerate(stream()):
            if i % 1000 == 0:
                sys.stdout.write(r + '\n')
    elif '--sha256' in ARGS:
        h, n = hashlib.sha256(), {}
        first = {}
        for r in stream():
            h.update((r + '\n').encode())
            ax = r.split('|', 1)[0]
            n[ax] = n.get(ax, 0) + 1
            first.setdefault(ax, r)
        print('rows per axis:', n, 'total', sum(n.values()))
        print('sha256 of the uncompressed stream:', h.hexdigest())
        for ax, r in first.items():
            print('first', ax, 'row:', r)
    else:
        out = sys.stdout
        for r in stream():
            out.write(r + '\n')
