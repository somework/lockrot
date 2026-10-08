#!/usr/bin/env python3
"""Builds lockrot-explain-2, lockrot-baseline-2 and lockrot-config-2 (draft-04) from SPEC-flags-r3.md.

The report-1/explain-1/baseline-1/config-1 files in the lockrot worktree are read (never written) so that every
definition the 0.14 documents keep unchanged keeps its published wording; everything report-2 adds is written here,
one description per property, from the spec sections named in each description.

Writes only beside this file: lockrot-explain-2.schema.json, lockrot-baseline-2.schema.json, lockrot-config-2.schema.json.
"""
import copy
import json
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.normpath(os.path.join(HERE, '..', '..'))
RESOURCES = os.path.join(REPO, 'resources')
REPORT2 = os.path.join(RESOURCES, 'lockrot-report-2.schema.json')
# attack round 3 (9.I5): the 0.13.0 -1 files from committed, hash-pinned copies (inputs/, tools/schema/inputs/ in the repository), read
# relative to this script; a later widening of a -1 file never changes a -2 rebuild
import hashlib
INPUTS = os.path.join(HERE, 'inputs')
PINNED = json.load(open(os.path.join(INPUTS, 'SHA256SUMS.json')))


def pinned(name):
    raw = open(os.path.join(INPUTS, name), 'rb').read()
    assert hashlib.sha256(raw).hexdigest() == PINNED[name], ('a pinned input changed', name)
    return json.loads(raw)


R1 = pinned('lockrot-report.schema.json')
E1 = pinned('lockrot-explain.schema.json')
D1 = R1['definitions']
DRAFT = 'http://json-schema.org/draft-04/schema#'

# ------------------------------------------------------------------------------------------------ vocabularies (§2.1, §5.2, §8.2)
FLAGS = ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'vulnerable']
MAINT = FLAGS[:6]
VERDICTS = ['critical', 'high', 'medium', 'low', 'unknown', 'finished', 'ok']
GRADES = VERDICTS[:4]
ZERO_WORDS = ['unknown', 'finished', 'ok']
SEVERITIES = ['critical', 'high', 'medium', 'unrated', 'low']
FIX_KINDS = ['update', 'upgrade', 'raise-php', 'unknown', 'blocked', 'none']
MOVE_KINDS = ['replace', 'find-alternative', 'tag', 'require', 'update', 'raise-php', 'test', 'blocked', 'no-tag', 'no-move', 'no-fix', 'no-single-fix']
V013_VERDICTS = ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'unknown', 'finished', 'ok']
PRIORITIES = ['critical', 'high', 'medium', 'low', 'none']
REASON = '^[a-z][a-z0-9_]*$'           # reason-like open values (underscores), the repo's style
FLAGLIKE = '^[a-z][a-z0-9-]*$'         # flag-like open values keep hyphens (raise-php), §8.2
SIGNAL_IDS = ['S%d' % i for i in range(1, 11)]
PROSE = 'prose, not contract; key on the fields'


# ------------------------------------------------------------------------------------------------ helpers
def ref(name, desc=None):
    out = {}
    if desc:
        out['description'] = desc
    out['$ref'] = '#/definitions/' + name
    return out


def nullable(inner, desc):
    """`inner` or null; desc says what null means."""
    return {'description': desc, 'oneOf': [inner, {'type': 'null'}]}


def obj(desc, props, required=None, **extra):
    out = {'description': desc, 'type': 'object', 'required': list(props) if required is None else required, 'properties': props}
    out.update(extra)
    return out


def string(desc, **kw):
    out = {'description': desc, 'type': 'string'}
    out.update(kw)
    return out


def integer(desc, minimum=None, **kw):
    out = {'description': desc, 'type': 'integer'}
    if minimum is not None:
        out['minimum'] = minimum
    out.update(kw)
    return out


def boolean(desc):
    return {'description': desc, 'type': 'boolean'}


def half(desc, minimum=0):
    """a number in half points (§8.2: exact, contribution, before, after carry multipleOf 0.5)."""
    out = {'description': desc, 'type': 'number', 'multipleOf': 0.5}
    if minimum is not None:
        out['minimum'] = minimum
    return out


def openset(desc, known, pattern=REASON):
    return {'description': desc, 'type': 'string', 'pattern': pattern, 'x-known-values': list(known)}


def closed(desc, values):
    return {'description': desc, 'type': 'string', 'enum': list(values)}


def array(desc, items, **kw):
    out = {'description': desc, 'type': 'array', 'items': items}
    out.update(kw)
    return out


def rendered(desc, paths):
    """a human string (§7.11): a rendering of the listed fields of the same object or of `run`."""
    return {'description': desc + ' ' + PROSE[0].upper() + PROSE[1:] + '.', 'type': 'string', 'x-rendered-from': paths}


OPTIONAL_RE = [
    re.compile(r'\s*Optional: (?:documents|reports) written before [0-9.]+ do not carry it(?:; from [0-9.]+ on (?:it is always written|every [a-z ]+ does|every finding carries it))?\.'),
    re.compile(r'\s*Optional: documents written before [0-9.]+ do not carry it, and there `notes` is untyped text; from [0-9.]+ on it is always written, `\[\]` when `notes` is\.'),
    re.compile(r'\s*Optional, as `[a-z_]+` is\.'),
    re.compile(r'\s*Absent (?:from documents written )?before [0-9.]+\.'),
    re.compile(r'\s*Absent from documents written before [0-9.]+; from [0-9.]+ on every finding carries it\.'),
    re.compile(r'\s*Optional, because reports written before [0-9.]+ do not carry it\.'),
]


def strip_optional(node):
    """report-1 wording kept, minus the "documents written before 0.x do not carry it" caveats: every key of a
    -2 document is always written, so the -2 schemas require it."""
    if isinstance(node, dict):
        out = {}
        for k, v in node.items():
            if k == 'description' and isinstance(v, str):
                for rx in OPTIONAL_RE:
                    v = rx.sub('', v)
                out[k] = v.strip()
            else:
                out[k] = strip_optional(v)
        return out
    if isinstance(node, list):
        return [strip_optional(x) for x in node]
    return node


def kept(name):
    return strip_optional(copy.deepcopy(D1[name]))


