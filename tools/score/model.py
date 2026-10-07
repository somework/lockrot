"""Score model 1 as the sweep evaluates it: the engine, the score object, the one grammar and the grade gate.

tools/score/sweep.py imports it. It reads no file, and it imports only the Python standard library.
"""
from fractions import Fraction as Fr

ORDER = ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'vulnerable']
MAINT = ORDER[:6]
GRADES = ['critical', 'high', 'medium', 'low']
SEVS = ['critical', 'high', 'medium', 'unrated', 'low']
SEV_GATE = {'critical': 4, 'high': 3, 'medium': 2, 'unrated': 2, 'low': 1}
PTS = {'abandoned': 32, 'silent': 32, 'pinned': 16, 'left-behind': 16, 'old-promise': 16, 'stale': 8}
SEVPTS = {'critical': 32, 'high': 16, 'medium': 8, 'unrated': 8, 'low': 2}
DOUBLING = ('none', 'blocked')
FIXES = ['update', 'upgrade', 'raise-php', 'unknown', 'blocked', 'none']
BANDS = [('critical', 32), ('high', 16), ('medium', 8), ('low', 1)]
FLOOR = dict(BANDS)
RANK = {'critical': 4, 'high': 3, 'medium': 2, 'low': 1, None: 0}


def band(x):
    for n, c in BANDS:
        if x >= c:
            return n
    return None


def num(x):
    x = Fr(x)
    return int(x) if x.denominator == 1 else float(x)


def adv_points(a):
    return SEVPTS[a['sev']] * (2 if a['fix'] in DOUBLING else 1)


def engine(flags, advs, reach, dev, accepted=(), under=None):
    """reach: direct, transitive or unreached. under: the liveness word that S2 and S4 give on their own, which
    `abandoned` hides. accepted: the allowlist entry's whole accepted set, which every rerun applies."""
    E = _engine(flags, advs, reach, dev, accepted)
    E['under'] = under if 'abandoned' in flags else None
    E['accept_set'] = frozenset(accepted)
    return E


def _engine(flags, advs, reach, dev, accepted=()):
    m = [f for f in ORDER if f in MAINT and f in flags and f not in accepted]
    mterms = []
    for i, f in enumerate(m):
        div = 1 if i == 0 else 4
        mterms.append(dict(flag=f, weight=PTS[f], divisor=div, points=Fr(PTS[f], div), role='lead' if i == 0 else 'corroborating'))
    cadvs = list(advs)
    dec, tied = None, []
    if cadvs:
        srt = sorted(cadvs, key=lambda a: (-adv_points(a), SEVS.index(a['sev']), a['id']))
        dec = srt[0]
        tied = [a['id'] for a in srt[1:] if adv_points(a) == adv_points(dec)]
    mp = sum((t['points'] for t in mterms), Fr(0))
    sp = Fr(adv_points(dec)) if dec else Fr(0)
    r = Fr(1) if reach == 'direct' else Fr(1, 2)
    d = Fr(1, 2) if dev else Fr(1)
    exact = (mp * r + sp) * d
    return dict(mterms=mterms, cadvs=cadvs, dec=dec, tied=tied, mp=mp, sp=sp, r=r, d=d, exact=exact,
                total=int(exact), lead=m[0] if m else None, reach=reach, dev=dev, flags=list(flags),
                accepted=[f for f in ORDER if f in accepted and f in flags])


def zero_word(allowlisted, maint_judged):
    return 'finished' if allowlisted else ('unknown' if not maint_judged else 'ok')


def verdict_of(total, allowlisted, maint_judged):
    return band(total) if total > 0 else zero_word(allowlisted, maint_judged)


def band_obj(total, exact):
    v = band(total)
    if v is None:
        return None
    up = {'low': 'medium', 'medium': 'high', 'high': 'critical', 'critical': None}[v]
    return dict(floor=FLOOR[v], next=up, to_next=(FLOOR[up] - total) if up else None)


def decided_by(total, mc, sc):
    if total == 0:
        return None
    bt, bm, bs = band(total), band(int(mc)), band(int(sc))
    if bt == bm and bt == bs:
        return 'either'
    if bt == bm:
        return 'maintenance'
    if bt == bs:
        return 'security'
    return 'combination'


