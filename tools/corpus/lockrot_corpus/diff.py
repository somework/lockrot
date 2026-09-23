"""Two corpus runs compared, and — more importantly — refused when they are not comparable.

A diff between two archives is only evidence about the code while everything else is held still.
The shared Composer cache is what holds it still, and it holds for one day: lockrot keeps an
activity answer for 86400 seconds from its own fetched_at, after which individual packages quietly
refetch live and the diff files an upstream change as a regression. The clock moves verdicts at the
three- and five-year boundaries on its own. And a run with a token and a run without one differ
across the whole corpus by construction.

So this refuses, loudly, rather than comparing anything it is handed. That refusal is the feature;
the comparison is the easy part.

What it compares is deliberately narrow — verdict, priority, the set of signal ids, each signal's
summary sentence, and libyears — and the libyears change is classified three ways, because
measured-becoming-null is a different event from a number moving, and this project has already
shipped a regression that was only visible as the former.
"""

import datetime
import os

from .jsonio import read_json
from .runner import load_manifest

# lockrot's ActivityClient keeps a repository answer for a day (src/Data/Forge/ActivityClient.php).
# Two runs further apart than that were not reading the same upstream.
ACTIVITY_TTL_SECONDS = 86400


class NotComparable(Exception):
    """The two runs do not hold anything still between them. Exit 2, never a reported difference."""


def compare(root_a: str, root_b: str, corpus_digest: 'str | None' = None) -> dict:
    manifest_a, manifest_b = load_manifest(root_a), load_manifest(root_b)
    _assert_comparable(manifest_a, manifest_b, root_a, root_b, corpus_digest)

    changes, rows, missing = {}, [], []
    # The union of what both runs were meant to cover, not a listing of one side: enumerating only
    # B makes a project that died in B invisible — no file to list, so neither compared nor missed —
    # which is the same silence in the differ that load.py had in the checks.
    for project in _projects(root_a, root_b, manifest_a, manifest_b):
        entry = project + '.json'
        path_a, path_b = os.path.join(root_a, entry), os.path.join(root_b, entry)
        if not _readable(path_a) or not _readable(path_b):
            missing.append(project)
            continue
        old, new = read_json(path_a), read_json(path_b)
        before = {item['package']: item for item in old.get('findings', [])}
        after = {item['package']: item for item in new.get('findings', [])}
        for package in sorted(set(before) | set(after)):
            left, right = before.get(package), after.get(package)
            if left is None or right is None:
                side = os.path.basename(root_a) if right is None else os.path.basename(root_b)
                _bump(changes, 'package only in ' + side)
                rows.append('%s %s only in %s' % (project, package, side))
                continue
            _compare_finding(project, package, left, right, changes, rows)

    return {'changes': changes, 'rows': rows, 'missing': missing,
            'a': manifest_a, 'b': manifest_b}


def _projects(root_a: str, root_b: str, manifest_a: dict, manifest_b: dict) -> 'list[str]':
    names = set(manifest_a.get('targets') or {}) | set(manifest_b.get('targets') or {})
    for root in (root_a, root_b):
        names |= {entry[:-len('.json')] for entry in os.listdir(root)
                  if entry.endswith('.json') and entry != 'run.json'}

    return sorted(names)


def _readable(path: str) -> bool:
    return os.path.exists(path) and os.path.getsize(path) > 0


def _compare_finding(project: str, package: str, left: dict, right: dict,
                     changes: 'dict[str, int]', rows: 'list[str]') -> None:
    if left.get('verdict') != right.get('verdict'):
        _bump(changes, 'verdict %s -> %s' % (left.get('verdict'), right.get('verdict')))
        rows.append('%s %s verdict %s -> %s' % (project, package, left.get('verdict'), right.get('verdict')))
    if left.get('priority') != right.get('priority'):
        _bump(changes, 'priority %s -> %s' % (left.get('priority'), right.get('priority')))
        rows.append('%s %s priority %s -> %s' % (project, package, left.get('priority'), right.get('priority')))

    before = {signal['id']: signal for signal in left.get('signals', [])}
    after = {signal['id']: signal for signal in right.get('signals', [])}
    for ident in sorted(set(before) | set(after)):
        if ident not in before:
            _bump(changes, 'signal added ' + ident)
            rows.append('%s %s +%s %s' % (project, package, ident, after[ident].get('summary', '')[:110]))
        elif ident not in after:
            _bump(changes, 'signal dropped ' + ident)
            rows.append('%s %s -%s %s' % (project, package, ident, before[ident].get('summary', '')[:110]))
        elif before[ident].get('summary') != after[ident].get('summary'):
            _bump(changes, 'signal reworded ' + ident)
            rows.append('%s %s ~%s\n    %s\n    %s'
                        % (project, package, ident, before[ident].get('summary', '')[:140],
                           after[ident].get('summary', '')[:140]))

    old_years, new_years = left.get('libyears'), right.get('libyears')
    if old_years != new_years:
        if new_years is None:
            kind = 'libyears measured -> unmeasured'
        elif old_years is None:
            kind = 'libyears unmeasured -> measured'
        else:
            kind = 'libyears value'
        _bump(changes, kind)
        rows.append('%s %s libyears %s -> %s' % (project, package, old_years, new_years))