# ------------------------------------------------------------------------------------------------ shared definitions
def shared_definitions():
    D = {}
    D['dateTime'] = {'description': 'An ISO 8601 date and time with an offset, as `DATE_ATOM` writes it.', 'type': 'string', 'format': 'date-time'}
    D['date'] = {'description': 'A calendar date, `YYYY-MM-DD`.', 'type': 'string', 'pattern': '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'}
    D['packageName'] = kept('packageName')
    D['years'] = {'description': 'Years on the run clock (`Clock::yearsSince` against the document\'s `generated_at`), integer tenths: a JSON number '
                                 'with at most one decimal, written as `json_encode` writes it under `serialize_precision=-1` (4.0 is written `4`). '
                                 'A reader prints it with one decimal; a published 0.0 reads "less than three weeks ago" (§2.1).',
                  'type': 'number', 'minimum': 0, 'multipleOf': 0.1}
    D['datedBy'] = kept('datedBy')
    D['signalId'] = kept('signalId')
    D['level'] = {'description': 'The signal\'s degree as 0.13 writes it: `info`, `warn` or `high`. Kept for consumers; pills and text show years, '
                                 'never this word (§2.1).', 'type': 'string', 'enum': ['info', 'warn', 'high']}
    D['flagId'] = closed('A flag id, in the frozen flag order used by lists, sums, pills, gates and baseline coverage (§2.1). A closed set: '
                         'changing it is a report-3 event.', FLAGS)
    D['maintenanceFlag'] = closed('One of the six maintenance flags, in flag order: every flag but `vulnerable` (§2.1). `lead`, an allowlist\'s '
                                  '`flag_ids` and a baseline\'s stored flags take only these.', MAINT)
    D['verdict'] = closed('The verdict: a grade (`critical high medium low`, from the score\'s band) or, at score 0, the word that says why '
                          'nothing counts (`unknown`: no repository metadata; `finished`: an allowlist entry applies; `ok`: otherwise). Closed (§4.1).', VERDICTS)
    D['grade'] = closed('A graded verdict, worst first: `critical` ≥ 32, `high` ≥ 16, `medium` ≥ 8, `low` ≥ 1 under score model 1 '
                        '(`run.score_model.bands[]` holds the floors).', GRADES)
    D['severity'] = closed('An advisory\'s severity bucket, in the one order used everywhere: `critical high medium unrated low` (§5.2). '
                           '`moderate` is bucketed as `medium`; a missing or unknown severity is `unrated`, which counts as medium. '
                           'Comparisons of severity use `run.score_model.severities[].gate_rank`, never this order. Closed.', SEVERITIES)
    D['fixKind'] = openset('A fix kind (§5.3–§5.4): the class of the easiest release that fixes an advisory for this project. `update` (composer '
                           'update reaches it), `upgrade` (a requirement or conflict holds it back), `raise-php` (the target runs it, the '
                           'project\'s require.php does not admit it), `unknown` (releases or the affected range could not be read), '
                           '`blocked` (no fixing release runs on the target PHP), `none` (no release fixes it). `blocked` and `none` double an '
                           'advisory\'s points. An open set (§8.2): a fix-model change can add a kind, and it arrives with its row in '
                           '`run.score_model.fix_kinds[]`, so read its `ease` and `doubles` there and show its id.', FIX_KINDS, FLAGLIKE)
    D['priority'] = closed('A 0.13 priority, `none` included.', PRIORITIES)
    D['flaggedPriority'] = kept('flaggedPriority')
    D['priorityStep'] = kept('priorityStep')
    D['legacy'] = nullable(obj('0.13\'s reading of the finding.', {
        'verdict': closed('0.13\'s verdict word: the first raised flag in 0.13\'s order, or `unknown`, `finished`, `ok`.', V013_VERDICTS),
        'priority': ref('priority', '0.13\'s priority.'),
        'basis': obj('How 0.13 reached `priority`: report-1\'s `priority_basis`.', {
            'base': ref('priority', 'The priority 0.13\'s verdict starts from.'),
            'steps': array('Each step 0.13 took from `base`, in order.', ref('priorityStep')),
        }),
    }), '0.13\'s verdict and priority for this finding, computed by a frozen copy of 0.13\'s rules (`Legacy\\Priority013`): the `0.13:` line of '
        '`--explain` and the `verdict_rules_changed` note read it. Null only in a document written after report-3 retires the frozen rules (§6.2, §7.7).')
    D['factRef'] = obj('A counted fact: a maintenance flag or an advisory.', {
        'kind': closed('`flag` or `advisory`.', ['flag', 'advisory']),
        'id': string('A flag id when `kind` is `flag`; the advisory\'s id (an S9 row\'s `id`) when `kind` is `advisory`.', minLength=1),
    })
    D['factClear'] = obj('A counted fact a move clears, and what lockrot checked to say so (§5.6). lockrot names only what it checked for every '
                         'version the emitted constraint admits on that branch, or, for `update`, for the one release `composer update` installs.', {
        'kind': closed('`flag` or `advisory`.', ['flag', 'advisory']),
        'id': string('The flag id or the advisory id.', minLength=1),
        'basis': openset('What was checked: `branch_releasing` (left-behind: the branch released within release-warn-years), '
                         '`released_after_ga` (old-promise: the lowest admitted release came out after the target major\'s GA), '
                         '`outside_range` (an advisory: no admitted version is in its affected range), `tags_only` (pinned: the '
                         'constraint admits only tags). An open set: read an unknown basis as "checked another way".',
                         ['branch_releasing', 'released_after_ga', 'outside_range', 'tags_only']),
    })
    D['phpCheck'] = obj('The php facts of a move or of `security.gets` (§5.3): what the release needs, and whether the project\'s require.php and the '
                        'target admit it. Every sentence prints its php clause only when `requires` is set.', {
        'requires': nullable({'type': 'string', 'minLength': 1}, 'The release\'s own php requirement, as the repository lists it. Null: the release '
                                                               'declares no php constraint (round 4).'),
        'project_allows': nullable({'type': 'boolean'}, 'Whether the project\'s require.php admits `requires` (its lowest PHP, as '
                                                       '`run.project_php_lowest` reads it). Null: lockrot could not compare (no require.php, or '
                                                       '`requires` null), never "does not allow".'),
        'target_runs': nullable({'type': 'boolean'}, 'Whether the target PHP (`run.target_php`) runs the release. Null: no php declared to compare.'),
        'raise_to': nullable({'type': 'string', 'minLength': 1}, 'The require.php value to write (`>=8.1`) when the project floor does not admit the '
                                                               'release; null when it already does.'),
        'raise_size': nullable(closed('How far require.php must move.', ['major', 'minor', 'patch']),
                               'Closed `major minor patch`; null when the project floor already admits the release.'),
    })
    D['holder'] = obj('The holder\'s own finding in this report, for display only: no score reads it (§5.3).', {
        'verdict': ref('verdict', 'The holder\'s verdict.'),
        'lead': nullable(ref('maintenanceFlag'), 'The holder\'s `lead`; null when no maintenance flag counts on it (a holder graded by its advisories alone).'),
        'flag_ids': array('The holder\'s counted flags, in flag order.', ref('flagId'), uniqueItems=True),
        'replacement': nullable(ref('packageName'), 'The holder\'s `replacement` (a Composer package that replaces an abandoned holder); null otherwise.'),
        'next_step': nullable(obj('The holder\'s own move, so a sentence can say what releases the hold ("its own move, require ^4.4.51").', {
            'kind': openset('The holder\'s `next_step.kind`.', MOVE_KINDS, FLAGLIKE),
            'to_branch': nullable({'type': 'string'}, 'The holder\'s `next_step.to_branch`.'),
            'constraint': nullable({'type': 'string'}, 'The holder\'s `next_step.constraint`.'),
        }), 'The holder\'s move; null when the holder scores 0 and has none.'),
    })
    D['heldBy'] = obj('One link that excludes the move\'s release (§5.3): a root requirement or another locked package\'s requirement or conflict.', {
        'source': closed('`root` (composer.json) or `package` (a locked package).', ['root', 'package']),
        'package': nullable(ref('packageName'), 'The locked package that holds it; null for `root`.'),
        'version': nullable({'type': 'string'}, 'That package\'s locked version; null for `root`.'),
        'link': closed('The kind of link: `require`, `require-dev` or `conflict`.', ['require', 'require-dev', 'conflict']),
        'constraint': string('The constraint as written in the link (`^2.0`, `^1.41|^2.10`); quoted, never interpreted.', minLength=1),
        'holder': nullable(ref('holder'), 'The holder\'s own finding in this report; null for the root, or a holder that is not a finding.'),
    })
    # explain-2 copies report-2's branchFixes (explain_schema), so no second definition lives here
    return D