def terms_of(E):
    out = []
    for t in E['mterms']:
        out.append(dict(part='maintenance', flag=t['flag'], role=t['role'], weight=t['weight'], divisor=t['divisor'],
                        points=num(t['points']), contribution=num(t['points'] * E['r'] * E['d'])))
    if E['dec']:
        a = E['dec']
        mult = 2 if a['fix'] in DOUBLING else 1
        out.append(dict(part='security', flag='vulnerable', role='security', advisory=a['id'], severity=a['sev'], fix_kind=a['fix'],
                        weight=SEVPTS[a['sev']], multiplier=mult, points=num(E['sp']), contribution=num(E['sp'] * E['d'])))
    return out


def modifiers_of(E):
    mods = []
    if not E['mterms'] and not E['dec']:
        return mods
    if E['reach'] != 'direct':
        mods.append(dict(reason=E['reach'], applies_to='maintenance', divide_by=2,
                         before=num(E['mp']), after=num(E['mp'] * E['r'])))
    if E['dev']:
        b = E['mp'] * E['r'] + E['sp']
        mods.append(dict(reason='dev', applies_to='total', divide_by=2, before=num(b), after=num(b * E['d'])))
    return mods


def part_status(E, part, ctx):
    if part == 'maintenance':
        if E['mp'] > 0:
            return 'counted'
        if E['accepted']:
            return 'accepted'
        return 'none' if ctx['maint_judged'] else 'not_judged'
    if E['sp'] > 0:
        return 'counted'
    return {'complete': 'clear', 'partial': 'unchecked', 'not_run': 'unchecked', 'disabled': 'disabled'}[ctx.get('check', 'complete')]


def score_obj(E, ctx):
    """ctx: allowlisted, maint_judged, check, liveness_complete"""
    terms = terms_of(E)
    mc, sc = E['mp'] * E['r'] * E['d'], E['sp'] * E['d']
    total, exact = E['total'], E['exact']
    if not terms:
        s = dict(model=1, total=0, exact=0, accepted=accepted_of(E, ctx), text=text_php(E))
        return s
    s = dict(model=1, total=total, exact=num(exact), rounded_down=exact != total, band=band_obj(total, exact),
             decided_by=decided_by(total, mc, sc))
    s['parts'] = None if total == 0 else dict(
        maintenance=dict(status=part_status(E, 'maintenance', ctx), contribution=num(mc),
                         alone=dict(total=int(mc), verdict=band(int(mc)))),
        security=dict(status=part_status(E, 'security', ctx), contribution=num(sc),
                      alone=dict(total=int(sc), verdict=band(int(sc))), of=len(E['cadvs']), tied=E['tied']))
    s['terms'] = terms
    s['modifiers'] = modifiers_of(E)
    s['accepted'] = accepted_of(E, ctx)
    s['without'] = without_of(E, ctx)
    s['text'] = text_php(E)
    return s


def accepted_of(E, ctx):
    """`at_least` only on `stale` decided without S4 or with S3 unread: a lookup could add S4 (silent) or S3 (abandoned)."""
    acc = []
    for f in E['accepted']:
        E2 = engine(E['flags'], E['cadvs'], E['reach'], E['dev'], accepted=[x for x in E['accept_set'] if x != f], under=E['under'])
        role = next((t['role'] for t in E2['mterms'] if t['flag'] == f), None)
        acc.append(dict(flag=f, weight=PTS[f],
                        if_counted=dict(total=E2['total'], verdict=verdict_of(E2['total'], ctx['allowlisted'], ctx['maint_judged']), role=role,
                                        at_least=(f == 'stale' and (not ctx.get('liveness_complete', True) or bool(ctx.get('s3_unread')))),
                                        modifiers=modifiers_of(E2))))
    return acc


def without_flags(E, f):
    """the flag set with f's raising signals removed, derived again: removing `abandoned` (S1, S3) leaves S2 and S4,
    which give back the liveness word that abandoned hid (`revealed`)."""
    fl = [x for x in E['flags'] if x != f]
    revealed = []
    if f == 'abandoned' and E['under'] and E['under'] not in fl:
        fl.append(E['under'])
        revealed = [E['under']]
    fl.sort(key=ORDER.index)
    return fl, revealed


