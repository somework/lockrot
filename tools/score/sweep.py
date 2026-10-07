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


def rows():
    live = [None, 'abandoned', 'silent', 'stale']
    one = [(sv, fx) for sv in M.SEVS for fx in M.FIXES]
    advsets = [()] + [(a,) for a in one] + list(itertools.combinations_with_replacement(one, 2))
    for lv, pn, lb, op, adv, d, dv in itertools.product(live, [0, 1], [0, 1], [0, 1], advsets, [True, False], [False, True]):
        fl = [x for x in (lv, 'pinned' if pn else None, 'left-behind' if lb else None, 'old-promise' if op else None) if x]
        if (not fl and not adv) or (pn and lb):
            continue
        advs = [dict(id='A%d' % i, sev=s, fix=fx) for i, (s, fx) in enumerate(adv)]
        for reach in (['direct'] if d else ['transitive', 'unreached']):
            for under in ([None, 'silent', 'stale'] if lv == 'abandoned' else [None]):
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
    if '--sha256' in ARGS:
        h, n = hashlib.sha256(), {}
        first = {}
        for r in rows():
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
        for r in rows():
            out.write(r + '\n')