# attack round 1: the finding, signal, note and run definitions explain-2 built here (a second copy of report-2's, which
# drifted from it in 189 places) are gone: explain_schema() copies report-2's own. This file keeps the relation groups
# (add_relations, applied to report-2 through a name map), the explain-only definitions, baseline-2 and config-2.


def explain_metadata():
    rows = strip_optional(copy.deepcopy(E1['definitions']['metadata']))
    row = rows['properties']['branches']['items']
    row['description'] = ('One release branch, highest first (the details block, §7.6). Every key but the last two is 0.13\'s; find the installed row by '
                          '`installed: true`, never by name.')
    row['properties']['branch']['description'] = 'The branch label as `ReleaseBranch::label()` writes it (`2.x`, `0.15.x`, `0.0.3`), the same label as `finding.branch`.'
    row['properties']['php'] = nullable({'type': 'string'}, 'The requirement of the branch\'s newest dated release, as the repository lists it; null when it '
                                                           'declares none (the Release branches line then leaves its php clause out).')
    row['properties']['released_years'] = nullable(ref('years'), 'The age of the branch\'s newest release on the run clock: `highest_released`, or, when '
                                                                 'that is null, S8\'s `newest_release` for the same branch; null when neither dates it.')
    row['properties']['fixes'] = nullable(ref('branchFixes'), 'What the branch fixes of the counted advisories; null when the package has no counted advisory.')
    row['required'] = list(row['properties'])
    rows['properties']['installed_branch'] = nullable({'type': 'string', 'minLength': 1}, 'The installed row\'s label (`finding.branch`); null for a snapshot.')
    rows['required'] = list(rows['properties'])
    rows['description'] = 'What the Composer repository said about the package, with every branch row the explain text\'s table prints.'
    for k in ['abandoned', 'replacement', 'releases_listed', 'has_stable_release', 'last_stable_release', 'last_stable_version', 'type', 'data_date']:
        rows['properties'][k].setdefault('description', {
            'abandoned': 'Whether the registry marks it abandoned.', 'replacement': 'The registry\'s replacement, as written; null when none.',
            'releases_listed': 'How many releases the registry lists.', 'has_stable_release': 'Whether it lists a tagged release.',
            'last_stable_release': 'The newest dated stable release; null when none.', 'last_stable_version': 'Its version; null exactly when it is.',
            'type': 'The package type the registry lists.', 'data_date': 'When this metadata was fetched or cached.'}[k])
    for k, v in row['properties'].items():
        v.setdefault('description', {'installed': 'Whether the installed version is on this branch.', 'newest_dated': 'The branch\'s newest dated stable release.',
                                     'newest_dated_released': 'Its date; null when undated.'}.get(k, k))
    return rows


def explain_activity():
    """explain-1's `activity` block, every member described."""
    act = copy.deepcopy(E1['definitions']['activity'])
    act['description'] = 'What the repository host answered for the package.'
    words = {'forge': 'The host, as a display label (`GitHub`, `GitLab`, `Bitbucket`).',
             'repository': 'The repository path on the host (`owner/repo`).',
             'archived': 'Whether the host marks the repository archived.',
             'pushed_at': 'The last push or commit the host reported; null when it reported none.',
             'fetched_at': 'When lockrot fetched the answer; null when the answer carries no fetch time.',
             'from_cache': "Whether the answer came from lockrot's cache."}
    act['properties'] = {k: dict({'description': words[k]}, **v) for k, v in act['properties'].items()}
    return act


# ------------------------------------------------------------------------------------------------ the field reference's relations (§7.6, I14, I18, I21)
def P(**props):
    """a branch that only constrains properties (no `type`: the strict twin leaves it as it is)."""
    return {'properties': props}


def E(*values):
    return {'enum': list(values)}


def NOT(*values):
    return {'not': {'enum': list(values)}}


NULL = {'type': 'null'}
STR = {'type': 'string'}
OBJ = {'type': 'object'}
MIN1 = {'minItems': 1}
EMPTY = {'maxItems': 0}
NO_COMMAND = ['replace', 'find-alternative', 'test', 'blocked', 'no-tag', 'no-move', 'no-fix', 'no-single-fix']
PHP_CHECKED = ['update', 'require', 'raise-php', 'blocked', 'tag']


def inner(node):
    """the object of a nullable definition."""
    return node['oneOf'][0] if 'oneOf' in node else node


def relate(node, desc, *branches_lists):
    """one allOf entry per branch list; `desc` is one description per entry (a list), or one string for a single entry
    (attack round 2: two entries never share a description, so each describes the relation it holds)."""
    node.setdefault('allOf', [])
    descs = desc if isinstance(desc, list) else [desc] * len(branches_lists)
    assert len(descs) == len(branches_lists) and (isinstance(desc, list) or len(branches_lists) == 1), (desc, len(branches_lists))
    for d_, branches in zip(descs, branches_lists):
        node['allOf'].append({'description': d_, 'anyOf': branches} if len(branches) > 1 else dict(branches[0], description=d_))


def CONTAINS(x):
    """draft-04's "some item is x": not every item is not x (attack round 2)."""
    return {'not': {'items': {'not': x}}}


def NONE(x):
    """draft-04's "no item is x"."""
    return {'items': {'not': x}}


MIN_OF = {'critical': 32, 'high': 16, 'medium': 8, 'low': 1}
MAX_OF = {'high': 31, 'medium': 15, 'low': 7}