def revealed_of(E2, revealed):
    roles = {t['flag']: t['role'] for t in E2['mterms']}
    return [dict(flag=w, role=roles.get(w, 'accepted')) for w in revealed]


def without_of(E, ctx):
    """One row per counted flag when the finding has two or more terms, and one row for the deciding advisory whenever two
    or more advisories count. Every row is an engine rerun under the entry's whole accepted set, never a subtraction."""
    rows = []
    nterms = len(E['mterms']) + (1 if E['dec'] else 0)
    acc = E['accept_set']
    vw = lambda E2: verdict_of(E2['total'], ctx['allowlisted'], ctx['maint_judged'])
    if nterms >= 2:
        for t in E['mterms']:
            fl, revealed = without_flags(E, t['flag'])
            E2 = engine(fl, E['cadvs'], E['reach'], E['dev'], accepted=acc)
            rows.append(dict(remove=dict(kind='flag', id=t['flag']), revealed=revealed_of(E2, revealed), total=E2['total'], verdict=vw(E2),
                             lead=E2['lead'], deciding_advisory=E2['dec']['id'] if E2['dec'] else None,
                             at_least=((t['flag'] == 'abandoned' and not ctx.get('liveness_complete', True) and ctx.get('s2_level') in (None, 'high'))
                                       or (t['flag'] == 'pinned' and bool(ctx.get('s8_unread'))))))
        if E['dec']:
            E2 = engine(E['flags'], [], E['reach'], E['dev'], accepted=acc, under=E['under'])
            rows.append(dict(remove=dict(kind='flag', id='vulnerable'), revealed=[], total=E2['total'], verdict=vw(E2),
                             lead=E2['lead'], deciding_advisory=None, at_least=False))
    if len(E['cadvs']) >= 2:
        E2 = engine(E['flags'], [a for a in E['cadvs'] if a['id'] != E['dec']['id']], E['reach'], E['dev'], accepted=acc, under=E['under'])
        rows.append(dict(remove=dict(kind='advisory', id=E['dec']['id']), revealed=[], total=E2['total'], verdict=vw(E2),
                         lead=E2['lead'], deciding_advisory=E2['dec']['id'] if E2['dec'] else None, at_least=False))
    return rows


def fmt(x):
    x = Fr(x)
    return str(x.numerator) if x.denominator == 1 else ('%g' % float(x))


def text_php(E):
    """ScoreText, text grammar 1. The dev halving wraps the body in parentheses unless the body is one term: one
    maintenance term with no printed reach halving, or the security term alone."""
    parts = [f"{t['flag']} {fmt(t['points'])}" if t['role'] == 'lead' else f"{t['flag']} {fmt(t['points'])} [¼ of {t['weight']}]"
             for t in E['mterms']]
    m = ' + '.join(parts)
    reach_printed = bool(m) and E['reach'] != 'direct'
    if reach_printed:
        m = (f'({m})' if len(parts) > 1 else m) + f" ÷ 2 {E['reach']}"
    out = [m] if m else []
    if E['dec']:
        a = E['dec']
        if a['fix'] in DOUBLING:
            out.append(f"vulnerable {fmt(E['sp'])} [{a['sev']} advisory {SEVPTS[a['sev']]} × 2: no reachable fix]")
        else:
            out.append(f"vulnerable {fmt(E['sp'])} [{a['sev']} advisory]")
    line = ' + '.join(out)
    if E['dev'] and line:
        single = len(out) == 1 and (not m or (len(parts) == 1 and not reach_printed))
        line = f'{line} ÷ 2 dev' if single else f'({line}) ÷ 2 dev'
    rnd = '' if E['exact'] == E['total'] else f" ({fmt(E['exact'])}, rounded down)"
    acc = f" ({', '.join(E['accepted'])} accepted)" if E['accepted'] else ''
    return (f"{E['total']} = {line}{rnd}" if line else '0') + acc


def covered(flag, entry_first):
    return entry_first is not None and ORDER.index(entry_first) <= ORDER.index(flag)


