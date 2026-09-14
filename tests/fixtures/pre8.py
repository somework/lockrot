import json, glob, re, sys
def vt(s):
    p=[int(x) for x in re.findall(r'\d+',s)[:3]]; return tuple(p+[0]*(3-len(p)))
def one(c,v):
    c=c.strip()
    if c in ('*',''): return True
    m=re.match(r'^(>=|<=|>|<|!=|\^|~|==|=)?\s*v?([\d.*]+)',c)
    if not m: return None
    op,ver=m.group(1) or '=',m.group(2)
    if '*' in ver: pre=[int(x) for x in ver.split('.') if x!='*']; return list(v[:len(pre)])==pre
    t=vt(ver); n=len(re.findall(r'\d+',ver))
    if op=='>=': return v>=t
    if op=='>': return v>t
    if op=='<=': return v<=t
    if op=='<': return v<t
    if op=='!=': return v!=t
    if op=='^': return t<=v<((t[0]+1,0,0) if t[0]>0 else (0,t[1]+1,0))
    if op=='~': return t<=v<((t[0]+1,0,0) if n<=2 else (t[0],t[1]+1,0))
    return v[:n]==t[:n]
def allows(e,v=(8,4,0)):
    r=False
    for alt in re.split(r'\|\|?',e):
        ps=[p for p in re.split(r'[,\s]+',alt.strip()) if p]; mg=[];i=0
        while i<len(ps):
            if ps[i] in ('>=','<=','>','<','^','~','!=') and i+1<len(ps): mg.append(ps[i]+ps[i+1]); i+=2
            else: mg.append(ps[i]); i+=1
        ok=True
        for p in mg:
            x=one(p,v)
            if x is None: return None
            ok=ok and x
        r=r or ok
    return r
uniq={}; per={}
for base in sys.argv[2:]:
    for lp in sorted(glob.glob(base+'/*/composer.lock')):
        app=lp.split('/')[-2]; lock=json.load(open(lp)); c=0
        for p in lock['packages']:
            php=p.get('require',{}).get('php'); t=(p.get('time') or '')[:10]
            if php and t and t<'2020-11-26' and allows(php):
                c+=1; uniq.setdefault((p['name'],p['version']),dict(name=p['name'],version=p['version'],time=t,php=php,dist=(p.get('dist') or {}).get('url'),apps=[]))['apps'].append(app)
        per[app]=(len(lock['packages']),c)
json.dump(dict(per=per,uniq=list(uniq.values())),open(sys.argv[1],'w'),indent=1)
for a,(n,c) in sorted(per.items(),key=lambda x:-x[1][1]): print(f'{a}: prod={n} pre8_claims_8.4={c}')
print('unique',len(uniq),'apps with >=1:',sum(1 for n,c in per.values() if c))