def _bump(counter: 'dict[str, int]', key: str) -> None:
    counter[key] = counter.get(key, 0) + 1


def _assert_comparable(manifest_a: 'dict | None', manifest_b: 'dict | None', root_a: str,
                       root_b: str, corpus_digest: 'str | None') -> None:
    if manifest_a is None or manifest_b is None:
        raise NotComparable(
            'one of these directories has no run.json, so nothing records which archive wrote it, '
            'on what day, with or without a token. A comparison of two anonymous piles of JSON is '
            'not evidence about anything.')
    for field, label in (('today', 'the pinned day'),
                         ('token_mode', 'the token mode'),
                         ('cache_root', 'the Composer cache')):
        if manifest_a.get(field) != manifest_b.get(field):
            raise NotComparable(
                '%s differs: %s has %s and %s has %s. Held apart, this difference would be filed '
                'against the code.' % (label, os.path.basename(root_a), manifest_a.get(field),
                                       os.path.basename(root_b), manifest_b.get(field)))
    if manifest_a.get('phar_sha256') == manifest_b.get('phar_sha256'):
        raise NotComparable('both runs used the same archive, so there is nothing to compare')
    _assert_close_enough_in_time(manifest_a, manifest_b, root_a, root_b)
    if corpus_digest is not None:
        for manifest, root in ((manifest_a, root_a), (manifest_b, root_b)):
            recorded = manifest.get('corpus_digest')
            if recorded is not None and recorded != corpus_digest:
                raise NotComparable(
                    '%s ran over corpus %s and the manifest now pins %s'
                    % (os.path.basename(root), recorded, corpus_digest))


def _assert_close_enough_in_time(manifest_a: dict, manifest_b: dict, root_a: str,
                                 root_b: str) -> None:
    """Two runs read the same upstream only while they are inside one activity-cache lifetime.

    lockrot keeps a repository answer for ACTIVITY_TTL_SECONDS from that answer's own fetched_at,
    after which individual packages quietly refetch live — so the further apart the two halves ran,
    the more of the difference is the calendar rather than the code. Missing timestamps are not an
    excuse: a run recorded by an older version of this tool cannot be shown to be comparable, and
    this refuses rather than assuming.
    """
    stamps = []
    for manifest, root in ((manifest_a, root_a), (manifest_b, root_b)):
        moments = [_moment(manifest.get(field)) for field in ('started', 'finished')]
        moments = [moment for moment in moments if moment is not None]
        if not moments:
            raise NotComparable(
                '%s records no start or finish time, so there is no way to tell whether the two '
                'runs read the same upstream. lockrot keeps a repository answer for %d seconds.'
                % (os.path.basename(root), ACTIVITY_TTL_SECONDS))
        stamps += moments
    spread = (max(stamps) - min(stamps)).total_seconds()
    if spread > ACTIVITY_TTL_SECONDS:
        raise NotComparable(
            'these runs are %.1f hours apart and lockrot keeps a repository answer for %.1f, so '
            'part of the second run read upstream afresh. Re-run them together.'
            % (spread / 3600.0, ACTIVITY_TTL_SECONDS / 3600.0))


def _moment(value: object) -> 'datetime.datetime | None':
    if not value:
        return None
    try:
        return datetime.datetime.fromisoformat(str(value))
    except ValueError:
        return None


def render(result: dict, root_a: str, root_b: str) -> str:
    lines = ['=== %s -> %s ===' % (os.path.basename(root_a), os.path.basename(root_b))]
    lines.append('%s %s  ->  %s %s'
                 % (result['a'].get('lockrot_version'), (result['a'].get('phar_sha256') or '')[:12],
                    result['b'].get('lockrot_version'), (result['b'].get('phar_sha256') or '')[:12]))
    lines.append('day %s, %s, cache %s'
                 % (result['a'].get('today'), result['a'].get('token_mode'), result['a'].get('cache_root')))
    lines.append('')
    if result['missing']:
        lines.append('!! %d project(s) unreadable on one side and not compared: %s'
                     % (len(result['missing']), ', '.join(result['missing'])))
        lines.append('')
    if not result['changes']:
        lines.append('no verdict, priority, signal or libyears change')
    else:
        for key in sorted(result['changes']):
            lines.append('%-40s %d' % (key, result['changes'][key]))
    lines.append('')
    lines.append('--- rows (%d) ---' % len(result['rows']))
    for row in result['rows'][:80]:
        lines.append('  ' + row)
    if len(result['rows']) > 80:
        lines.append('  … %d more' % (len(result['rows']) - 80))

    return '\n'.join(lines) + '\n'