def add_relations_round2(D):
    """attack round 2: the cross-field rules §8.2 filed as engine-only or left out, which draft-04 can say: `contains` as
    not/items/not, equality over a closed set by enumeration, and, on an open set, a constraint keyed on its listed values only."""
    f = D['finding']
    relate(f, ['The `vulnerable` flag fires exactly when `security.status` is `vulnerable`.',
               'The verdict is the band of `score.total` under score model 1: critical from 32, high 16 to 31, medium 8 to 15, low 1 to 7, a score-0 word at 0.',
               '`dev` and a `dev` modifier go together on a graded score (modifiers are listed whenever their fact holds, §3.2).',
               '`reach` and the reach modifier go together on a graded score: none when direct, `transitive` when transitive, `unreached` when unreached.',
               '`allowlist_reason` (report-1) is set only beside an entry that accepts the whole package (`allowlist.flag_ids` null).',
               'A flag labelled `role: accepted` (a fired flag no term counts) stands only beside an allowlist entry; whether a flag counts is the join `flags[].id` ∈ `score.terms[].flag`, which this relation does not replace.',
               '`gate.basis` is set exactly under a baseline entry (`baseline.recorded` an object).',
               'A score-0 finding never stands `new` against a baseline: with no entry its `baseline` is null (§6.4).',
               'The security part\'s zero status follows the lookup: `clear` on a complete lookup, `unchecked` on a partial or unrun one; values this schema does not list are not constrained.',
               '`security.move_in` points at a move that exists: `next_step` an object, `also` a non-empty `next_step.also`.',
               'A package not from a Composer repository was never read (`metadata.status` `not_from_composer_repository`).'],
           [P(security=P(status=E('vulnerable')), flags=CONTAINS(P(id=E('vulnerable')))), P(security=P(status=E('clear', 'unchecked')), flags=NONE(P(id=E('vulnerable'))))],
           [P(score=P(model=NOT(1)))] + [P(verdict=E(g), score=P(total=dict(minimum=MIN_OF[g], **({'maximum': MAX_OF[g]} if g in MAX_OF else {})))) for g in GRADES]
           + [P(verdict=E(*ZERO_WORDS), score=P(total=E(0)))],
           [P(score={'not': {'required': ['terms']}}), P(dev=E(True), score=P(modifiers=CONTAINS(P(reason=E('dev'))))), P(dev=E(False), score=P(modifiers=NONE(P(reason=E('dev')))))],
           [P(score={'not': {'required': ['terms']}}),
            P(reach=E('direct'), score=P(modifiers=NONE(P(reason=E('transitive', 'unreached'))))),
            P(reach=E('transitive'), score=P(modifiers={'allOf': [CONTAINS(P(reason=E('transitive'))), NONE(P(reason=E('unreached')))]})),
            P(reach=E('unreached'), score=P(modifiers={'allOf': [CONTAINS(P(reason=E('unreached'))), NONE(P(reason=E('transitive')))]}))],
           [P(allowlist_reason=NULL), P(allowlist={'allOf': [OBJ, P(flag_ids=NULL)]})],
           [{'properties': {'flags': {'items': {'anyOf': [P(id=E('vulnerable')), P(role=NOT('accepted'))]}}}}, P(allowlist=OBJ)],
           [P(baseline={'allOf': [OBJ, P(recorded=OBJ)]}, gate=P(basis=OBJ)), P(baseline=P(recorded=NULL), gate=P(basis=NULL))],
           [P(score={'required': ['terms']}), P(baseline=P(status=NOT('new')))],
           [P(score={'not': {'required': ['terms']}}), P(score=P(parts=P(security=P(status=NOT('clear', 'unchecked'))))),
            P(security=P(check=E('complete')), score=P(parts=P(security=P(status=E('clear'))))),
            P(security=P(check=E('partial', 'not_run')), score=P(parts=P(security=P(status=E('unchecked'))))),
            P(security=P(check=NOT('complete', 'partial', 'not_run')))],
           [P(security=P(move_in=NOT('next_step', 'also'))), P(security=P(move_in=E('next_step')), next_step=OBJ), P(security=P(move_in=E('also')), next_step=P(also=MIN1))],
           [P(from_composer_repository=NOT(False)), P(metadata=P(status=E('not_from_composer_repository')))])
    sg = D['scoreGraded']
    relate(sg, ['`rounded_down` is true exactly when `exact` is not whole (I2).',
                'The maintenance part counts exactly when a maintenance term exists (I4); a status this schema does not list is not constrained.',
                'The security part counts exactly when the security term exists, and `of` counts its advisories (I4); a status this schema does not list is not constrained.',
                'The first maintenance term is the lead: role `lead`, divisor 1 (a role this schema does not list is not constrained).',
                'Under score model 1 the security term doubles exactly for the fix kinds that double (`fix_kinds[].doubles`: `none`, `blocked`); a fix kind or a multiplier this schema does not list is not constrained.'],
           [P(rounded_down=E(True), exact={'not': {'multipleOf': 1}}), P(rounded_down=E(False), exact={'multipleOf': 1})],
           [P(parts=P(maintenance=P(status=E('counted'))), terms=CONTAINS(P(part=E('maintenance')))),
            P(parts=P(maintenance=P(status=E('none', 'accepted', 'not_judged'))), terms=NONE(P(part=E('maintenance')))),
            P(parts=P(maintenance=P(status=NOT('counted', 'none', 'accepted', 'not_judged'))))],
           [P(parts=P(security=P(status=E('counted'), of={'minimum': 1})), terms=CONTAINS(P(part=E('security')))),
            P(parts=P(security=P(status=E('clear', 'unchecked'), of=E(0))), terms=NONE(P(part=E('security')))),
            P(parts=P(security=P(status=NOT('counted', 'clear', 'unchecked'))))],
           [P(terms={'items': [P(part=E('security'))]}), P(terms={'items': [P(part=E('maintenance'), role=E('lead'), divisor=E(1))]}),
            P(terms={'items': [P(part=E('maintenance'), role=NOT('lead', 'corroborating', 'security'))]})],
           [P(model=NOT(1)), P(terms={'items': {'anyOf': [P(part=E('maintenance')), P(fix_kind=E('none', 'blocked'), multiplier=E(2)),
                                                          P(fix_kind=E('update', 'upgrade', 'raise-php', 'unknown'), multiplier=E(1)),
                                                          P(fix_kind=NOT(*FIX_KINDS)), P(multiplier=NOT(1, 2))]}})])
    relate(D['modifier'], 'A reach halving applies to the maintenance part, a dev halving to the total; a reason this schema does not list is not constrained.',
           [P(reason=E('transitive', 'unreached'), applies_to=E('maintenance')), P(reason=E('dev'), applies_to=E('total')), P(reason=NOT('transitive', 'unreached', 'dev'))])
    relate(D['withoutRow'], 'Only a removed flag can reveal a liveness word: a row that removes an advisory reveals none.',
           [P(remove=P(kind=E('flag'))), P(remove=P(kind=E('advisory')), revealed=EMPTY)])
    gv = D['gateValue']
    relate(gv, ['A value\'s kind follows the value: the four grades are `grade`, the six maintenance flags `flag`, `vulnerable` and `vulnerable-<severity>` `vulnerable`, '
                '`unchecked` `unchecked`; a value or a kind this schema does not list is not constrained.'],
           [P(value=E(*GRADES), kind=E('grade')), P(value=E(*MAINT), kind=E('flag')), P(value={'pattern': '^vulnerable(-[a-z]+)?$'}, kind=E('vulnerable')),
            P(value=E('unchecked'), kind=E('unchecked')), P(kind=NOT('grade', 'flag', 'vulnerable', 'unchecked')),
            {'properties': {'value': {'not': {'anyOf': [E(*GRADES), E(*MAINT), {'pattern': '^vulnerable(-[a-z]+)?$'}, E('unchecked')]}}}}])


NEXT_FLOOR = {'low': 8, 'medium': 16, 'high': 32}
WEIGHT_M1 = {'abandoned': 32, 'silent': 32, 'pinned': 16, 'left-behind': 16, 'old-promise': 16, 'stale': 8}
SEV_M1 = {'critical': 32, 'high': 16, 'medium': 8, 'unrated': 8, 'low': 2}
MODEL_NOT_1 = P(score=P(model=NOT(1)))


def BAND_OF(tkey, vkey, zero):
    """attack round 3: `vkey` is the band of `tkey` under score model 1 (critical from 32, high 16-31, medium 8-15, low 1-7), and at 0
    `zero` (the score-0 words, or null for a part or a gate basis)."""
    return [{'properties': {vkey: E(g), tkey: dict(minimum=MIN_OF[g], **({'maximum': MAX_OF[g]} if g in MAX_OF else {}))}} for g in GRADES] \
        + [{'properties': {tkey: E(0), vkey: zero}}]