def gate_basis(E, entry, ctx):
    """entry None: no baseline entry. entry = dict(first=<recorded lead or None>, acc_advs=set of known advisory ids).
    _unc and _ua are the uncovered counted maintenance flags and the advisories the baseline does not know."""
    cm = [f for f in E['flags'] if f in MAINT and f not in E['accepted']]
    if entry is None:
        return dict(score=E['total'], verdict=band(E['total']), cap=None, limited_by=None, new=None, _unc=cm, _ua=list(E['cadvs']), _cadvs=list(E['cadvs']))
    unc = [f for f in cm if not covered(f, entry['first'])]
    ua = [a for a in E['cadvs'] if a['id'] not in entry['acc_advs']]
    N = engine(unc, ua, E['reach'], E['dev'], accepted=())
    new = dict(total=N['total'], exact=num(N['exact']), rounded_down=N['exact'] != N['total'], terms=terms_of(N), modifiers=modifiers_of(N))
    cap = 2 * N['total']
    g = min(E['total'], cap)
    lim = 'cap' if N['total'] == 0 else ('equal' if E['total'] == cap else ('score' if E['total'] < cap else 'cap'))
    return dict(score=g, verdict=band(g), cap=cap, limited_by=lim, new=new, _N=N, _unc=unc, _ua=ua, _cadvs=list(E['cadvs']))


def grade_marks(G, s, value, has_entry):
    """The error records of a grade value T: the lead and the security term of gate.basis.new (the score's own terms
    without a baseline entry) whose contribution is at least half of T's floor without an entry, a quarter under one."""
    T = FLOOR[value]
    share = Fr(1, 4) if has_entry else Fr(1, 2)
    src = G['new']['terms'] if has_entry else s.get('terms', [])
    return [dict(kind='flag' if t['part'] == 'maintenance' else 'advisory', id=t['flag'] if t['part'] == 'maintenance' else t['advisory'])
            for t in src if t['role'] in ('lead', 'security') and Fr(t['contribution']).limit_denominator() >= T * share]


def gate_obj(G, s, gates, checks=None):
    """gates: run.gates as (value, kind), at most one per kind. fails reads the gate score, the uncovered flags and the
    unknown advisories. An unchecked value names the blocked signals once each as {kind: signal, id}."""
    has_entry = G['new'] is not None
    by, reaches = [], False
    cm = [t['flag'] for t in s.get('terms', []) if t['part'] == 'maintenance']
    for value, kind in gates:
        if kind == 'unchecked':
            if checks:
                reaches = True
                if not has_entry or G['new']['total'] > 0:
                    ids = []
                    for c in checks:
                        for b in c['blocks']:
                            if b not in ids:
                                ids.append(b)
                    by.append(dict(value=value, kind=kind, facts=[dict(kind='signal', id=b) for b in ids]))
            continue
        if kind == 'flag':
            lim = ORDER.index(value)
            if any(ORDER.index(f) <= lim for f in cm):
                reaches = True
            hit = [f for f in cm if f in G['_unc'] and ORDER.index(f) <= lim]
            if hit:
                by.append(dict(value=value, kind=kind, facts=[dict(kind='flag', id=f) for f in hit]))
            continue
        if kind == 'vulnerable':
            th = 1 if value == 'vulnerable' else SEV_GATE[value.split('-', 1)[1]]
            if s['total'] > 0 and any(SEV_GATE[a['sev']] >= th for a in G['_cadvs']):
                reaches = True
            hit = sorted((a for a in G['_ua'] if SEV_GATE[a['sev']] >= th), key=lambda a: (SEVS.index(a['sev']), a['id']))
            if hit:
                by.append(dict(value=value, kind=kind, facts=[dict(kind='advisory', id=a['id']) for a in hit]))
            continue
        if s['total'] > 0 and RANK[band(s['total'])] >= RANK[value]:
            reaches = True
        if G['score'] > 0 and RANK[band(G['score'])] >= RANK[value]:
            by.append(dict(value=value, kind=kind, facts=grade_marks(G, s, value, has_entry)))
    fails = bool(by)
    basis = None if not has_entry else dict(score=G['score'], verdict=G['verdict'], cap=G['cap'], limited_by=G['limited_by'], new=G['new'])
    return dict(reaches_fail_on=reaches, fails=fails, exempt_by=('baseline' if reaches and not fails and has_entry else None), by=by, basis=basis)
