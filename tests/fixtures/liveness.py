import json, urllib.request, concurrent.futures, datetime, re, subprocess, sys, os
today=datetime.datetime(2026,9,14,tzinfo=datetime.timezone.utc)
def yrs(s): return (today-datetime.datetime.fromisoformat(s.replace('Z','+00:00'))).days/365.25
def p2(name):
    try:
        d=json.load(urllib.request.urlopen(f'https://repo.packagist.org/p2/{name}.json',timeout=30))
    except Exception: return None
    vs=d['packages'].get(name,[]); cur={}; times=[]
    for v in vs:
        cur={**cur,**v}; cur={k:x for k,x in cur.items() if x!='__unset'}
        if 'dev' not in cur.get('version','').lower() and cur.get('time'): times.append(cur['time'])
    first=vs[0] if vs else {}
    return dict(abandoned=first.get('abandoned',False) not in (None,False), last=max(times) if times else None, src=(first.get('source') or {}).get('url',''))
def gh(src):
    m=re.search(r'github\.com[/:]([^/]+)/([^/.]+?)(?:\.git)?$',src)
    if not m: return None
    try:
        out=subprocess.run(['gh','api',f'repos/{m.group(1)}/{m.group(2)}','--jq','[.archived,.pushed_at]'],capture_output=True,text=True,timeout=30).stdout
        a,p=json.loads(out); return dict(archived=a,pushed=p)
    except Exception: return None
res={}
base=sys.argv[1]
for proj in sorted(os.listdir(base)):
    lp=os.path.join(base,proj,'composer.lock')
    if not os.path.isfile(lp): continue
    lock=json.load(open(lp)); names=[p['name'] for p in lock['packages']]
    with concurrent.futures.ThreadPoolExecutor(16) as ex: meta=dict(zip(names,ex.map(p2,names)))
    notp=[n for n,m in meta.items() if m is None]
    flag=[n for n,m in meta.items() if m and m['abandoned']]
    stale5=[n for n,m in meta.items() if m and not m['abandoned'] and m['last'] and yrs(m['last'])>=5]
    dead=[]
    for n in stale5:
        g=gh(meta[n]['src'])
        if g and (g['archived'] or yrs(g['pushed'])>=5): dead.append((meta[n]['last'][:10],(g['pushed'] or '')[:10],g['archived'],n))
    res[proj]=dict(prod=len(names),not_on_packagist=len(notp),flagged=len(flag),unflagged_no_release_5y=len(stale5),unflagged_no_release_no_push_5y=len(dead),dead=sorted(dead),flagged_list=flag)
    print(f"{proj}: prod={len(names)} flagged={len(flag)} noRelease5y={len(stale5)} noRelease+noPush5y={len(dead)} notOnPackagist={len(notp)}")
    for r in sorted(dead): print('   ',r)
json.dump(res,open(os.path.join(base,'summary.json'),'w'),indent=1)