def add_relations_round3(D):
    """attack round 3 (9.S1-9.S5): the rules §8.2 still filed as engine-only though draft-04 can say them: the score-0 word follows its
    condition; every rerun's verdict is the band of its total; the band object follows the verdict; the gate basis's own arithmetic; the
    baseline marks follow the standing; the model-1 weight tables; `worst` follows `counts`; S9's level follows `worst`; a graded finding
    has evidence."""
    f = D['finding']
    whole = {'allOf': [OBJ, P(flag_ids=NULL)]}
    partial_accepting = [P(allowlist=OBJ, score=P(accepted=MIN1))]
    not_finished = {'anyOf': [P(allowlist=NULL), {'allOf': [P(allowlist={'allOf': [OBJ, P(flag_ids={'type': 'array'})]}), P(score=P(accepted=EMPTY))]}]}
    rerun = lambda path: [MODEL_NOT_1, path]
    zero_g = E(*ZERO_WORDS)
    relate(f, ['The score-0 word follows its condition (§4.1, in precedence): `finished` when an allowlist entry applies (it accepts the whole package, '
               'or a partial `ignore[]` entry accepted a fired flag); else `unknown` when maintenance was not judged; else `ok`. A grade is not constrained here.',
               'Under score model 1 each `without[]` row\'s verdict is the band of its total.',
               'Under score model 1 each accepted flag\'s `if_counted.verdict` is the band of its total.',
               'Under score model 1 each part\'s `alone.verdict` is the band of its total, null at 0.',
               'Under score model 1 `next_step.if_applied.verdict` is the band of its total, a score-0 word at 0.',
               'Under score model 1 each `next_step.also[].if_applied.verdict` is the band of its total, a score-0 word at 0.',
               'Under score model 1 `security.partial.if_applied.verdict` is the band of its total, a score-0 word at 0.',
               'Under score model 1 `gate.basis.verdict` is the band of `gate.basis.score`, null at 0.',
               'Under score model 1 the band object follows the verdict: `floor` is the verdict\'s floor, and `to_next` is the next floor minus `total` (null at critical).',
               'Under score model 1 each term of `gate.basis.new` carries model 1\'s weight for its flag or severity, and its divisor for its role.',
               'Without a baseline standing no fact carries a baseline mark: `baseline` null makes every `flags[].baseline` and every S9 row\'s `baseline` null.',
               'S9\'s level is `high` exactly when `security.worst` is `critical` or `high`.',
               'A graded finding has evidence: the join of its counted flags\' sentences is never empty.'],
           [P(verdict=E(*GRADES)), {'allOf': [P(verdict=E('finished')), {'anyOf': [P(allowlist=whole)] + partial_accepting}]},
            {'allOf': [P(verdict=E('unknown'), maintenance_judged=E(False)), not_finished]},
            {'allOf': [P(verdict=E('ok'), maintenance_judged=E(True)), not_finished]}],
           rerun(P(score=P(without={'items': {'anyOf': BAND_OF('total', 'verdict', zero_g)}}))),
           rerun(P(score=P(accepted={'items': P(if_counted={'anyOf': BAND_OF('total', 'verdict', zero_g)})}))),
           rerun(P(score=P(parts=P(maintenance=P(alone={'anyOf': BAND_OF('total', 'verdict', NULL)}), security=P(alone={'anyOf': BAND_OF('total', 'verdict', NULL)}))))),
           rerun(P(next_step=P(if_applied={'anyOf': [NULL] + BAND_OF('total', 'verdict', zero_g)}))),
           rerun(P(next_step=P(also={'items': P(if_applied={'anyOf': [NULL] + BAND_OF('total', 'verdict', zero_g)})}))),
           rerun(P(security=P(partial=P(if_applied={'anyOf': BAND_OF('total', 'verdict', zero_g)})))),
           rerun(P(gate=P(basis={'anyOf': [NULL] + BAND_OF('score', 'verdict', NULL)}))),
           [MODEL_NOT_1, P(score={'not': {'required': ['terms']}}),
                P(verdict=E('critical'), score=P(band=P(floor=E(32), next=NULL, to_next=NULL)))]
           + [P(verdict=E(band_), score=P(total=E(k), band=P(floor=E(MIN_OF[band_]), to_next=E(NEXT_FLOOR[band_] - k))))
              for k in range(1, 32) for band_ in [next(g for g in ('high', 'medium', 'low') if k >= MIN_OF[g])]],
           [MODEL_NOT_1, P(gate=P(basis=P(new=P(terms={'items': {'anyOf': [P(part=E('maintenance'), flag=E(m), weight=E(w)) for m, w in WEIGHT_M1.items()]
                                                                          + [P(part=E('security'), severity=E(s_), weight=E(w)) for s_, w in SEV_M1.items()]}})))),],
           [P(baseline=OBJ), P(baseline=NULL, flags={'items': P(baseline=NULL)},
                                signals=NONE(P(id=E('S9'), data=P(advisories=CONTAINS(P(baseline=OBJ))))))],
           [P(security=P(worst=E('critical', 'high')), signals=NONE(P(id=E('S9'), level=NOT('high')))),
            P(security=P(worst=E('medium', 'unrated', 'low')), signals=NONE(P(id=E('S9'), level=E('high'))))],
           [P(score={'not': {'required': ['terms']}}), P(evidence={'minLength': 1})])
    sg = D['scoreGraded']
    relate(sg, ['Under score model 1 each term carries model 1\'s weight for its flag (abandoned 32, silent 32, pinned 16, left-behind 16, old-promise 16, stale 8) '
                'or for its severity (critical 32, high 16, medium 8, unrated 8, low 2).',
                'Under score model 1 a `lead` term divides by 1 and a `corroborating` term by 4; a role this schema does not list is not constrained.'],
           [P(model=NOT(1)), P(terms={'items': {'anyOf': [P(part=E('maintenance'), flag=E(m), weight=E(w)) for m, w in WEIGHT_M1.items()]
                                                + [P(part=E('security'), severity=E(s_), weight=E(w)) for s_, w in SEV_M1.items()]}})],
           [P(model=NOT(1)), P(terms={'items': {'anyOf': [P(part=E('security')), P(role=E('lead'), divisor=E(1)), P(role=E('corroborating'), divisor=E(4)),
                                                          P(role=NOT('lead', 'corroborating'))]}})])
    gb = inner(D['gateBasis'])
    relate(gb, ['`new.rounded_down` is true exactly when `new.exact` is not whole.', 'What `new` adds has a term exactly when its total is not 0.'],
           [P(new=P(rounded_down=E(True), exact={'not': {'multipleOf': 1}})), P(new=P(rounded_down=E(False), exact={'multipleOf': 1}))],
           [P(new=P(total=E(0), terms=EMPTY)), P(new=P(total={'minimum': 1}, terms=MIN1))])
    fb = inner(D['findingBaseline'])
    relate(fb, 'A `known` finding has nothing new against its entry: no new, re-rated or fix-lost advisory (each would be a `worsened_by` entry).',
           [P(status=NOT('known')), P(status=E('known'), advisories_now=P(new=E(0), re_rated=E(0), fix_lost=E(0)))])
    # attack round 3, found while re-running the lens's own mutation sets: four more rules draft-04 can say
    SIG_OF = {'abandoned': ['S1', 'S3', 'S2', 'S4'], 'silent': ['S2', 'S4'], 'stale': ['S2', 'S4'], 'pinned': ['S6'], 'left-behind': ['S8'],
              'old-promise': ['S5'], 'vulnerable': ['S9']}
    relate(f, ['An accepted flag fired: each `score.accepted[].flag` has its `flags[]` entry.',
               '`metadata.reason` and `.message` are null when the metadata was read, and an `unavailable` status names its reason (MetadataFailure classifies every failure).'],
           *([[{'allOf': [{'anyOf': [P(score=P(accepted=NONE(P(flag=E(m))))), P(flags=CONTAINS(P(id=E(m))))]} for m in MAINT]}]]),
           [P(metadata=P(status=E('read'), reason=NULL, message=NULL)), P(metadata=P(status=E('unavailable'), reason=STR)),
            P(metadata=P(status=NOT('read', 'unavailable')))])
    fl_ = D.get('findingFlag')
    if fl_ is not None:
        relate(fl_, 'A flag names only the signals that raise or date it: abandoned S1 S3 S2 S4, silent and stale S2 S4, pinned S6, left-behind S8, old-promise S5, vulnerable S9; a signal id this schema does not list is not constrained.',
               [P(id=E(k), signal_ids={'items': {'anyOf': [E(*v), NOT(*SIGNAL_IDS)]}}) for k, v in SIG_OF.items()])  # a later signal id is free
    relate(D['scoreGraded'], ['A graded score has at least one term.', 'Under score model 1 every halving divides by 2.'],
           [P(terms=dict(MIN1, description='At least one term.'))], [P(model=NOT(1)), P(modifiers={'items': P(divide_by=E(2))})])
    sv = D['securityVulnerable']
    relate(sv, '`worst` is the highest severity `counts` holds, in the order `critical high medium unrated low`.',
           [P(worst=E('critical'), counts=P(critical={'minimum': 1})),
            P(worst=E('high'), counts=P(critical=E(0), high={'minimum': 1})),
            P(worst=E('medium'), counts=P(critical=E(0), high=E(0), medium={'minimum': 1})),
            P(worst=E('unrated'), counts=P(critical=E(0), high=E(0), medium=E(0), unrated={'minimum': 1})),
            P(worst=E('low'), counts=P(critical=E(0), high=E(0), medium=E(0), unrated=E(0), low={'minimum': 1}))])


