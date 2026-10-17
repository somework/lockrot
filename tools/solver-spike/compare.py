#!/usr/bin/env python3
"""Spike: compare verify.php results with dryrun.py results, per move and form.

Usage: python3 -I tools/solver-spike/compare.py <solver.json> <dry.json> [--quiet]
Prints one line per mismatch and a summary line as JSON.
"""
import json
import sys


def main():
    solver = json.load(open(sys.argv[1]))
    dry = json.load(open(sys.argv[2]))
    quiet = '--quiet' in sys.argv
    by_move = {m['package']: m for m in solver['moves']}
    summary = {'compared': 0, 'outcome_match': 0, 'target_match': 0, 'changes_match': 0, 'mismatches': []}
    for d in dry:
        if d['package'] == 'update_now':
            s = solver['update_now'][d['form']]
            name = None
        else:
            s = by_move[d['package']]['results'][d['form']]
            name = d['package']
        s_changes = s['changes'] if isinstance(s['changes'], dict) else {}
        summary['compared'] += 1
        outcome = s['ok'] == d['ok']
        target = outcome and (name is None or s_changes.get(name) == d['changes'].get(name))
        changes = outcome and s_changes == d['changes']
        summary['outcome_match'] += outcome
        summary['target_match'] += target
        summary['changes_match'] += changes
        if not target or not changes:
            diff = {k: (s_changes.get(k), d['changes'].get(k)) for k in sorted(set(s_changes) | set(d['changes']))
                    if s_changes.get(k) != d['changes'].get(k)}
            summary['mismatches'].append({'package': d['package'], 'form': d['form'], 'solver_ok': s['ok'],
                                          'composer_ok': d['ok'], 'target_match': target, 'diff': diff,
                                          'unloaded': s.get('unloaded', [])})
    if not quiet:
        for m in summary['mismatches']:
            print(json.dumps(m))
    summary['mismatch_count'] = len(summary.pop('mismatches'))
    print(json.dumps(summary))


main()