def add_relations(D):
    f = D['finding']
    relate(f, 'The verdict, its `priority` alias and the score\'s shape agree: a grade has the graded score; a score-0 word has the score-0 shape, '
              'priority `none` and no move.',
           [P(verdict=E(g), priority=E(g), score={'required': ['terms']}) for g in GRADES]
           + [P(verdict=E(*ZERO_WORDS), priority=E('none'), score={'not': {'required': ['terms']}}, next_step=NULL)])
    relate(f, '`reach` follows `direct` and `chain`: `direct` when composer.json requires it; `transitive` with a chain of two or more; `unreached` with an '
              'empty one.',
           [P(direct=E(True), reach=E('direct')),
            P(direct=E(False), reach=E('transitive'), chain={'minItems': 2}),
            P(direct=E(False), reach=E('unreached'), chain=EMPTY)])
    relate(f, 'Maintenance is judged exactly when repository metadata was read.',
           [P(metadata=P(status=E('read')), maintenance_judged=E(True)), P(metadata=P(status=NOT('read')), maintenance_judged=E(False))])
    relate(f, '`security.installed_branch_fixes` is null exactly when `branch` is (a snapshot; I21).',
           [P(branch=STR, security=P(installed_branch_fixes=OBJ)), P(branch=NULL, security=P(installed_branch_fixes=NULL))])
    # attack round 2: the first term's flag is the lead itself (six-way enumeration), not just some maintenance term
    relate(f, '`lead` is null exactly when no maintenance term counts; when set, the first term is the lead\'s own maintenance term (I6).',
           [P(lead=NULL, score=P(terms={'items': P(part=E('security'))}))]
           + [{'properties': {'lead': E(m), 'score': {'required': ['terms'], 'properties': {'terms': {'items': [P(part=E('maintenance'), flag=E(m))]}}}}} for m in MAINT])
    relate(f, 'An advisory counts (`security.status` vulnerable) exactly when the security part counts.',
           # attack round 1: parts.security.status is open: a status a later release adds for a vulnerable finding validates (§8.2)
           [P(security=P(status=E('vulnerable')), score={'required': ['terms'], 'properties': {'parts': P(security=P(status={'anyOf': [
               E('counted'), NOT('counted', 'clear', 'unchecked')]}))}}),
            P(security=P(status=E('clear', 'unchecked')), score={'anyOf': [{'not': {'required': ['terms']}}, P(parts=P(security=P(status=NOT('counted'))))]})])
    add_relations_round2(D)
    add_relations_round3(D)
    for name in ('nextStep', 'move'):
        m = D[name]
        # every relation keyed on an open set constrains its known values only: a kind a later release adds is free (§8.2)
        others = lambda *ks: E(*[k for k in MOVE_KINDS if k not in ks])
        relate(m, '`reason` is set exactly on `no-move` (I18); a kind this schema does not list is not constrained.',
               [P(kind=E('no-move'), reason=STR), P(kind=others('no-move'), reason=NULL), P(kind=NOT(*MOVE_KINDS))])
        relate(m, '`replacement` is set exactly on `replace`; a kind this schema does not list is not constrained.',
               [P(kind=E('replace'), replacement=OBJ), P(kind=others('replace'), replacement=NULL), P(kind=NOT(*MOVE_KINDS))])
        relate(m, '`constraint` is set exactly on `require` and `raise-php` (I18); a kind this schema does not list is not constrained.',
               [P(kind=E('require', 'raise-php'), constraint=STR), P(kind=others('require', 'raise-php'), constraint=NULL), P(kind=NOT(*MOVE_KINDS))])
        relate(m, '`php_check` is set on every move that names a branch or a tag (I18), null on the known kinds that name none; a kind this schema '
                  'does not list is not constrained.',
               [P(kind=E(*PHP_CHECKED), php_check=OBJ), P(kind=E(*[k for k in MOVE_KINDS if k not in PHP_CHECKED]), php_check=NULL),
                P(kind=NOT(*MOVE_KINDS))])
        relate(m, ['`if_applied` is null for `replace find-alternative test blocked` and the `no-*` kinds (I14); a kind this schema does not list is not constrained.',
                   '`if_applied` is present only with a non-empty `clears` (I14).'],
               [P(kind=E(*NO_COMMAND), if_applied=NULL), P(kind=NOT(*NO_COMMAND))],
               [P(if_applied=NULL), P(clears=MIN1)])
        relate(m, '`latest` is set on `tag` and `test`, on a `blocked` move from `pinned`, and nowhere else.',
               [P(kind=E('tag', 'test'), latest=OBJ), P(kind=E('blocked'), source=E('pinned'), latest=OBJ), P(kind=E('blocked'), source=NOT('pinned'), latest=NULL),
                P(kind=others('tag', 'test', 'blocked'), latest=NULL), P(kind=NOT(*MOVE_KINDS))])
        # review round 3 (C08): the lead move of a transitive package may end with `composer why-not` checks, whatever its kind
        relate(m, 'Kinds without a command write none but `composer why-not` checks; `update` always writes one (a transitive package included).',
               [P(kind=E(*NO_COMMAND), commands={'items': {'items': [{'enum': ['composer']}, {'enum': ['why-not']}]}}), P(kind=E('update'), commands=MIN1),
                P(kind=NOT(*(NO_COMMAND + ['update'])))])
        relate(m, '`clears_all` is true exactly when `leaves` and `unverified` are both empty.',
               [P(clears_all=E(True), leaves=EMPTY, unverified=EMPTY), {'properties': {'clears_all': E(False)}, 'anyOf': [P(leaves=MIN1), P(unverified=MIN1)]}])
        relate(m, '`if_applied.at_most` is true exactly when `unverified` is not empty (I14).',
               [P(if_applied=NULL), P(if_applied=P(at_most=E(True)), unverified=MIN1), P(if_applied=P(at_most=E(False)), unverified=EMPTY)])
        relate(m, '`crosses_major` is null when the move names no branch.', [P(to_branch=NULL, crosses_major=NULL), P(to_branch=STR)])
        # attack round 2: `merged` holding vulnerable makes it a security move too
        relate(m, 'A security move carries its fix kind: `source` or `merged` holds `vulnerable`.',
               [P(source=E('vulnerable'), fix_kind=STR), P(merged=CONTAINS(E('vulnerable')), fix_kind=STR), P(source=NOT('vulnerable'), merged=NONE(E('vulnerable')))])
    relate(inner(D['gateBasis']), ['`verdict` is null exactly at gate score 0 (I21).', '`limited_by` is `cap` whenever `new.total` is 0.'],
           [P(score=E(0), verdict=NULL), P(score={'minimum': 1}, verdict=STR)],
           [P(new=P(total=E(0)), limited_by=E('cap')), P(new=P(total={'minimum': 1}))])
    relate(D['findingGate'], 'A failing finding reaches a value and is not exempt; a passing one is exempt only when it reaches a value.',
           [P(fails=E(True), reaches_fail_on=E(True), exempt_by=NULL), P(fails=E(False), exempt_by=NULL),
            P(fails=E(False), reaches_fail_on=E(True), exempt_by=STR)])
    relate(D['band'], '`next` and `to_next` are null together, at the top band.', [P(next=NULL, to_next=NULL), P(next=STR, to_next={'type': 'integer'})])
    # attack round 2: the floor and the next band follow each other (model 1's bands are a closed table of four)
    relate(D['band'], '`floor` and `next` name adjacent bands: 32 at the top, 16 below critical, 8 below high, 1 below medium.',
           [P(floor=E(32), next=NULL), P(floor=E(16), next=E('critical')), P(floor=E(8), next=E('high')), P(floor=E(1), next=E('medium')),
            P(floor=NOT(32, 16, 8, 1))])
    relate(D['partAlone'], '`verdict` is null exactly when the part alone is 0.', [P(total=E(0), verdict=NULL), P(total={'minimum': 1}, verdict=STR)])
    for name in ('partMaintenance', 'partSecurity'):
        known = D[name]['properties']['status']['x-known-values']
        relate(D[name], 'A part counts exactly when it contributes (I4); a status this schema does not list is not constrained.',
               [P(status=E('counted'), contribution={'minimum': 0.5}), P(status=E(*[k for k in known if k != 'counted']), contribution=E(0)),
                P(status=NOT(*known))])
    for name in ('securityVulnerable', 'securityOther'):
        relate(D[name], '`complete` is true exactly when `check` is `complete`, and `unchecked_reason` is null exactly then; a check this schema '
                        'does not list is not constrained.',
               [P(check=E('complete'), complete=E(True), unchecked_reason=NULL),
                P(check=E('partial', 'not_run'), complete=E(False), unchecked_reason=STR),
                P(check=NOT('complete', 'partial', 'not_run'))])
    relate(D['securityOther'], '`clear` only on a complete lookup; `unchecked` when it did not complete (I21).',
           [P(status=E('clear'), check=E('complete')), P(status=E('unchecked'), check=NOT('complete'))])
    fb = inner(D['findingBaseline'])
    relate(fb, ['`recorded` is null exactly for `new`.', 'A `known` finding has nothing that worsens it, a `worsened` one names what does.'],
           [P(status=E('new'), recorded=NULL), P(status=NOT('new'), recorded=OBJ)],
           [P(status=E('known'), worsened_by=EMPTY), P(status=E('worsened'), worsened_by=MIN1), P(status=E('new'))])
    wb = fb['properties']['worsened_by']['items']
    relate(wb, 'A flag worsens only as `new` and carries no severity; an advisory carries its state now, and what was recorded unless it is new.',
           [P(kind=E('flag'), why=E('new'), recorded=NULL, now=NULL), P(kind=E('advisory'), why=E('new'), recorded=NULL, now=OBJ),
            P(kind=E('advisory'), why=E('re_rated', 'fix_lost'), recorded=OBJ, now=OBJ), P(why=NOT('new', 're_rated', 'fix_lost'))])
    relate(inner(D['flagBaseline']), '`covered_by` is set exactly on `covered`; a state this schema does not list is not constrained.',
           [P(state=E('covered'), covered_by=STR), P(state=E('known', 'new', 're_rated', 'fix_lost'), covered_by=NULL),
            P(state=NOT('known', 'covered', 'new', 're_rated', 'fix_lost'))])
    relate(inner(D['allowlist']), 'Who accepted decides whose words the reason is: a project entry is the user\'s (no `reason_id`); a built-in or type '
                                  'entry is lockrot\'s, with a `reason_id`, accepts every maintenance flag, and a type entry matches no name pattern.',
           [P(by=E('project'), reason_by=E('user'), reason_id=NULL, pattern=STR),
            P(by=E('builtin'), reason_by=E('lockrot'), reason_id=STR, flag_ids=NULL, pattern=STR),
            P(by=E('type'), reason_by=E('lockrot'), reason_id=STR, flag_ids=NULL, pattern=NULL)])
    fix = D['s9row']['properties']['fix']
    relate(fix, ['A fix of kind `unknown` or `none` has a `reason` and no `to_branch`; a known reachable kind has a branch and no reason; a kind this schema '
                 'does not list is not constrained.', '`on_installed_branch` and `newest` are null when there is no branch; `on_installed_branch` is set when there is one.'],
           [P(kind=E('unknown', 'none'), reason=STR, to_branch=NULL), P(kind=E('update', 'upgrade', 'raise-php', 'blocked'), reason=NULL, to_branch=STR),
            P(kind=NOT(*FIX_KINDS))],
           [P(to_branch=NULL, on_installed_branch=NULL, newest=NULL), P(to_branch=STR, on_installed_branch={'type': 'boolean'})])
    D['s9row']['properties']['counted'] = {'description': 'Always true on an S9 row: ignored advisories live in `security.ignored[]` (round 4).',
                                           'type': 'boolean', 'enum': [True]}


EXPLAIN_RUN_KEYS = ['target_php', 'target_php_source', 'project_php', 'project_php_lowest', 'include_dev', 'thresholds', 'flag_ids', 'verdicts', 'graded_verdicts',
                    'signal_ids', 'fail_on', 'fail_on_source', 'gates', 'fix_model', 'text_grammar', 'score_model']
EXPLAIN_ONLY = ['legacy', 'priority', 'priorityStep', 'flaggedPriority', 'lock', 'repositoryMetadata', 'activity', 'explainRun']


def refs_in(node, out):
    if isinstance(node, dict):
        r = node.get('$ref')
        if isinstance(r, str) and r.startswith('#/definitions/'):
            out.add(r[len('#/definitions/'):])
        for v in node.values():
            refs_in(v, out)
    elif isinstance(node, list):
        for v in node:
            refs_in(v, out)
    return out


def closure(defs, names):
    """every definition reachable from `names` through `$ref`s."""
    seen, todo = set(), list(names)
    while todo:
        n = todo.pop()
        if n in seen:
            continue
        seen.add(n)
        todo.extend(refs_in(defs[n], set()) - seen)
    return seen


def explain_schema():
    """attack round 1: explain-2 types the report-2 finding, its signals, notes and the run block with report-2's own
    definitions, copied byte for byte from lockrot-report-2.schema.json (built first by build_report2_schema.py). Revision 3's
    second builder typed the same finding differently in 189 places; SchemaParity in run_all.sh asserts the copies are equal."""
    R2 = json.load(open(REPORT2))
    RD = R2['definitions']
    D = {}
    sd = shared_definitions()
    for k in ('legacy', 'priority', 'priorityStep', 'flaggedPriority'):
        D[k] = sd[k]
    run = copy.deepcopy(RD['run'])
    D['explainRun'] = {'description': ('What an `--explain` run read, and every field `score_model` refers to (§7.7, §8.3): the explain text renders from this '
                                       'block and the finding alone. Each key is report-2\'s `run` key of the same name, typed by the same schema.'),
                       'type': 'object', 'required': list(EXPLAIN_RUN_KEYS), 'properties': {k: run['properties'][k] for k in EXPLAIN_RUN_KEYS},
                       'allOf': copy.deepcopy(run.get('allOf', []))}  # attack round 2: the run relation (`none` ⇔ no gate) too
    lock = strip_optional(copy.deepcopy(E1['properties']['lock']))
    lock['properties']['php']['description'] = lock['properties']['php'].get('description', 'The entry\'s php requirement, null when it has none.')
    for k, dsc in [('dev', 'Whether the entry is in packages-dev.'), ('branch_snapshot', 'Whether the installed version is a branch snapshot.'),
                   ('type', 'The entry\'s package type.')]:
        lock['properties'][k]['description'] = dsc
    D['lock'] = lock
    D['metadata'] = explain_metadata()
    D['repositoryMetadata'] = D.pop('metadata')
    D['activity'] = explain_activity()
    for k in D:
        assert k in EXPLAIN_ONLY, k
        assert k not in RD, ('an explain-only definition collides with report-2', k)
    need = refs_in(D, set()) | {'finding', 'envelope', 'packageName', 'dateTime', 'noteDetail'}
    shared = closure(RD, [n for n in need if n in RD])
    for k in sorted(shared):
        D[k] = copy.deepcopy(RD[k])
    missing = refs_in(D, set()) - set(D)
    assert not missing, missing
    root = {
        '$schema': DRAFT,
        'id': 'https://lockrot.dev/schema/explain-2.json',
        'title': 'lockrot explanation',
        'description': ('The document `composer lockrot --explain=vendor/package --format=json` prints (explain-2, lockrot 0.14): the finding exactly as '
                        'report-2 carries it, everything it was read from, the run block every field of `run.score_model` refers to, and 0.13\'s '
                        'reading of it (§7.7, §8.3). Every human string in it is a rendering of fields in the same object or in `run` (§7.11): '
                        'strings carry `x-rendered-from`, and no consumer parses them. Objects are open: a field added later validates against this '
                        'file unchanged; the number in the id changes only when a field is removed or renamed. Open sets carry a `pattern` and '
                        '`x-known-values`; closed sets are enums.'),
        'type': 'object',
        'required': ['$schema', 'lockrot', 'package', 'version', 'finding', 'lock', 'metadata', 'activity', 'run', 'legacy', 'generated_at', 'notes', 'note_details'],
        'properties': {
            '$schema': {'description': 'This schema\'s published URL; always written (schema round), so an explain-1 document fails at once.', 'type': 'string', 'enum': ['https://lockrot.dev/schema/explain-2.json']},
            'lockrot': ref('envelope', 'The writer and the schema number.'),
            'package': ref('packageName', 'The package explained; equal to `finding.package`.'),
            'version': string('Its installed version; equal to `finding.version`.', minLength=1),
            'finding': ref('finding', 'The finding, exactly as the report carries it (report-2\'s definition, copied).'),
            'lock': ref('lock', 'The package\'s entry in composer.lock.'),
            'metadata': nullable(ref('repositoryMetadata'), 'What the Composer repository said; null when it could not be loaded or was never asked.'),
            'activity': nullable(ref('activity'), 'What the repository host said; null when the repository was not checked.'),
            'run': ref('explainRun', 'What the run read.'),
            'legacy': ref('legacy', '0.13\'s reading of the finding (the `0.13:` line).'),
            'generated_at': ref('dateTime', 'The run clock: every years field is measured against it.'),
            'notes': array('The run\'s notes, as printed.', {'description': 'One note: ' + PROSE + '.', 'type': 'string', 'x-rendered-from': ['note_details[].data']}),
            'note_details': array('The run\'s notes, typed: one entry per `notes` string, at the same index.', ref('noteDetail')),
        },
        'definitions': dict(sorted(D.items())),
    }
    return root


# r3 schema round: properties the -1 files left undescribed (report-1 described the object, not each member); every -2
# property carries a description, as the report-2 builder already checks
MEMBER_DESCRIPTIONS = {
    ('definitions', 'forgeRepository', 'host'): 'The host as Composer names it (`github.com`, possibly with a port or a path prefix).',
    ('definitions', 'forgeRepository', 'repo'): 'The path on the host (`owner/repo`, or `group/sub/project` on GitLab).',
    ('definitions', 'failedForgeRepository', 'host'): 'The host as Composer names it.',
    ('definitions', 'failedForgeRepository', 'repo'): 'The path on the host.',
    ('definitions', 's8', 'branch_last_version'): "The installed branch's newest release (report-1).",
    ('definitions', 's8', 'newest_branch'): 'The newest release branch, as `ReleaseBranch::label()` writes it (report-1).',
    ('definitions', 's8', 'newest_version'): "The newest branch's newest release (report-1).",
    ('properties', 'target-php'): 'The PHP minor the project runs on (`8.4`); the third link of the target chain (§5.3). config-1 unchanged.',
    ('properties', 'include-dev'): 'Whether packages-dev are analysed (`--dev`). config-1 unchanged.',
    ('properties', 'install-time'): 'Whether the install-time pass runs after `composer install` and `update` (`on`, `off`). config-1 unchanged.',
    ('properties', 'install-time-strict'): 'Apply `fail-on` at install time and stop the transaction (docs/install-time.md#install-time-strict). config-1 unchanged.',
    ('properties', 'install-time-budget'): 'Seconds the install-time pass may take, 1–120 (default 5). config-1 unchanged.',
    ('properties', 'baseline'): 'The baseline file, relative to composer.json (§6.4). lockrot 0.14 reads baseline-2 only; a baseline-1 file exits 2 until --generate-baseline overwrites it.',
    ('properties', 'release-warn-years'): 'Years without a stable release at which S2 (no stable release) and S8 (none on the installed branch) reach `warn` (default 3). config-1 unchanged.',
    ('properties', 'release-high-years'): 'Years without a stable release at which S2 and S8 reach `high` (default 5). config-1 unchanged.',
    ('properties', 'push-warn-years'): 'Years without a push or commit at which S4 reaches `warn` (default 3). config-1 unchanged.',
    ('properties', 'push-high-years'): 'Years without a push or commit at which S4 reaches `high` (default 5). config-1 unchanged.',
}


def describe_members(doc):
    for path, text in MEMBER_DESCRIPTIONS.items():
        node = doc
        for k in path[:-1]:
            node = node.get(k) if isinstance(node, dict) else None
            if node is None:
                break
        if node is None:
            continue
        props = node.get('properties', node) if path[0] == 'definitions' else node
        sub = props.get(path[-1]) if isinstance(props, dict) else None
        if isinstance(sub, dict) and 'description' not in sub:
            props[path[-1]] = dict({'description': text}, **sub)


def main():
    out = {'lockrot-explain-2.schema.json': explain_schema()}
    from ship_text import ship_schema, leftovers
    for doc in out.values():
        describe_members(doc)
        ship_schema(doc)  # attack round 2: the shipping pass
        lo = leftovers(doc)
        assert not lo, lo[:5]
    for name, doc in out.items():
        with open(os.path.join(RESOURCES, name), 'w') as fh:
            json.dump(doc, fh, indent=4, ensure_ascii=False)
            fh.write('\n')
        print(name, os.path.getsize(os.path.join(RESOURCES, name)))


if __name__ == '__main__':
    main()
