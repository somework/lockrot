"""Builds resources/lockrot-report-2.schema.json (draft-04).

Conventions kept from report-1 (src/Json/Schemas.php, src/Json/KnownValues.php):
  draft-04, `id` = https://lockrot.dev/schema/report-2.json, objects open (no additionalProperties: false; the tests' strict
  twin adds it), closed sets as `enum`, open sets as a string `pattern` plus `x-known-values` (KnownValues::closed reads
  the list as the enum), nullable values as `oneOf [.., {type: null}]` or a type list, shared shapes under `definitions`,
  signals and notes typed per id/code by `anyOf` branches with a generic branch for an id/code the schema does not list.
Conventions report-2 adds: every key is always written (so every key is `required`), every rendered string carries
  `x-rendered-from`, discriminated shapes (`score`, `terms[]`, `security`) are `oneOf` branches, half-point numbers carry
  `multipleOf: 0.5`, and integer parameters of score model 1 that a model change can move carry `x-known-values`
  (published: any integer; strict twin: model 1's values).

Usage: python3 tools/schema/build_report2_schema.py
"""
import json, os, copy

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.normpath(os.path.join(HERE, '..', '..'))
OUT = os.path.join(REPO, 'resources', 'lockrot-report-2.schema.json')
import hashlib
# the report-1 file is read from a committed, hash-pinned copy of the 0.13.0 file (tools/schema/inputs/ in the
# repository; inputs/ here), never from the live resources/ file, so a later widening of report-1 cannot change a report-2 rebuild
INPUTS = os.path.join(HERE, 'inputs')
PINNED = json.load(open(os.path.join(INPUTS, 'SHA256SUMS.json')))


def pinned(name):
    raw = open(os.path.join(INPUTS, name), 'rb').read()
    assert hashlib.sha256(raw).hexdigest() == PINNED[name], ('a pinned input changed', name)
    return json.loads(raw)


R1 = pinned('lockrot-report.schema.json')

FLAGS = ['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'vulnerable']
MAINT = FLAGS[:6]
VERDICTS = ['critical', 'high', 'medium', 'low', 'unknown', 'finished', 'ok']
GRADES = VERDICTS[:4]
SEVERITIES = ['critical', 'high', 'medium', 'unrated', 'low']
FIX_KINDS = ['update', 'upgrade', 'raise-php', 'unknown', 'blocked', 'none']
RULE_IDS = ['counted', 'lead-first', 'corroborating-share', 'advisory-points', 'no-reachable-fix-multiplier', 'security-max', 'divide-reach',
            'security-exempt-from-reach', 'sum', 'divide-dev', 'floor-once', 'band-floors', 'zero-verdicts', 'sort', 'gate-bounded-new', 'baseline-cover']
RULE_KINDS = ['select', 'share', 'lookup', 'multiply', 'max', 'divide', 'exempt', 'combine', 'floor', 'band', 'zero', 'order', 'gate', 'cover']
WHY_IDS = ['accepted-facts-shown-not-counted', 'strongest-fact-in-full', 'correlated-evidence', 'severity-points', 'one-band-up',
           'one-number-per-part', 'reach-halves-maintenance', 'exploitability-independent-of-reach', 'parts-add', 'dev-halves-total',
           'two-floor-parts-make-next', 'integer-band-edges', 'score-zero-says-why', 'advisory-findings-first', 'new-fact-sets-level', 'recorded-lead-covers-below']
SNAKE = '^[a-z][a-z0-9_]*$'          # reason-like open values ("reason-like values use underscores")
HYPHEN = '^[a-z][a-z0-9-]*$'         # flag-like open values ("flag-like values keep hyphens")
DOTTED = '^[a-z][a-z0-9_.-]*$'       # a Composer config key (`policy.advisories`, `audit.ignore-severity`)
POLICY_KEY = '^([a-z][a-z0-9_.-]*|[A-Z][A-Z0-9_]*)$'  # P1: a Composer config key, or the environment variable COMPOSER_POLICY
NOT_CONTRACT = 'Prose, not contract; key on the fields it is rendered from (§7.11).'


def _class_without(ch, alphabet='abcdefghijklmnopqrstuvwxyz0123456789'):
    """a character class of `alphabet` minus `ch`, written as ranges (`[a-np-z0-9]`)."""
    keep = [c for c in alphabet if c != ch]
    runs, start, prev = [], None, None
    for c in keep:
        if start is not None and ord(c) == ord(prev) + 1:
            prev = c
            continue
        if start is not None:
            runs.append((start, prev))
        start = prev = c
    runs.append((start, prev))
    return '[' + ''.join(a if a == b else (a + b if ord(b) == ord(a) + 1 else a + '-' + b) for a, b in runs) + ']'


def rule_id_except(word):
    """the rule-id grammar `^[a-z][a-z0-9]*(-[a-z0-9]+)*$` minus the one id `word`, with no lookahead: RE2-based
    validators (Go's regexp) cannot compile one.
    A first segment that differs from `word` at some position, or extends it, or `word` itself followed by a further segment."""
    seg = []
    seg.append(_class_without(word[0], 'abcdefghijklmnopqrstuvwxyz') + '[a-z0-9]*')
    for i in range(1, len(word)):
        seg.append(word[:i] + '(' + _class_without(word[i]) + '[a-z0-9]*)?')
    seg.append(word + '[a-z0-9]+')
    return '^((' + '|'.join(seg) + ')(-[a-z0-9]+)*|' + word + '(-[a-z0-9]+)+)$'


def ref(name):
    return {'$ref': '#/definitions/' + name}


def d(desc, schema):
    """a property schema with its description first."""
    out = {'description': desc}
    out.update(schema)
    return out


def nul(desc, schema):
    """`schema` or null, described; a `$ref` or an enum keeps `oneOf` (as report-1 does), a bare type takes a type list."""
    if set(schema) <= {'type', 'minimum', 'minLength'} and isinstance(schema.get('type'), str) and schema['type'] in ('string', 'integer', 'number', 'boolean'):
        s = dict(schema)
        s['type'] = [schema['type'], 'null']
        return d(desc, s)
    return d(desc, {'oneOf': [schema, {'type': 'null'}]})


def closed(values, desc=None):
    s = {'type': 'string', 'enum': list(values)}
    return d(desc, s) if desc else s


def open_set(known, desc, pattern=SNAKE):
    return d(desc, {'type': 'string', 'pattern': pattern, 'x-known-values': list(known)})


def obj(desc, props, required=None, **extra):
    s = {'description': desc, 'type': 'object', 'required': list(props) if required is None else required, 'properties': props}
    if not s['required']:  # draft-04: `required` has at least one item
        del s['required']
    s.update(extra)
    return s


def arr(desc, items, **extra):
    s = {'description': desc, 'type': 'array', 'items': items}
    s.update(extra)
    return s


def rendered(desc, paths):
    return d(desc + ' ' + NOT_CONTRACT, {'type': 'string', 'x-rendered-from': paths})


INT0 = {'type': 'integer', 'minimum': 0}
INT1 = {'type': 'integer', 'minimum': 1}
HALF = {'type': 'number', 'minimum': 0, 'multipleOf': 0.5}

defs = {}

# basic vocabularies
defs['envelope'] = obj('Who wrote the document and under which schema number (§8.1).', {
    'version': d('The lockrot release that wrote the document.', {'type': 'string', 'minLength': 1}),
    'schema': d('The document schema number this file describes: 2 (§8.1; report-1 documents name report-1.json).', {'type': 'integer', 'enum': [2]}),
})
for k in ('dateTime', 'date', 'packageName', 'level', 'years', 'datedBy', 'forgeId', 'metadataFailureReason', 'repositoryActivityNotCheckedReason',
          'forgeRepository', 'failedForgeRepository', 'originKind', 'originRegistry', 'signalId'):
    defs[k] = copy.deepcopy(R1['definitions'][k])
defs['dateTime']['description'] = 'An ISO 8601 date-time with its offset, as lockrot writes every instant (`2026-10-01T17:39:31+00:00`).'
defs['date']['description'] = 'A calendar date, `YYYY-MM-DD`.'
defs['level']['description'] = "A signal's own level, as report-1 (`info`, `warn`, `high`); kept for consumers, never printed beside a grade (§2.1). Closed."
defs['years']['description'] = ('Years elapsed on the run clock (`Clock::yearsSince` against `generated_at`), one decimal, written as PHP json_encode writes a '
                                'float under serialize_precision=-1, so a whole value is an integer (`4`, never `4.0`); print it with one decimal (§2.1 "The years fields"). '
                                'The one-decimal rule is stated, not encoded: `multipleOf: 0.1` has no exact binary form and standard validators (ajv, '
                                'python-jsonschema) reject a third of all tenths with it (attack round 1); only `multipleOf: 0.5` is used, which is exact.')
defs['years'].pop('multipleOf', None)
defs['advisoriesNotCheckedReason'] = open_set(['offline', 'composer_too_old', 'install_time_budget'],
    "Why security advisories were not checked (§5.1): `offline` (`--offline`; advisories are never served from a cache), `composer_too_old` "
    "(Composer below 2.4 has no advisory API), `install_time_budget` (the install-time budget ran out). "
    "An open set: read a reason you do not know as another reason.")
defs['flag'] = closed(FLAGS, 'A flag id (§2.1), in the frozen flag order `abandoned silent pinned left-behind old-promise stale vulnerable`. Closed: a new flag is a report-3 event (§3.7, §8.2).')
defs['maintenanceFlag'] = closed(MAINT, 'A maintenance flag id: the flag order without `vulnerable` (§2.1). The same closed set as `flag`, restricted; the values `lead`, an accepted flag and a baseline entry can name.')
defs['verdict'] = closed(VERDICTS, 'The verdict (§4.1): a grade scored from the counted flags (`critical high medium low`, by `score.total` and the bands of `run.score_model`), or at score 0 the word that says why (`unknown` no metadata, `finished` the allowlist accepted a fired flag, `ok` otherwise). Closed (§8.2).')
defs['grade'] = closed(GRADES, 'A grade: the verdict set without its score-0 words, highest first (`run.graded_verdicts`). The same closed set as `verdict`, restricted.')
defs['severity'] = closed(SEVERITIES, "An advisory severity bucket in the one order used everywhere (§5.2): `critical high medium unrated low`; `moderate` reads `medium`, a missing or unknown rating reads `unrated` (which counts as medium). Closed (§8.2). Compare severities by `run.score_model.severities[].gate_rank`, never by position.")
defs['fixKind'] = open_set(FIX_KINDS, ("A fix kind (§5.3, §5.4): the class of the easiest release outside an advisory's range, in ease order `update upgrade raise-php unknown blocked none`. "
    "`blocked` and `none` double the advisory (`run.score_model.fix_kinds[].doubles`). An open set (§8.2, round 2): a fix-model change may add a kind, which arrives "
    "with its row in `run.score_model.fix_kinds[]`; read an unknown kind's `ease` and `doubles` there and show its id."), HYPHEN)
defs['branchLabel'] = d("A release branch as `ReleaseBranch::label()` writes it (`2.x`, `0.15.x`, and `0.0.3` for a 0.0.n branch, which is one release wide) (§2.1 \"One label per branch\").",
                        {'type': 'string', 'minLength': 1})
defs['halfPoints'] = d('A score number in half points (§8.2 "Numbers"): exact, dyadic, `multipleOf: 0.5` (`run.score_model.exact_unit`); a whole value is written as an integer.', HALF)
defs['advisoryId'] = d("An advisory's `id` as its Composer repository serves it (Packagist: `PKSA-…`); not promised to be a Packagist id (report-1's S9 rule).", {'type': 'string', 'minLength': 1})

# facts named by moves and gates
FACT_KIND = closed(['flag', 'advisory'], ('What the fact is (§5.6, §6.4): a `flag` (its `id` a flag id) or an `advisory` (its `id` an S9 row id). '
    'Closed (§8.2, schema round): the same pair as `without[].remove.kind`; a score has two fact spaces, and a third would be a report-3 event like a new flag.'))
defs['factKind'] = FACT_KIND
defs['fact'] = obj('A counted fact a move leaves in place (§5.6 `leaves[]`): a flag or an advisory of this finding.', {
    'kind': ref('factKind'),
    'id': d('The flag id (`kind: flag`) or the advisory id (`kind: advisory`); joins `flags[].id` or the S9 row `id`.', {'type': 'string', 'minLength': 1}),
})
defs['clearedFact'] = obj("A fact a move clears (§5.6 \"What clears[] may name\"): checked for every version the emitted constraint admits, or for the one release `composer update` installs.", {
    'kind': ref('factKind'),
    'id': d('The flag id or the advisory id the move clears.', {'type': 'string', 'minLength': 1}),
    'basis': open_set(['branch_releasing', 'released_after_ga', 'outside_range', 'tags_only'],
        ('Why it is cleared (§5.6): `branch_releasing` (left-behind: the branch released within `release-warn-years`), `released_after_ga` (old-promise: the lowest admitted release '
         'came out after the target major\'s GA), `outside_range` (an advisory: no admitted version is inside its range), `tags_only` (pinned: the constraint admits only tags). '
         'An open set (§8.2, round 2): read an unknown basis as another reason the fact is cleared.')),
})
defs['unverifiedFact'] = obj("A fact lockrot could not check for the move (§5.6): a counted fact it may not clear (kept as counted), or a flag the move may raise "
                             "(left-behind on a tag whose line lockrot cannot date); either way `if_applied` is an upper bound (`at_most`).", {
    'kind': ref('factKind'),
    'id': d('The flag id or the advisory id left unverified.', {'type': 'string', 'minLength': 1}),
    'reason': open_set(['undated', 'no_release_data'], ("Why it could not be checked (§5.6): `undated` (the lowest admitted release carries no date), `no_release_data` "
        "(no release data for the branch). An open set (§8.2): read an unknown reason as another reason it was not checked.")),
})
defs['addedFact'] = obj("A fact the move would raise (§5.6 `adds[]`); every surface names it (I22).", {
    'kind': ref('factKind'),
    'id': d('The flag id the move would raise.', {'type': 'string', 'minLength': 1}),
    'basis': open_set(['released_before_ga', 'branch_not_releasing'], ("Why the move raises it (§5.6): `released_before_ga` (a tag released before the target major's GA, with an open `require.php`, adds old-promise), "
        "`branch_not_releasing` (a tag on a line that has not released in `release-warn-years` while a higher line has, S8's own rule, adds left-behind). "
        "An open set (§8.2): read an unknown basis as another reason.")),
})

# baseline per fact
defs['factBaseline'] = nul("A fact's state against the baseline entry (§6.4 \"Per fact\"): on each counted maintenance flag's `flags[].baseline` and on each S9 row's `baseline`. "
                           "Null without a baseline, and always null on the `vulnerable` flag (its advisories carry theirs).",
    obj('A fact\'s baseline state.', {
        'state': open_set(['known', 'covered', 'new', 're_rated', 'fix_lost'], ("`known` (the entry lists it), `covered` (a flag below the entry's recorded flag: `covered_by` names it), "
            "`new`, `re_rated` (higher by gate rank), `fix_lost` (`update upgrade raise-php unknown` → `blocked none`, compared only at an equal target and fix model). "
            "An open set (§8.2, round 2) sharing its values with `baseline.worsened_by[].why`. `known` is the baseline's word; `accepted` is the allowlist's only (§6.4, round 3).")),
        'since': nul('The date the baseline first knew the fact (`known_since`); null for a fact the entry does not know.', ref('date')),
        'covered_by': nul('The recorded flag above a `covered` flag ("stale (baseline: below the recorded old-promise)"); null for every other state.', ref('maintenanceFlag')),
    }))

# holders and php checks
HOLDER_MOVE = obj("The holder's own move, `{kind, to_branch, constraint}` of its `next_step`, so a sentence writes \"its own move, require ^4.4.51\" without reading another finding (§5.3, round 2).", {
    'kind': open_set(['replace', 'find-alternative', 'tag', 'require', 'update', 'raise-php', 'test', 'blocked', 'no-tag', 'no-move', 'no-fix', 'no-single-fix'],
                     "The holder's `next_step.kind` (an open set, as `next_step.kind`).", HYPHEN),
    'to_branch': nul("The holder's `next_step.to_branch`.", ref('branchLabel')),
    'constraint': nul("The holder's `next_step.constraint`.", {'type': 'string'}),
})
defs['holder'] = nul(("The holder's own finding in this report, for display only (no score reads it; §5.3): `{verdict, lead, flag_ids[], replacement, next_step}`. "
                      "Null for the root, and for a holder that is not a finding of this report."),
    obj("A holder's finding, restated.", {
        'verdict': ref('verdict'),
        'lead': nul("The holder's `lead`.", ref('maintenanceFlag')),
        'flag_ids': arr("The holder's counted flag ids, in flag order (every list of bare flag ids is `flag_ids`, §8.2 \"One name, one type\").", ref('flag'), uniqueItems=True),
        'replacement': nul("The holder's `replacement` (a Composer package name), so an abandoned holder reads \"held by abandoned symfony/x (replacement: …)\".", ref('packageName')),
        'next_step': nul("The holder's own move; null when the holder's finding has no move (score 0).", HOLDER_MOVE),
    }))
defs['heldBy'] = obj("One link that excludes the move or the fix (§5.3 \"held_by[] entries\"): the root's or a locked package's `require`, `require-dev` or `conflict`.", {
    'source': closed(['root', 'package'], 'Who holds it: `root` (composer.json) or `package` (a locked package). Closed (§8.2).'),
    'package': nul('The locked package that holds it; null for `source: root`.', ref('packageName')),
    'version': nul("That package's locked version; null for `source: root`.", {'type': 'string'}),
    'link': closed(['require', 'require-dev', 'conflict'], 'The link that excludes it. Closed (§8.2).'),
    'constraint': d('The constraint as the link writes it, quoted and never interpreted ("requires ^2.0").', {'type': 'string', 'minLength': 1}),
    'holder': ref('holder'),
})
defs['phpCheck'] = obj(("What the release a move names needs from PHP (§5.3): set on every move that names a branch or a tag (`update require raise-php blocked tag`, I18) "
                        "and on `security.gets`. A sentence prints its php clause only when `requires` is set; it names the project's lowest PHP from `run.project_php_lowest`."), {
    'requires': nul('The php constraint the release declares (`>=8.1.0`); null when it declares none (round 4).', {'type': 'string', 'minLength': 1}),
    'project_allows': nul("Whether the project's `require.php` admits it; null when lockrot could not compare (no `require.php`, or no php on the branch row), never \"does not allow\".", {'type': 'boolean'}),
    'target_runs': nul('Whether the target PHP (`run.target_php`) runs it; null when lockrot could not compare.', {'type': 'boolean'}),
    'raise_to': nul('The value to write into `require.php` (`>=8.1`); null when the project floor already admits the release.', {'type': 'string', 'minLength': 1}),
    'raise_size': nul('How far the floor moves: closed `major minor patch` (§8.2); null when no raise is needed.', closed(['major', 'minor', 'patch'])),
})

# the score
# one known-values list per part, so a strict reader never takes `lead` for a security term's role
TERM_ROLE = open_set(['lead', 'corroborating'], ("The maintenance term's role (§3.2): `lead` (the first counted maintenance flag, in full) or `corroborating` (every other counted "
                     "maintenance flag, at its share). Open with `x-known-values` (§8.2), so a model change cannot force report-3."))
TERM_ROLE_SECURITY = open_set(['security'], ("The security term's role (§3.2): `security` (the deciding advisory). Open with `x-known-values` (§8.2), so a model change "
                              "cannot force report-3."))
defs['termMaintenance'] = obj("A maintenance term (§3.2 \"The structured basis\"): one counted maintenance flag. `points` = `weight` ÷ `divisor` (I5); `contribution` is after every modifier.", {
    'part': closed(['maintenance'], 'The discriminator of `terms[]` (closed, §8.2): this term counts in the maintenance part.'),
    'flag': ref('maintenanceFlag'),
    'role': TERM_ROLE,
    'weight': d("The flag's points in the model (`run.score_model.flags[].points`; I5).", INT1),
    'divisor': d('1 for the lead, 4 for a corroborating flag in score model 1 (the share `rules[corroborating-share].share`); `divisor` 4 ⇔ corroborating (I5). A JSON integer.',
                 dict(INT1, **{'x-known-values': [1, 4]})),
    'points': d('`weight` ÷ `divisor`, before any halving (§3.2). A JSON integer: every quarter of a weight is whole.', INT0),
    'contribution': d("The term's share of `exact` after every modifier (reach, then dev), in half points; Σ `terms[].contribution` = `exact` (I1).", ref('halfPoints')),
})
defs['termSecurity'] = obj("The security term (§3.2): the deciding advisory, the one with the most points, ties by severity order, then the lowest id (§5.4). `points` = `weight` × `multiplier` (I5).", {
    'part': closed(['security'], 'The discriminator of `terms[]` (closed, §8.2): this term counts in the security part.'),
    'flag': closed(['vulnerable'], 'Always `vulnerable`: the security part has one flag (§2.1).'),
    'role': TERM_ROLE_SECURITY,
    'advisory': d('The deciding advisory: joins the S9 row whose `deciding` is true (§8.2 "Join by id, never by index").', ref('advisoryId')),
    'severity': d("The deciding advisory's severity, which is not always `security.worst` (§5.4 \"The deciding severity is not always the worst\").", ref('severity')),
    'fix_kind': d("The deciding advisory's fix kind (its S9 row's `fix.kind`); `fix` is always the S9 object and `fix_kind` always this string (§8.2).", ref('fixKind')),
    'weight': d("The severity's points (`run.score_model.severities[].points`).", INT1),
    'multiplier': d('2 when the fix is `none` or `blocked` (no reachable fix), else 1, in score model 1 (I8). A JSON integer.', dict(INT1, **{'x-known-values': [1, 2]})),
    'points': d('`weight` × `multiplier`, before the dev halving (security is never halved for reach).', INT1),
    'contribution': d("The term's share of `exact` after the dev modifier, in half points.", ref('halfPoints')),
})
defs['term'] = d("One counted fact's arithmetic, discriminated on `part` (§3.2, §7.6): a TypeScript union on `part`, never on position. No key is null-padded.",
                 {'oneOf': [ref('termMaintenance'), ref('termSecurity')]})
defs['modifier'] = obj("One halving, in application order (§3.2): listed whenever its fact holds and a term exists, `before == after` when it removes nothing (then it is left out of `score.text`).", {
    'reason': open_set(['transitive', 'unreached', 'dev'], "The fact that halves (§3.2): `transitive`, `unreached` (not direct, empty chain), `dev` (packages-dev). An open set (§8.2)."),
    'applies_to': open_set(['maintenance', 'total'], "What it halves: the `maintenance` part (reach) or the `total` (dev). An open set (§8.2)."),
    'divide_by': d('The divisor (2 in score model 1, `rules[divide-reach|divide-dev].by`); printed by the grammar, never a literal of the grammar (§3.2).', dict(INT1, **{'x-known-values': [2]})),
    'before': d('The value it halves, in half points.', ref('halfPoints')),
    'after': d('The value after it, in half points; the last `after` is `exact` (I9).', ref('halfPoints')),
})
PART_ALONE = obj('The band the part would reach alone (§3.2): `alone.total` = ⌊`contribution`⌋ (I10).', {
    'total': d('⌊contribution⌋.', INT0),
    'verdict': nul('A grade, or null when the part is 0 (a part has no score-0 word; the page writes "no maintenance flag counts").', ref('grade')),
})  # the maintenance part's; the security part words its own
defs['partMaintenance'] = obj("The maintenance part (§3.2 `parts`).", {
    'status': open_set(['counted', 'none', 'accepted', 'not_judged'], ("`counted` ⇔ the part has a term (I4); else why it is 0, by precedence `accepted` (a flag fired and "
                       "every fired flag is accepted, a lock-only flag on unread metadata included), then `not_judged` (no repository metadata, nothing fired), then "
                       "`none` (no maintenance flag fired). An open set.")),
    'contribution': d('Σ of the part\'s terms\' contributions (I4), in half points.', ref('halfPoints')),
    'alone': PART_ALONE,
})
defs['partSecurity'] = obj("The security part (§3.2 `parts`): the maximum over counted advisories.", {
    'status': open_set(['counted', 'clear', 'unchecked'], ("`counted` ⇔ an advisory counts (I4); else why it is 0: `clear` (complete lookup, nothing counted), "
                       "`unchecked` (`security.status`'s word: the lookup did not complete; round 4). An open set (§8.2).")),
    'contribution': d("The security term's contribution, in half points (0 without a term).", ref('halfPoints')),
    'alone': obj('The band the part would reach alone: `alone.total` = ⌊`contribution`⌋ (I10).', {
        'total': d('⌊contribution⌋.', INT0),
        'verdict': nul('A grade, or null when the part is 0 (a part has no score-0 word; the page writes "no advisory counts").', ref('grade')),
    }),
    'of': d('How many advisories count (`advisories_now` sums to it under a baseline).', INT0),
    'tied': arr("The other advisories with the deciding advisory's points, in the tie order (severity, then the lowest id first; §3.2 \"Deciding ties by id\").", ref('advisoryId'), uniqueItems=True),
})
defs['band'] = obj("Where `total` sits among the bands (§7.6): rendered as \"critical from 32\" and \"4 points short of critical\".", {
    'floor': d("The floor of the verdict's band (`run.score_model.bands[].floor`).", INT1),
    'next': nul('The next band up; null at critical.', ref('grade')),
    'to_next': nul("`next`'s floor − `total`, an integer measured from `total`, at least 1; null at critical.", INT1),
})
defs['ifCounted'] = obj("An engine rerun with the accepted flag counted, under the allowlist entry's whole accepted set (§2.3); never computed by a consumer (§7.11).", {
    'total': d('The total the rerun gives; ≥ `score.total` (I13) and ≥ 1: a counted flag always scores (attack round 1: report-2 had accepted 0).', INT1),
    'verdict': d('The rerun\'s grade (a counted flag always grades).', ref('grade')),
    'role': open_set(['lead', 'corroborating'], "The role the flag would take (an open set, as `terms[].role`)."),
    'at_least': d('True only for an accepted `stale` decided without S4 (`degree.liveness_complete` false): a lower bound, printed "at least 8, medium" (§2.3).', {'type': 'boolean'}),
    'modifiers': arr("The rerun's own halvings, as `score.modifiers` lists them (reach, then dev; `before == after` when one removes nothing): a score-0 finding has no "
                     "`score.modifiers`, so a page says \"its maintenance counts half\" beside the rerun's number from this list (attack round 3).", ref('modifier')),
})
ZERO_OR_GRADE = [{'properties': {'total': {'enum': [0]}, 'verdict': {'enum': ['unknown', 'finished', 'ok']}}},
                 {'properties': {'total': {'minimum': 1}, 'verdict': {'enum': GRADES}}}]
ZERO_OR_GRADE_DESC = 'A rerun at 0 reads a score-0 word; a rerun at 1 or more reads its grade (attack round 1).'
defs['acceptedFlag'] = obj("A fired maintenance flag the allowlist accepts (§2.3): listed, weighs 0, reaches no gate, and says what it would add. Who accepted it is `finding.allowlist` (one vocabulary).", {
    'flag': ref('maintenanceFlag'),
    'weight': d("Its model points (`run.score_model.flags[].points`).", INT1),
    'if_counted': ref('ifCounted'),
})
defs['withoutRow'] = obj("A counterfactual (§3.2 `without[]`): an engine rerun on the flags derived again from the signals left once the removed fact's `raised_by` signals go, under the entry's whole accepted set; never subtraction.", {
    'remove': obj('What the row removes.', {
        'kind': closed(['flag', 'advisory'], '`flag` (one row per counted flag when the finding has two or more terms; `vulnerable` removes every advisory) or `advisory` (the deciding advisory, whenever two or more count). Closed (§8.2).'),
        'id': d('The flag id or the advisory id removed.', {'type': 'string', 'minLength': 1}),
    }),
    'revealed': arr('The liveness word the re-derivation brings back once `abandoned` hides it no longer (§3.2, round 2), with its role there.', obj('A revealed flag.', {
        'flag': ref('maintenanceFlag'),
        'role': open_set(['lead', 'corroborating', 'accepted'], "Its role in the rerun; `accepted` when the allowlist entry accepts it (round 3). An open set (§8.2)."),
    })),
    'total': d('The rerun total; never above `score.total` (I12).', INT0),
    'verdict': ref('verdict'),
    'lead': nul('The rerun\'s lead; null when it has none.', ref('maintenanceFlag')),
    'deciding_advisory': nul("The rerun's deciding advisory id; null when it has none. Not the S9 row's boolean `deciding`.", ref('advisoryId')),
    'at_least': d('True when the rerun is a lower bound (§2.3 "Lower bounds"): a `without[abandoned]` row read without S4 (liveness incomplete, S2 absent or high), '
                  'and a `without[pinned]` row on a branch snapshot when a tag could be left behind: the branch table was not read, or S8\'s rule on some branch row '
                  'fires or cannot be dated.', {'type': 'boolean'}),
}, allOf=[{'description': ZERO_OR_GRADE_DESC, 'anyOf': ZERO_OR_GRADE}])
SCORE_TEXT = rendered("The score line, `ScoreText` text grammar 1 (§3.2 \"The one grammar\"); evaluates back to `exact`, its head to `total` (I17b). JSON writes `÷ ¼ ×` as \\u escapes. Never empty.",
                      ['score.terms', 'score.modifiers', 'score.accepted', 'score.exact', 'score.total', 'score.rounded_down'])
SCORE_TEXT['minLength'] = 1
SCORE_ZERO_TEXT = rendered("The score-0 line, `ScoreText` text grammar 1 (§3.2): `0`, then the accepted flags (`0 (left-behind accepted)`).", ['score.total', 'score.accepted'])
defs['scoreGraded'] = obj("The graded score shape (§3.2, §7.6): told from the score-0 shape by the presence of `terms` (`'terms' in score`; `total` alone cannot discriminate).", {
    'model': d('The score model id the score was computed under (`run.score_model.id`); grades compare only between equal models (§8.2).', INT1),
    'total': d('⌊`exact`⌋ (I2): the score, a JSON integer ≥ 1 on this shape.', INT1),
    'exact': d('The score before the single floor, in half points (§3.2 "exact"); at least 1 on this shape (attack round 1).', dict(HALF, minimum=1)),
    'rounded_down': d('`exact` ≠ `total` (I2).', {'type': 'boolean'}),
    'band': ref('band'),
    'decided_by': open_set(['maintenance', 'security', 'combination', 'either'], ("Which part decides the band (§3.2): `maintenance`/`security` when the total's band equals that part's "
                           "alone-band and not the other's, `combination` when it equals neither, `either` when it equals both. An open set (§8.2): show an unknown value as its id.")),
    'parts': obj('The two parts (§3.2 `parts`).', {'maintenance': ref('partMaintenance'), 'security': ref('partSecurity')}),
    'terms': arr("One entry per counted fact, in flag order: the lead first, the security term last, each flag at most once (§8.2 \"Ordering guarantees\"). The single arithmetic source.", ref('term'), minItems=1),
    'modifiers': arr('Each halving in application order: reach, then dev (I9).', ref('modifier')),
    'accepted': arr('The accepted flags, in flag order (§2.3).', ref('acceptedFlag')),
    'without': arr('Counterfactuals, in terms order, then the deciding advisory (§8.2).', ref('withoutRow')),
    'text': SCORE_TEXT,
})
defs['scoreZero'] = obj("The score-0 shape `{model, total: 0, exact: 0, accepted[], text}` (§3.2): no arithmetic to show, only what was accepted (`0 (left-behind accepted)`).", {
    'model': d('The score model id (`run.score_model.id`).', INT1),
    'total': d('0.', {'type': 'integer', 'enum': [0]}),
    'exact': d('0.', {'type': 'integer', 'enum': [0]}),
    'accepted': arr('The accepted flags, in flag order (§2.3).', ref('acceptedFlag')),
    'text': SCORE_ZERO_TEXT,
}, **{'not': {'required': ['terms']}})
defs['score'] = d("The score basis as data (§3.2, §7.6): two shapes, discriminated by the presence of `terms` (I4: the score-0 shape appears exactly when there is no term).",
                  {'oneOf': [ref('scoreGraded'), ref('scoreZero')]})

# the move
MOVE_KINDS = ['replace', 'find-alternative', 'tag', 'require', 'update', 'raise-php', 'test', 'blocked', 'no-tag', 'no-move', 'no-fix', 'no-single-fix']
defs['ifApplied'] = nul(("The score after the move (§5.6): an engine rerun on the finding's facts minus `clears[]` plus `adds[]`. Null for `replace find-alternative test blocked` "
                         "and the `no-*` kinds (the package leaves, or nothing is cleared). Never claims that Composer resolves the move."),
    obj('The rerun after the move.', {
        'total': d('The rerun total; ≤ `score.total` unless `adds` is not empty (I14).', INT0),
        'verdict': ref('verdict'),
        'lead': nul("The rerun's lead; null when none.", ref('maintenanceFlag')),
        'deciding_advisory': nul("The rerun's deciding advisory id; null when none.", ref('advisoryId')),
        'at_most': d('True ⇔ `unverified` is not empty: an upper bound, printed "at most 16, high" (I14).', {'type': 'boolean'}),
        'assumes': arr(("The conditions the rerun takes as given (§5.6): `clears_hold` always, `composer_resolves` when `held_by` or `composer_moves_because` is not empty, "
                        "`no_new_advisories` when the batched lookup of the move's own versions did not run (§5.3, critic round)."),
                       open_set(['clears_hold', 'composer_resolves', 'no_new_advisories'], 'An assumption; an open set (§8.2, round 2).'), uniqueItems=True, minItems=1),
    }, allOf=[{'description': ZERO_OR_GRADE_DESC, 'anyOf': ZERO_OR_GRADE}]))
defs['latest'] = nul("The tag the move names, on `tag`, `blocked` (pinned) and `test` moves (§5.6): for a branch-alias snapshot (`5.4.x-dev`) the newest tag on its own line, "
                    "else the package's newest tag; else null.", obj('The tag the move names.', {
    'version': d('The tag: the newest on the snapshot\'s own line, else the package\'s newest.', {'type': 'string', 'minLength': 1}),
    'released': nul("Its release date; null only when the branch table lists the tag undated (a snapshot's own-line tag whose row has no date): then "
                    "`relation` is null and the facts that need the date (old-promise, left-behind) are in the move's `unverified`.", ref('dateTime')),
    'is_installed': d('Whether it is the installed version ("— <v> is its newest release").', {'type': 'boolean'}),
    'relation': nul("How the tag relates to the installed snapshot (S6's `tag_relation`): `older newer same`, open (§8.2, round 3); null when the installed version is no snapshot.",
                    {'type': 'string', 'pattern': SNAKE, 'x-known-values': ['older', 'newer', 'same']}),
    'snapshot_time': nul("The installed snapshot's commit date (S6's `snapshot_time`); null when the installed version is no snapshot or the lock carries no time.", ref('dateTime')),
}))
defs['replacement'] = nul("The replacement a `replace` move names (§5.6 `replacement`, split in round 3, `text` → `suggestion` in round 4); null on every other kind.", obj('A replacement.', {
    'package': nul(("The suggestion when it is a Composer package name (`vendor/name`) other than the package itself, else null; `finding.replacement` equals it on every `replace` move (I21)."),
                   ref('packageName')),
    'suggestion': d('The replacement as the registry or the lock wrote it: data, not a sentence (no `text` key holds data, §8.2). Text, never markup (§7.9).', {'type': 'string', 'minLength': 1}),
    'url': nul("The registry page of `package`, or the suggestion itself when it is an http(s) URL, else null. Show a link only when it is a string; never build one.", {'type': 'string', 'pattern': '^https?:\\/\\/'}),
    'named_by': open_set(['repository', 'lock'], "Who named it (S1's `marked_by`): an open set (§8.2, round 3)."),
    'finding': nul("The replacement's own finding when it is graded in this report, `{verdict, lead, flag_ids}` (filled as `holder` is); else null.", obj('A graded replacement.', {
        'verdict': ref('grade'),
        'lead': nul("Its lead.", ref('maintenanceFlag')),
        'flag_ids': arr('Its counted flag ids, in flag order.', ref('flag'), uniqueItems=True),
    })),
}))


# does the newest stable release of each direct dependent still require the package?
defs['throughEntry'] = obj("One direct dependent of a transitive package, and whether its newest stable release still requires the package.", {
    'package': d('The direct dependent: a requirement of the root that reaches the package (`direct_dependents`, in its order).', ref('packageName')),
    'installed_version': nul('Its locked version; null when the lock does not list it.', {'type': 'string', 'minLength': 1}),
    'installed_requires': nul("Whether its installed release names the package itself in `require`; null when that release was not read.", {'type': 'boolean'}),
    'newest_stable': nul("Its newest stable release on the run date, by version; null when none was read.", obj('A release.', {
        'version': d('The version as its repository lists it.', {'type': 'string', 'minLength': 1}),
        'released': d('When it was released.', ref('dateTime')),
    })),
    'newest_requires': nul("Whether that newest release still names the package in `require`. Null exactly when `newest_requires_unmeasured` is set.", {'type': 'boolean'}),
    'newest_requires_unmeasured': nul(("Why `newest_requires` is null, and, when `installed_requires` is null too, why that is null (one reason for both "
                       "readings of the dependent): `not_from_composer_repository` (the dependent is outside every registry), `releases_unknown` (its "
                       "metadata did not come), `installed_unlisted` (its installed release is not among the releases read, such as a branch snapshot), "
                       "`through_other_package` (its installed release does not require the package itself: it arrives through another package), "
                       "`no_stable_release` (no stable release on the run date). An open set: read an unknown reason as another reason it was not checked."),
                      {'type': 'string', 'pattern': SNAKE, 'x-known-values': ['not_from_composer_repository', 'releases_unknown', 'installed_unlisted',
                                                                              'through_other_package', 'no_stable_release']}),
}, allOf=[{'description': '`newest_requires` is null exactly when `newest_requires_unmeasured` is set.',
           'anyOf': [{'properties': {'newest_requires': {'type': 'null'}, 'newest_requires_unmeasured': {'type': 'string'}}},
                     {'properties': {'newest_requires': {'type': 'boolean'}, 'newest_requires_unmeasured': {'type': 'null'}}}]},
          {'description': 'A measured entry has a newest stable release and an installed release that requires the package.',
           'anyOf': [{'properties': {'newest_requires': {'type': 'null'}}},
                     {'properties': {'installed_requires': {'enum': [True]}, 'newest_stable': {'type': 'object'}}}]}])


def move_props(with_also):
    p = {
        'kind': open_set(MOVE_KINDS, ("The move (§5.6 \"Candidate moves\"). An open set with `x-known-values`: an unknown kind falls back to `text`. A `blocked` move is worded by `source`: "
                         "`pinned` (the newest tag) or another (the fix's branch)."), HYPHEN),
        'source': d('The flag whose move this is (§5.6).', ref('flag')),
        'merged': arr('The other sources merged into this move when two moves name the same branch (§5.6 rule 4), in flag order.', ref('flag'), uniqueItems=True),
        'fix_kind': nul(("The security fix kind beside the move kind when `source` or `merged` holds `vulnerable`, or the move clears every counted advisory (round 3); else null "
                         "(`upgrade` is the move `require`, §8.2)."), ref('fixKind')),
        'reason': nul(("Set exactly on `no-move` (I18): `package_quiet` (stale), `no_higher_release` and `higher_undated` (left-behind), `releases_unknown` (vulnerable, round 4), "
                       "`metadata_not_read` (pinned: its releases were never read, so lockrot cannot say whether a tag exists) and `local_package` (pinned: installed from a "
                       "local path, the project's own package) (attack round 1). An open set (§8.2)."),
                      {'type': 'string', 'pattern': SNAKE, 'x-known-values': ['package_quiet', 'no_higher_release', 'higher_undated', 'releases_unknown', 'metadata_not_read', 'local_package']}),
        'replacement': ref('replacement'),
        'quiet_years': nul('On a `silent` `find-alternative` move, the years nothing was released (a years field, §2.1); else null.', ref('years')),
        'to_branch': nul('The branch moved to; null when the move names none.', ref('branchLabel')),
        'version': nul(("The lowest release the constraint admits (twig: `v3.27.0`); for an `update` the release installed, for a `tag` the tag. Null when it is not known "
                        "(releases not read; corpus-model nulls are ‡ in the fixtures) and on `blocked` (§7.6)."), {'type': 'string', 'minLength': 1}),
        'newest': nul("The branch's newest tag (what `composer update` gets on it); null when the move names no branch.", {'type': 'string', 'minLength': 1}),
        'constraint': nul('Set exactly on `require` and `raise-php` (I18): the tightest constraint of a merge (§5.6 rule 4).', {'type': 'string', 'minLength': 1}),
        'latest': ref('latest'),
        'php_check': nul('Set on `update require raise-php blocked tag` (I18); null on the kinds that name no branch or tag.', ref('phpCheck')),
        'crosses_major': nul("Whether the move crosses a major; for a branch-alias snapshot (`5.4.x-dev`) the snapshot's own line is the installed branch, so on a `tag` "
                             "move from `pinned` false says the tag is the newest on the snapshot's own line; null when the move names no branch, or the installed version "
                             "has none (a `dev-main` snapshot).", {'type': 'boolean'}),
        'held_by': arr("The links that exclude the move (§5.3); `upgrade` ⇒ not empty where lockrot read the links.", ref('heldBy')),
        'clears': arr("What the move clears, each with its basis (§5.6). `clears`, `unverified` and `leaves` are disjoint and together hold every counted fact (I14b).", ref('clearedFact')),
        'unverified': arr('Facts the move could not be checked against (§5.6): counted facts it may not clear, and a flag it may raise that lockrot could not date.', ref('unverifiedFact')),
        'adds': arr("Facts the move would raise that would count: neither fired now (counted or accepted) nor accepted by the finding's allowlist entry; disjoint from "
                     "`clears`, `unverified` and `leaves` (I14b).", ref('addedFact')),
        'leaves': arr('Counted facts the move neither clears nor leaves unverified (§5.6).', ref('fact')),
        'clears_all': d('`leaves` and `unverified` both empty (§7.6): "it clears all three" is read, not counted.', {'type': 'boolean'}),
        'if_applied': ref('ifApplied'),
        'composer_moves_because': arr(("Why the move needs more than the project's own command, one clause each: `holders` (`held_by` names a package), `crosses_major` (a "
                                       "`require`, `raise-php` or `tag` move to a new major), `transitive` (not direct, `direct_dependents` not empty, and `held_by` names none of "
                                       "them), `unreached` (not direct and no direct dependent: lockrot found no path from the requirements it walked). `transitive` and `unreached` "
                                       "are set on every kind that names an action on the package (`require raise-php tag replace find-alternative test`): the packages that "
                                       "require it act, or the project replaces them. Closed."),
                                      closed(['holders', 'crosses_major', 'transitive', 'unreached']), uniqueItems=True),
        'commands': arr(("The ordered commands a user runs, each an argv list (round 4), rendered per shell by §5.6's quoting (POSIX, cmd.exe, PowerShell); `[]` for a transitive "
                         "`require` and for the kinds without a command; a transitive `raise-php` holds only its php step (`composer require php:>=8.1 --no-update`, attack round 1). The lead move of a transitive package then adds one `composer why-not <dependent> <version>` per `through` entry whose `newest_requires` is false. "
                         "A page renders it as written and never builds a command from `constraint`."),
                        arr('One command as its argument list (`["composer", "require", "php:>=8.1", "--no-update"]`).', {'type': 'string', 'minLength': 1}, minItems=1)),
    }
    if with_also:
        p['through'] = arr(("On the lead move only: one entry per direct dependent of a transitive package, in `direct_dependents` order; `[]` when the "
                            "package is direct or has no direct dependent. For each dependent whose newest release no longer requires the package, "
                            "`commands` ends with `composer why-not <dependent> <version>`, which says what holds the dependent back."), ref('throughEntry'))
    if with_also:
        p['also'] = arr("The other part's move, the same object without `also` (§5.6 rule 3); empty when the lead move's `clears` and `unverified` hold every fact of that part.", ref('alsoMove'))
    p['text'] = rendered('The `do:` line, `MoveText` (§5.6, §7.11; versioned by `run.text_grammar`); never empty. A renderer that does not know `kind` (or a `no-move` `reason`) prints this string (§7.9).',
                         [('next_step' if with_also else 'next_step.also[]'), 'package', 'direct', 'direct_dependents', 'run.target_php', 'run.project_php_lowest'])
    p['text']['minLength'] = 1
    return p


defs['move'] = obj("The single best move (§5.6): evidence, never the verdict and never a gate input; the only place a move is published (`security.move_in` points here, I19).", move_props(True))
defs['alsoMove'] = obj("A move that follows the lead move in `also[]` (§5.6 rule 3): the move object without `also`.", move_props(False))

# security
defs['branchFixes'] = obj(("How many counted advisories one branch fixes, and the way out on it (§7.6 the details block `fixes`, §5.3 `installed_branch_fixes`; "
                            "round 4 names). The installed row's `fixes` is `security.installed_branch_fixes` (I19)."), {
    'fixed': d("On a branch that holds a release above the installed one: the counted advisories the branch's newest release lies outside (the branch has a lower bound "
               "for them); one a later release on the branch reintroduces is not fixed on it. 0 on any other branch.", INT0),
    'unknown': d('Counted advisories whose fix lockrot could not judge (releases not read, no affected range): "not known", never "not fixed".', INT0),
    'of': d('Counted advisories in all.', INT1),
    'fix_kind': nul("The class of the branch's candidate (`update`, `upgrade`, `raise-php` or `blocked`, see `lowest`). The row, a move to that release and the per-advisory "
                    "class of the same release agree; null when `fixed` is 0.", ref('fixKind')),
    'lowest': nul(("The branch's candidate (§5.3): the easiest release, then the lowest, from the branch's lower bound up to its newest release, the range in which every release "
                   "lies outside every range the branch fixes. The release a move to the branch names. Null when `fixed` is 0, and when the whole branch fixes them and no "
                   "single release is named (a move then requires the branch, `^<major>.<minor>`)."), {'type': 'string'}),
    'newest': nul("The branch's newest release, what `composer update` installs on it; null when the metadata lists no row for the branch.", {'type': 'string', 'minLength': 1}),
    'held_by': arr("The links that exclude the branch's candidate (see `lowest`), as a move's `held_by[]`; `[]` when nothing holds it.", ref('heldBy')),
    'if_applied': nul(("The score after a move to the branch's candidate (see `lowest`), judged as a move is: an engine rerun on the facts minus what that move clears. On an "
                       "`update` row the move is `composer update`. Null when `fixed` is 0 or `fix_kind` is `blocked`."),
                      obj('The rerun.', {
                          'total': d('The rerun total.', INT0), 'verdict': ref('verdict'),
                          'at_most': d('True when the move leaves a fact unverified (an undated release): the total is an upper bound.', {'type': 'boolean'}),
                          'assumes': arr("The conditions the rerun takes as given, as a move's `if_applied.assumes`: `clears_hold` always, `composer_resolves` when Composer must "
                                         "move other packages, `no_new_advisories` when the batched lookup did not run.",
                                         open_set(['clears_hold', 'composer_resolves', 'no_new_advisories'], 'An assumption; an open set.'), uniqueItems=True, minItems=1),
                      }, allOf=[{'description': ZERO_OR_GRADE_DESC, 'anyOf': ZERO_OR_GRADE}])),
})
defs['ignoredAdvisory'] = obj("An advisory Composer's audit ignore lists match (§5.1): not counted, never in S9 (round 4), listed here on every `security` shape.", {
    'id': ref('advisoryId'),
    'cve': nul('Its CVE, null when it has none.', {'type': 'string'}),
    'title': nul('Its title, without a leading `<cve>: `.', {'type': 'string'}),
    'severity': ref('severity'),
    'by': open_set(['policy.advisories', 'audit.ignore', 'audit.ignore-severity'], ("The Composer setting that ignores it (SPEC §4.1: `policy.advisories` on 2.10+, `audit.ignore` or "
                   "`audit.ignore-severity` on 2.4–2.9). An open set: read an unknown key as another setting."), DOTTED),
    'matched': open_set(['id', 'cve', 'remote_id', 'package', 'severity'], ("What the entry matched on (SPEC §4.1): the advisory `id`, its `cve`, its `remote_id`, the `package`, "
                        "or the `severity`. An open set.")),
    'reason': nul("The reason the policy entry gives, the project's words (data, never inside a machine string); null when it gives none.", {'type': 'string'}),
})
SEC_COMMON = {
    'check': open_set(['complete', 'partial', 'not_run'], ("How the advisory lookup went (§5.1, §5.5): `complete`, `partial`, `not_run` (round 4, underscore). "
                      "Composer's policy never stops the lookup. An open set (§8.2).")),
    'complete': d('`check` is `complete`.', {'type': 'boolean'}),
    'unchecked_reason': nul(("Why the lookup did not complete: `offline composer_too_old install_time_budget lookup_failed`, and (C75) "
                             "`not_from_composer_repository` (outside the composer-repositories scope), `unparseable_version`, `no_feed`; an open set (§8.2, round 4); null on a complete lookup."),
                            {'type': 'string', 'pattern': SNAKE, 'x-known-values': ['offline', 'composer_too_old', 'install_time_budget', 'lookup_failed',
                                                                                  'not_from_composer_repository', 'unparseable_version', 'no_feed']}),
    'ignored': arr("The advisories Composer's audit policy ignores (§5.1; round 4 moves them here from S9).", ref('ignoredAdvisory')),
    'ignored_count': d('The length of `ignored` (I21).', INT0),
}
defs['securityVulnerable'] = obj("`status: vulnerable` (§7.6): whenever an advisory counts, even on a partial lookup. `vulnerable`'s facts live here and in the S9 rows (§2.1).", dict({
    'status': closed(['vulnerable'], 'The discriminator (closed, §8.2): an advisory counts.'),
}, **{
    # an advisory counts only when a lookup returned it, so this shape's lookup is
    # `complete` or `partial` (never `not_run`), and an incomplete one stopped part-way (`install_time_budget`
    # `lookup_failed`; `offline` and `composer_too_old` look nothing up). Still open sets: the strict
    # reading holds lockrot's output to the values this shape can take
    'check': open_set(['complete', 'partial'], ("How the advisory lookup went: `complete`, or `partial` (a Composer repository did not return advisories, or "
                      "the install-time budget ran out part-way); an advisory counts only when a lookup returned it. An open set.")),
    'complete': SEC_COMMON['complete'],
    'unchecked_reason': nul("Why the lookup did not complete: `install_time_budget` or `lookup_failed`, an open set; null on a complete lookup.",
                            {'type': 'string', 'pattern': SNAKE, 'x-known-values': ['install_time_budget', 'lookup_failed']}),
    'worst': d("The worst counted severity; severity gates and the pill tone read it (§5.4).", ref('severity')),
    'counts': obj('Counted advisories per severity, every severity present.', {k: d(f'Counted `{k}` advisories.', INT0) for k in SEVERITIES}),
    'ignored': SEC_COMMON['ignored'], 'ignored_count': SEC_COMMON['ignored_count'],
    'fix_kind': d("The `fix_kind` of the move `move_in` names (round 3; I19), else, with no move, the hardest per-advisory kind (§5.3).", ref('fixKind')),
    'installed_branch_fixes': nul("The installed branch row's `fixes` (§5.3, round 4; I19); null exactly when `branch` is (a snapshot).", ref('branchFixes')),
    'move_in': nul("Where the move that clears the advisories is published: `next_step` or `also` (`next_step.also[0]`), or null when no move clears them (§5.3; replaces revision 3's copy `move`). Closed (§8.2, schema round): it points into this document's own structure.",
                   closed(['next_step', 'also'])),
    'gets': nul(("What `composer update <pkg>` installs on the installed branch, judged against the target, never `require.php` (§5.3, round 3): `{version, php_check, clears[], clears_all}`; "
                 "null when there is no newer release on the installed branch, or no installed branch."),
                obj("What composer update installs.", {
                    'version': nul('The release; null when the target cannot run it (`php_check.target_runs` false).', {'type': 'string'}),
                    'php_check': ref('phpCheck'),
                    'clears': arr('The advisories it clears (an `update` move clears exactly these, I19).', ref('clearedFact')),
                    'clears_all': d('Whether it clears every counted advisory.', {'type': 'boolean'}),
                })),
    'partial': nul(("When the move is not `update` (a `no-single-fix` or `no-fix` move included) and the installed branch's newest release is itself `update`-class and clears some but not every advisory: that release, "
                    "judged exactly as an `update` move to it is (the flags it settles too), and the score after it; null otherwise, and null when `next_step` or an `also[]` move "
                    "already is `update` to this release, whose `if_applied` is then the one number for the command."),
                   obj('A partial update.', {
                       'version': d('The release `composer update` installs.', {'type': 'string', 'minLength': 1}),
                       'clears': arr("What it clears, each with its basis, as an `update` move's `clears[]`: the maintenance flags the release settles (`old-promise` when it came after the "
                                     "target PHP's GA) and some of the advisories (a sentence counts the `advisory` entries).", ref('clearedFact'), minItems=1),
                       'unverified': arr("The counted flags it could not be checked against (an undated release), as an `update` move's `unverified[]`.", ref('unverifiedFact')),
                       'clears_all': d('Always false: it clears some, not every advisory.', {'type': 'boolean', 'enum': [False]}),
                       'if_applied': obj('An engine rerun after it, on the facts minus `clears[]`, with the conditions it takes as given, as a move\'s `if_applied`.', {
                           'total': d('The rerun total.', INT0), 'verdict': ref('verdict'),
                           'at_most': d('True exactly when `unverified` is not empty: the total is an upper bound.', {'type': 'boolean'}),
                           'assumes': arr("`clears_hold` always, `no_new_advisories` when the batched lookup (§5.3), which asks this release's own advisories too, did not run.",
                                          open_set(['clears_hold', 'composer_resolves', 'no_new_advisories'], 'An assumption; an open set.'), uniqueItems=True, minItems=1),
                       }, allOf=[{'description': ZERO_OR_GRADE_DESC, 'anyOf': ZERO_OR_GRADE}]),
                   })),
}))
defs['securityClear'] = obj("`status: clear` (§7.6): nothing counts and the lookup was complete (I21: `clear` only on a complete lookup).", {
    'status': closed(['clear'], 'The discriminator (closed, §8.2).'),
    'check': d('Always `complete` on this shape (I21).', {'type': 'string', 'enum': ['complete']}),
    'complete': d('Always true on this shape.', {'type': 'boolean', 'enum': [True]}),
    'unchecked_reason': d('Always null on this shape.', {'type': 'null'}),
    'ignored': SEC_COMMON['ignored'], 'ignored_count': SEC_COMMON['ignored_count'],
})
defs['securityUnchecked'] = obj("`status: unchecked` (§7.6, round 4): nothing counts and the lookup did not complete, so nothing is promised clear.", {
    'status': closed(['unchecked'], 'The discriminator (closed, §8.2).'),
    # a complete lookup with nothing counted is `clear`, so this shape's lookup never is
    'check': open_set(['partial', 'not_run'], ("How the advisory lookup went: `partial` or `not_run`; a complete lookup with nothing counted is `clear`. "
                      "An open set.")),
    'complete': SEC_COMMON['complete'], 'unchecked_reason': SEC_COMMON['unchecked_reason'],
    'ignored': SEC_COMMON['ignored'], 'ignored_count': SEC_COMMON['ignored_count'],
})
defs['security'] = d("The finding's advisories (§5.3, §7.6), told apart by `status` (closed: `vulnerable clear unchecked`). Two shapes: `vulnerable` carries the counts, the fix and the move pointer; `clear` and `unchecked` share the short shape.",
                     {'oneOf': [ref('securityVulnerable'), ref('securityClear'), ref('securityUnchecked')]})

# flags on a finding
defs['headline'] = obj(("The pill qualifier, chosen by lockrot, never by the page (§2.1): `{unit, value, source}`. `unit` and `source` are closed (§8.2); "
                        "the branches below type `value` and `source` per unit."), {
    'unit': closed(['years', 'php', 'reason', 'advisories'], 'What `value` measures. Closed.'),
    'value': d('A years field (`years`, null for a liveness word with no dated reading), the PHP major the release was written for (`php`), a reason id worded by the page (`reason`), or the count of counted advisories (`advisories`).',
               {'type': ['number', 'string', 'null']}),
    'source': nul('Which reading the years come from (`release push branch`, closed); null for every unit but `years`.', closed(['release', 'push', 'branch'])),
}, anyOf=[
    {'description': '`years` (silent, stale: the larger of the S2 and S4 years; left-behind: the branch): a value always has its source (attack round 1).',
     'properties': {'unit': {'enum': ['years']}, 'value': {'oneOf': [ref('years'), {'type': 'null'}]}},
     'anyOf': [{'properties': {'value': {'type': 'null'}}}, {'properties': {'source': {'type': 'string'}}}]},
    {'description': '`php` (old-promise: S5 `written_for_php`).', 'properties': {'unit': {'enum': ['php']}, 'value': {'type': 'integer', 'minimum': 1}, 'source': {'type': 'null'}}},
    {'description': "`reason` (abandoned: `archived` wins over `marked`; pinned: S6's reason).", 'properties': {'unit': {'enum': ['reason']}, 'value': {'type': 'string', 'pattern': SNAKE,
        'x-known-values': ['archived', 'marked', 'branch_snapshot', 'no_stable_release']}, 'source': {'type': 'null'}}},
    {'description': '`advisories` (vulnerable: the count of counted advisories).', 'properties': {'unit': {'enum': ['advisories']}, 'value': {'type': 'integer', 'minimum': 1}, 'source': {'type': 'null'}}},
])
LIVENESS_COMPLETE = d('"S2 and S4 were both read" (§2.3, round 4): false exactly when a `checks_skipped[]` or `checks_missing[]` entry blocks S2 or S4.', {'type': 'boolean'})
defs['degreeAbandoned'] = obj("`abandoned`'s degree: which sources fired (§2.2: S3 is a degree of abandoned, not a flag).", {
    'reasons': arr('The sources: `marked` (S1) and/or `archived` (S3). Closed (§8.2).', closed(['marked', 'archived']), minItems=1, uniqueItems=True),
    'liveness_complete': LIVENESS_COMPLETE,
})
defs['degreeLiveness'] = obj("`silent`'s and `stale`'s degree.", {'liveness_complete': LIVENESS_COMPLETE}, **{'not': {'required': ['reasons']}})
defs['findingFlag'] = obj("A fired flag (§2.1, §7.6): facts only, no weight or points (`terms[]` holds them); every fired flag in flag order, accepted in place (§8.2).", {
    'id': ref('flag'),
    'role': open_set(['lead', 'corroborating', 'security', 'accepted'], ("The flag's term role, or `accepted` (I16): a label. Whether the flag counts is the join "
                     "`id` ∈ `score.terms[].flag`, never this word (attack round 1: a later role, counted or not, reads correctly by the join). Open with `x-known-values` (§8.2), "
                     "so a model change cannot force report-3.")),
    'baseline': ref('factBaseline'),
    'signal_ids': arr('The ids of the signals the flag\'s sentence reads (`signals[].data`; one source per fact, §2.1); never empty.', ref('signalId'), uniqueItems=True, minItems=1),
    'degree': d('Only facts no single signal holds (§2.1): `abandoned {reasons, liveness_complete}`, `silent`/`stale` `{liveness_complete}`, else null.',
                {'oneOf': [ref('degreeAbandoned'), ref('degreeLiveness'), {'type': 'null'}]}),
    'headline': ref('headline'),
    'summary': dict(rendered('The flag sentence, `FlagSentence` (§7.11); never empty. The `vulnerable` sentence also reads `security`, `branch` and the deciding term\'s `severity` in `score.terms`.', ['flags[].signal_ids', 'signals[].data', 'flags[].degree.liveness_complete', 'security', 'branch', 'score.terms']), minLength=1),
}, anyOf=[  # the flag id fixes its degree and its headline unit, in both published schemas
    {'description': '`abandoned`: reasons in its degree, headline `reason` archived or marked.',
     'properties': {'id': {'enum': ['abandoned']}, 'degree': ref('degreeAbandoned'),
                    'headline': {'properties': {'unit': {'enum': ['reason']}, 'value': {'enum': ['archived', 'marked']}}}}},
    {'description': '`silent`, `stale`: liveness degree, headline in years from S2 or S4.',
     'properties': {'id': {'enum': ['silent', 'stale']}, 'degree': ref('degreeLiveness'),
                    'headline': {'properties': {'unit': {'enum': ['years']}, 'source': {'enum': ['release', 'push', None]}}}}},
    {'description': '`pinned`: headline `reason` (S6\'s).', 'properties': {'id': {'enum': ['pinned']}, 'degree': {'type': 'null'}, 'headline': {'properties': {'unit': {'enum': ['reason']}}}}},
    {'description': '`left-behind`: headline in years of the branch.',
     'properties': {'id': {'enum': ['left-behind']}, 'degree': {'type': 'null'}, 'headline': {'properties': {'unit': {'enum': ['years']}, 'source': {'enum': ['branch']}}}}},
    {'description': '`old-promise`: headline `php`.', 'properties': {'id': {'enum': ['old-promise']}, 'degree': {'type': 'null'}, 'headline': {'properties': {'unit': {'enum': ['php']}}}}},
    {'description': '`vulnerable`: no degree, its baseline is null (its advisories carry theirs), headline `advisories`; its role is not constrained (open).',
     'properties': {'id': {'enum': ['vulnerable']}, 'degree': {'type': 'null'}, 'baseline': {'type': 'null'}, 'headline': {'properties': {'unit': {'enum': ['advisories']}}}}},
])

# checks
# two lists: one shared entry would give each field the union of both vocabularies
defs['checkMissing'] = obj("A check that did not run and raised S10 (S10's `unchecked[]` entry, restated as `checks_missing[]`; `--fail-on=unchecked` reads it).", {
    'check': open_set(['repository_activity', 'release_dates', 'releases'], ("S10's check id: `repository_activity` (S3, S4), `release_dates` (the package's "
                      "age, dated only by a shared commit), `releases` (S9's fix part: releases could not be read). An open set.")),
    'reason': open_set(['no_token', 'anonymous_budget', 'install_time_budget', 'rate_limit', 'fetch_failed', 'offline', 'undated_releases', 'releases_unknown'],
                       'Why it did not run (§2.3, §7.9 page vocabulary). An open set: read an unknown reason as another reason.'),
    'blocks': arr('The signals that could not be read without it.', ref('signalId'), minItems=1, uniqueItems=True),
})
defs['checkSkipped'] = obj("A check lockrot chose not to run (§2.3): a decision, not a gap; it raises no S10 and reaches no `unchecked` gate.", {
    'check': open_set(['repository_activity', 'release_metadata', 'advisories', 'release_branch'], ("`repository_activity` (blocks S3, S4), `release_metadata` (S2, S8), "
                      "`advisories` (S9), `release_branch` (S8 on a branch snapshot, which has no release branch; attack round 1). An open set.")),
    'reason': open_set(['allowlisted', 'no_repository', 'not_from_composer_repository', 'offline', 'rate_limit', 'fetch_failed', 'anonymous_budget', 'install_time_budget',
                        'no_token', 'unavailable', 'not_found', 'branch_snapshot', 'unparseable_version'],
                       ('Why (§2.3, §7.9 page vocabulary): `unparseable_version` an installed version Composer cannot parse. '
                        'An open set: read an unknown reason as another reason.')),
    'blocks': arr('The signals it left unread.', ref('signalId'), minItems=1, uniqueItems=True),
})

# allowlist, metadata, baseline, gate on a finding
defs['allowlist'] = nul("The allowlist entry that accepts maintenance flags of this package (§6.5, revision 3); null when none does. `allowlist_reason` stays as an alias for report-1 readers.",
    obj('An allowlist entry.', {
        'by': closed(['builtin', 'project', 'type'], "Who accepted: the built-in finished list, the project's `extra.lockrot.ignore[]`, or an entry lockrot makes for a package type. Closed (§8.2); the one vocabulary for who accepted a flag."),
        'pattern': nul('The entry\'s package pattern; null exactly on a `type` entry.', {'type': 'string', 'minLength': 1}),
        'version': nul('The version the entry is limited to; null when it names none.', {'type': 'string', 'minLength': 1}),
        'reason': d("The reason as written: lockrot's sentence (`reason_by: lockrot`) or the user's words, data never entered into a machine string (§2.3).", {'type': 'string'}),
        'reason_id': nul("An id for every lockrot-authored reason (`php-fig-interfaces`, `symfony-polyfills`, `type-metapackage`, …), so a page words it itself; null for a user's reason. Open (§8.2).",
                         {'type': 'string', 'pattern': HYPHEN, 'x-known-values': ['php-fig-interfaces', 'php-fig-utilities', 'symfony-polyfills', 'symfony-extension-polyfills', 'symfony-packs',
                                                                                   'getallheaders-polyfill', 'random-compat-empty', 'type-metapackage', 'type-symfony-pack']}),
        'reason_by': closed(['user', 'lockrot'], 'Whose words `reason` is. Closed (§8.2).'),
        'expires': nul('The date the entry stops accepting; null when it does not expire.', ref('date')),
        'flag_ids': nul("The flags the entry lists (config-2 `ignore[].flags`, echoed under the one name `flag_ids`); null for an entry that accepts every maintenance flag.",
                        arr('Listed maintenance flags.', ref('maintenanceFlag'), minItems=1, uniqueItems=True)),
    }))
defs['metadata'] = obj("How the repository metadata went (§7.6, revision 3): `note` stays as its rendering.", {
    'status': closed(['read', 'not_from_composer_repository', 'unavailable', 'not_found'], ("Closed (§8.2). `read` exactly when `maintenance_judged` is true (schema round); a package installed from vcs, path or an archive is "
                     "`not_from_composer_repository` whatever its verdict, and its `release_metadata` entry (S2, S8 not run) is in `checks_skipped[]`.")),
    'reason': nul('Why it did not come (`MetadataFailure::REASONS`, the open set of report-1\'s run notes); null when read.', ref('metadataFailureReason')),
    'message': nul("Foreign text — a repository's, a host's or Composer's own words, redacted as report-1's notes redact them. Not contract. Null when read.", {'type': 'string'}),
})
WORSE = obj('A fact\'s severity and fix kind at one time.', {'severity': ref('severity'), 'fix_kind': ref('fixKind')})
defs['findingBaseline'] = nul(("Where the finding stands against the baseline (§6.4 \"finding.baseline as data\"); null without a baseline file, and null for a score-0 "
                              "finding the baseline holds no entry for (the writer stores graded findings only; attack round 1). A graded finding with no entry has "
                              "`status: new`, `recorded: null`."),
    obj('A baseline standing.', {
        'status': closed(['known', 'new', 'worsened'], 'The standing (§6.4). Closed (§8.2).'),
        'recorded': nul("What the entry holds, as the file stores it; null when the baseline holds no entry for the package (`status: new`).", obj('A recorded entry.', {
            'lead': nul("The entry's first flag in flag order (report-1's `previous_verdict`, §8.4); null when the entry lists no flag (advisories only).", ref('maintenanceFlag')),
            'flags': nul(("The flags the entry lists, keyed by maintenance flag id (an object at the root, in baseline-2 and here, §8.2); null when the entry lists no "
                          "flag (an advisory-only entry: an empty map is never written, attack round 1)."),
                         {'type': 'object', 'minProperties': 1, 'additionalProperties': False,
                          'properties': {f: obj('One recorded flag.', {'known_since': d('The date it was first known (baseline-2 stores a date for every fact).', ref('date'))})
                                         for f in MAINT}}),
            'advisory_count': d('The number of advisory ids the entry stores (round 3).', INT0),
            'first_seen': d('The date the package first entered the baseline, as the file stores it (attack round 1).', ref('date')),
            'target_php': d('The target PHP minor the baseline file was written for (its `target_php`).', {'type': 'string', 'pattern': '^[0-9]+\\.[0-9]+$'}),
            'fix_model': d("The fix model the entry's fix kinds were classified under (the file's `fix_model`). Fixes are compared (`fix_lost`) only when it and `target_php` equal the run's.", INT1),
        })),
        'advisories_now': obj("The counted advisories by their state now, summing to `parts.security.of` (round 3).", {
            'known': INT0, 'new': INT0, 're_rated': INT0, 'fix_lost': INT0}),
        'worsened_by': arr("Every fact that makes the finding worse than the entry (§6.4, round 3: revision 3's `unaccepted[]`).", obj('A worsening fact.', {
            'kind': ref('factKind'),
            'id': d('The flag id or the advisory id.', {'type': 'string', 'minLength': 1}),
            'why': open_set(['new', 're_rated', 'fix_lost'], ("Why it worsens the finding. One value per fact, by precedence `new`, then `re_rated`, then `fix_lost`: a re-rated advisory whose fix "
                                                             "was also lost reads `re_rated`, and `recorded.fix_kind` beside `now.fix_kind` shows the lost fix. An open set sharing values with `factBaseline.state`.")),
            'recorded': nul('The advisory as recorded; null for a flag and for a new advisory.', WORSE),
            'now': nul('The advisory now; null for a flag.', WORSE),
        })),
    }))
for k in ('known', 'new', 're_rated', 'fix_lost'):
    defs['findingBaseline']['oneOf'][0]['properties']['advisories_now']['properties'][k] = d(f'Counted advisories whose state is `{k}`.', INT0)
defs['gateFact'] = obj("A fact whose record is `error` for one gate value (§6.2 \"The marks are data\").", {
    'kind': open_set(['flag', 'advisory', 'signal'], "`flag`, `advisory`, or `signal` (an `unchecked` value names each blocked signal once; joins `checks_missing[].blocks[]`). An open set (§8.2)."),
    'id': d('The flag id, the advisory id or the signal id.', {'type': 'string', 'minLength': 1}),
})
defs['findingGate'] = obj("Where the finding stands against the gate values (§6.1, §6.2, §6.4). Text formats render the gate basis line (`GateText`) from `basis`.", {
    'reaches_fail_on': d("Whether the finding reaches some value of `run.gates[]`, reading the score and the flags before the baseline (0.13's meaning, §6.1).", {'type': 'boolean'}),
    'fails': d('Whether it fails the run: it reaches a value, `exempt_by` is null and the root `gate.fail_on_applied` is true; a grade value reads the gate score.', {'type': 'boolean'}),
    'exempt_by': nul("What keeps a reaching finding from failing: `baseline` (a `known` finding, or a `worsened` one whose gate score stays below the value). Set only under a baseline entry (I15b). An open set.",
                     {'type': 'string', 'pattern': SNAKE, 'x-known-values': ['baseline']}),
    'by': arr("One entry per value of `run.gates[]` the finding fails (`by[].value` ∈ `run.gates[]`), naming the facts whose records are `error` (§6.2; `Output\\RecordMarks` is the single producer).",
              obj('One failing value.', {
                  'value': d('A value of `run.gates[]`.', {'type': 'string', 'pattern': HYPHEN}),
                  'kind': open_set(['grade', 'flag', 'vulnerable', 'unchecked'], "The value's kind, as `run.gates[].kind`. An open set (§8.2), reason-like (underscores; attack round 1)."),
                  'facts': arr('The facts that trip it; never empty for a failing value (I15b).', ref('gateFact'), minItems=1),
              })),
    'basis': nul("The baseline arithmetic (§6.4 \"The gate basis as data\"); null without a baseline entry (the gate score is then `score.total`).", obj('The gate basis.', {
        'score': d('The gate score, min(`score.total`, 2 × `new.total`) (I15).', INT0),
        'verdict': nul("The gate score's band; null exactly at gate 0 (I21).", ref('grade')),
        'cap': d('2 × `new.total`.', INT0),
        'limited_by': closed(['score', 'cap', 'equal'], "`score` (the full score is lower), `cap` (the new facts bound it; always when `new.total` is 0), `equal`. Closed (§8.2)."),
        'new': obj("What the facts the baseline does not cover add, in the score's own term shape (§6.4); its maintenance terms are a prefix of the score's (I15).", {
            'total': d('⌊exact⌋.', INT0),
            'exact': ref('halfPoints'),
            'rounded_down': d('`exact` ≠ `total`; GateText renders it as ScoreText does.', {'type': 'boolean'}),
            'terms': arr('The uncovered flags and the highest-scoring advisory the baseline does not know.', ref('term')),
            'modifiers': arr('The halvings, as on the score.', ref('modifier')),
        }),
    })),
})

# signals
def sig_data(desc, props):
    return obj(desc, props)


defs['s1'] = sig_data("S1 (§2.1): marked abandoned by its repository or by the lock.", {
    'marked_by': closed(['repository', 'lock'], 'Who marked it (revision 3). Closed (§8.2).'),
    'replacement': nul('The replacement as the registry or the lock wrote it: free text, a package name or a URL (data; `next_step.replacement` classifies it). Text, never markup.', {'type': 'string', 'minLength': 1}),
    'replacement_url': nul("packagist.org's page for the replacement when it is a package name packagist.org named; else null. Show a link only when it is a string.", {'type': 'string', 'pattern': '^https?:\\/\\/'}),
})
defs['s2'] = sig_data('S2 (§2.1): no release for years (report-1, unchanged).', {
    'last_release': ref('dateTime'), 'last_version': nul('The version `last_release` dates.', {'type': 'string'}),
    'years': ref('years'), 'dated_by': ref('datedBy')})
FORGE = d("The forge kind, as the repository reference lockrot asked names it (`RepoRef::forge()`; a self-hosted GitLab is `gitlab`): `github gitlab bitbucket` known; "
          "Forgejo/Gitea and GitHub Enterprise are on the roadmap. An open set (§8.2). Never null: S3 and S4 exist only when lockrot asked a forge.",
          {'type': 'string', 'pattern': SNAKE, 'x-known-values': ['github', 'gitlab', 'bitbucket']})
defs['s3'] = sig_data('S3 (§2.1): the repository is archived; a degree of `abandoned`.', {
    'repo': d('The repository path on the host.', {'type': 'string'}), 'host': d('The host as Composer names it.', {'type': 'string'}), 'forge': FORGE})
defs['s4'] = sig_data('S4 (§2.1): no push (or commit) for years.', {
    'last_push': ref('dateTime'), 'repo': d('The repository path on the host.', {'type': 'string'}), 'host': d('The host as Composer names it.', {'type': 'string'}),
    'years': ref('years'), 'forge': FORGE,
    'activity': ref('activityEvent')})
defs['s5'] = sig_data("S5 (§2.1): written for an older PHP major and released before the target's major existed (report-1, unchanged).", {
    'target_php': {'type': 'string'}, 'target_major': d("The first release of the target's major (`8.0` for `8.4`).", {'type': 'string'}),
    'ga_date': d('GA of `target_major`.', ref('date')), 'php_constraint': {'type': 'string'},
    'written_for_php': nul("The PHP major of the constraint's lower bound; null when it has none (`*`).", {'type': 'integer'}), 'released': ref('dateTime')})
for k in ('target_php', 'php_constraint'):
    defs['s5']['properties'][k] = d({'target_php': 'The target PHP the release is held against.', 'php_constraint': "The release's php constraint as written."}[k], {'type': 'string'})
defs['s6'] = sig_data('S6 (§2.1): pinned to a branch snapshot, or no tagged release.', {
    'version': d('The installed version.', {'type': 'string'}),
    'reason': open_set(['branch_snapshot', 'no_stable_release'], "Which of S6's cases fired. An open set (report-1)."),
    'has_stable_release': nul('Whether the repository lists any tagged version; null when no metadata was loaded.', {'type': 'boolean'}),
    'last_stable_release': nul('The newest dated tagged release; null when there is none lockrot trusts.', ref('dateTime')),
    'last_stable_version': nul('The version `last_stable_release` dates; null exactly when it is.', {'type': 'string'}),
    'last_stable_dated_by': ref('datedBy'),
    'snapshot_time': nul("For `branch_snapshot`, the lock's `time`; else null.", ref('dateTime')),
    'tag_relation': nul("How the newest tag relates to the snapshot (`older newer same`; revision 3), open (§8.2); null when there is no tag or no snapshot time.",
                        {'type': 'string', 'pattern': SNAKE, 'x-known-values': ['older', 'newer', 'same']}),
})
defs['s7'] = sig_data(("S7: a direct requirement pulling in graded transitive packages; context, not a flag. Every graded package that is not direct and is reached from "
                       "1 to `exposure_rule.max_fan_in` direct requirements is named by each of them, a package graded by its advisories alone included."), {
    'flagged': d('How many graded packages it names.', INT1),
    'packages': arr('The graded packages it pulls in, in the report\'s rank order.', obj('A pulled-in package.', {
        'package': ref('packageName'),
        'chain': arr('From this requirement down to the package.', ref('packageName')),
        'verdict': d("The package's grade (report-1's cause word moved to `lead`; §8.4).", ref('grade')),
        'lead': nul('Its lead.', ref('maintenanceFlag')),
        'flag_ids': arr('Its counted flag ids, in flag order (critic round: never `flags`).', ref('flag'), minItems=1, uniqueItems=True),
    }), minItems=1),
})
defs['s8'] = sig_data("S8 (§2.1): the installed release branch stopped while a higher one kept releasing. Every key always written; `branch` equals `finding.branch` (I21).", {
    'branch': ref('branchLabel'),
    'branch_last_release': ref('dateTime'),
    'branch_last_version': {'type': 'string'},
    'years': ref('years'),
    'newest_branch': d('The newest release branch, as `ReleaseBranch::label()` writes it (report-1).', ref('branchLabel')),
    'newest_version': {'type': 'string'},
    'newest_release': ref('dateTime'),
    'newest_php': nul("The newest branch's php requirement; null when it requires none.", {'type': 'string'}),
    'newest_within_reach': d('Whether its php admits the project floor and the target.', {'type': 'boolean'}),
    'newest_years': d('Age of `newest_release` on the run clock (round 2).', ref('years')),
    'floor_php': nul('What holds the newest branch back; null when within reach.', {'type': 'string'}),
    'floor_source': nul('Which floor `floor_php` is (`project target`), open; null when within reach.', {'type': 'string', 'pattern': SNAKE, 'x-known-values': ['project', 'target']}),
    'reachable_branch': nul('The releasing higher branch within reach; null when none is.', ref('branchLabel')),
    'reachable_version': nul('Its newest version; null with `reachable_branch`.', {'type': 'string'}),
    'reachable_release': nul('Its release date; null with `reachable_branch`.', ref('dateTime')),
    'reachable_php': nul("Its php requirement (revision 3); null when there is no reachable branch or its release declares none.", {'type': 'string'}),
    'reachable_admits': nul("Whether `reachable_php` admits the project floor and the target (revision 3); null when there is no reachable branch.", obj('Admission.', {
        'project': nul("null: no `require.php` to compare.", {'type': 'boolean'}),
        'target': nul('null: no php declared.', {'type': 'boolean'}),
    })),
    'reachable_years': nul('Age of `reachable_release` on the run clock (round 2); null with `reachable_branch`.', ref('years')),
    'suggested_constraint': nul('A caret constraint following the reachable branch; null when there is none.', {'type': 'string'}),
    'dated_by': ref('datedBy'),
})
for k, desc in (('branch_last_version', "The installed branch's newest version."), ('newest_version', "The newest branch's newest version.")):
    defs['s8']['properties'][k] = d(desc, {'type': 'string'})
defs['s9fix'] = obj("The advisory's fix (§5.3 \"Per advisory\"): `fix` is always this object, `fix_kind` always the string (§8.2). Null keys below mean the kind names no fixing release.", {
    'kind': ref('fixKind'),
    'to_branch': nul('The branch of the fixing release; null for `unknown` and `none` (no fixing release known).', ref('branchLabel')),
    'version': nul("The lowest fixing release on `to_branch`; null when not known (releases not read; corpus-model nulls are ‡). Not the move's `version` (§5.3).", {'type': 'string', 'minLength': 1}),
    'newest': nul("`to_branch`'s newest tag; null for `unknown` and `none`.", {'type': 'string', 'minLength': 1}),
    'on_installed_branch': nul("Whether the easiest fix is on the installed branch; null for `unknown` and `none`.", {'type': 'boolean'}),
    'php': nul("The fixing release's php requirement; null for `unknown` and `none`, or when it declares none.", {'type': 'string'}),
    'held_by': arr('The links that exclude the fixing release (§5.3); `upgrade` ⇒ not empty where lockrot read the links.', ref('heldBy')),
    'reason': nul(("Set for `unknown` (`releases_unknown`, `affected_range_unknown`, `not_from_composer_repository`) and `none` (`no_release_outside_range`), else null. An open set "
                   "(round 3). The corpus model's `branch_table_missing` (‡, no details block) is a model-only value lockrot never writes, so it is not listed."),
                  {'type': 'string', 'pattern': SNAKE, 'x-known-values': ['releases_unknown', 'affected_range_unknown', 'not_from_composer_repository', 'no_release_outside_range']}),
})
defs['s9row'] = obj("One counted advisory on the installed version (§7.6 S9 `data`). Ignored advisories are in `security.ignored[]` (round 4).", {
    'id': ref('advisoryId'),
    'cve': nul('Its CVE; null when it has none.', {'type': 'string'}),
    'title': nul(('Its title without a leading `<cve>: ` (round 2), so `{cve} {title}` never prints the CVE twice; trimmed, runs of whitespace collapsed (attack round 1). '
                  'Text, never markup: a page that styles backticks escapes them here (102 corpus titles hold backticks).'), {'type': 'string'}),
    'link': nul('Its advisory page; null when the repository serves none.', {'type': 'string'}),
    'reported_at': nul('When it was reported; null when the repository does not say.', ref('dateTime')),
    'severity': d('Its severity bucket (§5.2).', ref('severity')),
    'severity_published': nul('The raw word the repository published (`moderate`); null when unrated.', {'type': 'string'}),
    'affected_versions': nul('Its affected range as published; null when it has none (fix `unknown`, `affected_range_unknown`).', {'type': 'string'}),
    'counted': d('True: S9 lists counted advisories only (round 4).', {'type': 'boolean'}),
    'points': d('Severity points × multiplier, before halving (§5.3).', INT1),
    'deciding': d('True on exactly the advisory the security term names.', {'type': 'boolean'}),
    'fix': ref('s9fix'),
    'baseline': ref('factBaseline'),
})
defs['s9'] = sig_data("S9 (§7.6): the counted advisories on the installed version; fires exactly when an advisory counts (round 4: `select(.id==\"S9\")` means \"has a counted advisory\").", {
    'advisories': arr('The counted advisories, deciding one included.', ref('s9row'), minItems=1),
    'releases_read': d('Whether the releases were read and compared (report-1).', {'type': 'boolean'}),
    'complete': d('Whether the advisory lookup for the package completed (`security.complete`).', {'type': 'boolean'}),
})
defs['s10'] = sig_data("S10 (§2.2): a check the verdict rests on did not run; a confidence marker, never a flag. Unchanged from report-1 but for its known values.", {
    'unchecked': arr('One entry per check that did not run.', ref('checkMissing'), minItems=1),
    'blocks': arr('The union of its entries\' `blocks` (I21).', ref('signalId'), minItems=1, uniqueItems=True),
})
SIG_IDS = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10']
defs['signal'] = obj("A signal (§2.1, §7.6): S1–S10 as in report-1, with the data keys report-2 adds; typed per id by the branches below.", {
    'id': ref('signalId'),
    'level': ref('level'),
    'summary': rendered('The signal sentence, `SignalSentence` (§7.11): S1–S6 and S8 from their `data`, S7 from `data.packages[]`, S9 from `security` and the S9 rows, S10 from `data.unchecked[]`.',
                        ['signals[].data', 'security', 'branch']),
    'data': d('The facts, typed per `id`.', {'type': 'object'}),
}, anyOf=[{'properties': {'id': {'enum': [s]}, 'data': ref(s.lower())}} for s in SIG_IDS] + [{
    'description': 'A signal whose id this schema does not list, from a later release or not from lockrot: its data is any object.',
    'not': {'properties': {'id': {'enum': SIG_IDS}}}}])

defs['activityEvent'] = open_set(['push', 'commit'], "What a forge's activity date measures: the last `push` (GitHub) or the last `commit` (GitLab, Bitbucket), as `RepoRef` words it. An open set.")

# finding
FINDING = {
    'package': ref('packageName'),
    'version': d('The installed version.', {'type': 'string', 'minLength': 1}),
    'branch': nul('The installed release branch (§7.6, revision 3); null for a snapshot. Equal to S8\'s `branch` when both are set, and to the installed branch row\'s (I21).', ref('branchLabel')),
    'installed_php': obj(("What the installed release itself needs from PHP: its own `require.php` from the lock entry (the branch rows' `php` is each branch's newest "
                          "release's), and whether the target and the project admit it, as PhpFloor compares them (attack round 3). Not scored: a release the target does "
                          "not run cannot be installed there unseen, Composer refuses it; every surface says it."), {
        'requires': nul('The lock entry\'s php constraint as written; null when the entry declares none.', {'type': 'string', 'minLength': 1}),
        'target_runs': nul('Whether some version of the target PHP minor (`run.target_php`) satisfies it; null when `requires` is null or cannot be parsed.', {'type': 'boolean'}),
        'project_allows': nul("Whether the project's lowest PHP (`run.project_php_lowest`) satisfies it; null when either side is missing or cannot be parsed.", {'type': 'boolean'}),
    }),
    'verdict': ref('verdict'),
    'priority': closed(['critical', 'high', 'medium', 'low', 'none'], '`verdict` when graded, else `none` (§4.2): a deprecated alias that leaves in report-3.'),
    'lead': nul("The first counted maintenance flag; null when none counts (§4.2). Equal to 0.13's verdict word on every 0.13-flagged finding: jq reads `.lead` where it read `.verdict`.", ref('maintenanceFlag')),
    'rank': d("Position in the run's order (`run.score_model.sort`), 1 first. A report writes `findings[]` in rank order, and re-sorting by `run.score_model.sort[]` gives the same order (I20); an explain document carries the rank the package has in the run it explains. SARIF's `rank` is the opposite polarity.", INT1),
    'flags': arr('Every fired flag in flag order, accepted in place (§8.2); each id once.', ref('findingFlag'), uniqueItems=True),
    'score': ref('score'),
    'next_step': nul('The move (§5.6); null exactly at score 0 (I21).', ref('move')),
    'security': ref('security'),
    'checks_missing': arr('S10 restated (§7.6): the checks that did not run and raise S10.', ref('checkMissing')),
    'checks_skipped': arr(("The checks that did not run and raise no S10 (§2.3, round 3): `repository_activity` (allowlisted, no repository, a path package, or the "
                           "activity plan's cause for a registry-abandoned package), `release_metadata` (metadata that did not come, or a package not from a Composer repository), `advisories` "
                           "(the composer-repositories scope, an installed version Composer cannot parse), `release_branch` (a branch snapshot: S8 cannot run). "
                           "No `unchecked` gate reads it."),
                          ref('checkSkipped')),
    'maintenance_judged': d(("True exactly when `metadata.status` is `read`: repository metadata was read, so S2 and S8 ran (schema round). A finding can be graded with it false: "
                             "a package not from a Composer repository is graded on the facts the lock holds (S1 marked in the lock, S5, S6), and its score-0 words (`without[]`, "
                             "`if_counted`, `if_applied`) read `unknown`, never `ok`."), {'type': 'boolean'}),
    'metadata': ref('metadata'),
    'allowlist': ref('allowlist'),
    'from_composer_repository': d("The lock entry carries a Composer notification-url (report-1): true exactly when `origin.kind` is `packagist` or `composer`.", {'type': 'boolean'}),
    'origin': copy.deepcopy(R1['definitions']['finding']['properties']['origin']),
    'replacement': nul(("0.13's successor (report-1's key, §5.6): S1's replacement when it is a package name other than the package itself, on a finding whose `abandoned` counts; "
                        "equals `next_step.replacement.package` on every `replace` move, null elsewhere (I21)."), ref('packageName')),
    'replacement_url': nul("packagist.org's page for `replacement` (report-1); null when `replacement` is null or another registry named it.", {'type': 'string', 'pattern': '^https?:\\/\\/'}),
    'note': nul(rendered('The rendering of `metadata` (§7.6, §7.11); null when there is nothing to say.', ['metadata'])['description'], {'type': 'string'}),
    'libyears_unmeasured': copy.deepcopy(R1['definitions']['finding']['properties']['libyears_unmeasured']),
    'direct': d('A direct requirement of the run.', {'type': 'boolean'}),
    'dev': d('Listed under packages-dev in the lock.', {'type': 'boolean'}),
    'reach': closed(['direct', 'transitive', 'unreached'], '`unreached` = not direct, with an empty `chain` (§7.6). Closed (§8.2).'),
    'chain': arr('From the direct requirement down to this package; one element when direct; empty when no direct requirement reaches it (report-1).', ref('packageName')),
    'direct_dependents': arr('The direct requirements that reach it (report-1); a transitive move names them (§5.6).', ref('packageName')),
    'signals': arr('S1–S10 that fired, as report-1 orders them (§7.6).', ref('signal')),
    'evidence': rendered("The join of the counted flags' `flags[].summary` (a flag counts when a `score.terms[].flag` names it) as `<id>: <summary>` in flag order; kept for jq (§7.6). "
                         "Empty exactly on the score-0 shape (no flag counts).", ['flags[].summary', 'score.terms[].flag']),
    'allowlist_reason': nul(("report-1's key, kept (§6.5): the reason of an entry that accepts the whole package; null for a partial entry (`allowlist.flag_ids` set) and "
                             "when no entry applies. Not an alias of `allowlist.reason` (attack round 1): read that for every entry."), {'type': 'string'}),
    'data_date': nul('The time of the newest repository data the finding read (report-1); null when lockrot read none.', ref('dateTime')),
    'libyears': copy.deepcopy(R1['definitions']['finding']['properties']['libyears']),
    'baseline': ref('findingBaseline'),
    'gate': ref('findingGate'),
}
FINDING['note']['x-rendered-from'] = ['metadata']
FINDING['origin']['description'] = "Where the lock entry came from (report-1's `packageOrigin`, unchanged; §8.2 \"unchanged … origin\")."
FINDING['libyears_unmeasured']['description'] = FINDING['libyears_unmeasured']['description'].replace(
    ' Optional: documents written before 0.13.0 do not carry it; from 0.13.0 on every finding does, as a string or null.', '')
FINDING['libyears']['description'] = "Years between the installed release and the newest stable release, two decimals, at least 0; null when not measured (report-1, unchanged)."
defs['finding'] = obj("One locked package judged (§7.6 field reference): every key always written, nulls explicit; `spec_flags_r3.py` `KEYS['finding']`.", FINDING)
defs['packageOrigin'] = copy.deepcopy(R1['definitions']['packageOrigin'])
defs['packageOrigin']['required'] = ['kind', 'registry', 'package_url', 'local']
defs['packageOrigin']['properties']['local']['description'] = ("Whether Composer installed the package from the machine it ran on (a `path` or `artifact` repository, or a "
    "dist or source that is a path or `file://` URL) (report-1).")
defs['packageOrigin']['description'] = defs['packageOrigin']['description'].replace(' Open: a later minor release may add a member.', '') + ' Open: a later minor release may add a member.'

# the score model
SHARE = obj('A fraction.', {'num': d('Numerator.', INT1), 'den': d('Denominator.', INT1)})
RULE_PARAMS = {
    'order': open_set(['flag_order'], 'The order a `select` or `cover` rule walks (`flag_order`). Open.'),
    'share': d("The share a `select` or `share` rule takes (`{num, den}`: ¼ for corroborating).", SHARE),
    'table': open_set(['severities', 'zero_verdicts'], 'The model table a `lookup` or `zero` rule reads. Open.'),
    'factor': d('The factor of a `multiply` rule.', INT1),
    'when_fix': arr('The fix kinds a `multiply` rule applies on (`none blocked`).', ref('fixKind'), uniqueItems=True),
    'tie_break': arr('The tie order of a `max` rule: points desc, severity in order, id asc (§5.4).', obj('One tie-break key.', {
        'key': open_set(['points', 'severity', 'id'], 'The key. Open.'),
        'dir': open_set(['desc', 'order', 'asc'], 'The direction. Open.'),
        'collation': nul(("On an `asc` string key: `bytes` orders by UTF-8 bytes (PHP `strcmp`; in JavaScript compare code units, never `localeCompare`, "
                          "which puts `_` before `-` and digits); null on every other key. Open."), {'type': 'string', 'pattern': SNAKE, 'x-known-values': ['bytes']}),
    })),
    'by': d('The divisor of a `divide` rule.', INT1),
    'when': arr('The facts a `divide` rule applies on (`transitive unreached`, `dev`).', open_set(['transitive', 'unreached', 'dev'], 'A fact (as `modifiers[].reason`).'), uniqueItems=True),
    'of': open_set(RULE_IDS, 'The rule an `exempt` rule exempts from.', HYPHEN),
    'op': open_set(['sum'], 'The operation of a `combine` rule. Open.'),
    'ratio': d('The ratio of a `band` rule (each floor doubles).', INT1),
    'ratio_from': d('The band from which the floors double (`medium`).', ref('grade')),
    'cap_multiplier': d('The cap of a `gate` rule (`min(score, 2 × new)`).', INT1),
    'covers': open_set(['listed_and_below'], 'What a `cover` rule covers (round 3: an entry covers each flag it lists and every flag below it). Open.'),
    'accept_group': open_set(['liveness'], ("On `counted`: the exclusive group within which an `ignore[]` entry's accepted flag also accepts the flags below it "
                                            "(`liveness`: abandoned covers silent and stale, silent covers stale), so a package that ages into the word the entry "
                                            "names never scores lower. Open.")),
    'accept_covers': open_set(['listed_and_below'], "On `counted`: what an `ignore[]` entry's flag accepts within `accept_group` (each listed flag and every one below it). Open."),
}
KIND_REQUIRES = {'share': ['share'], 'lookup': ['table'], 'multiply': ['factor', 'when_fix'], 'max': ['tie_break'], 'divide': ['by', 'when'], 'exempt': ['of'],
                 'combine': ['op'], 'band': ['ratio', 'ratio_from'], 'zero': ['table'], 'gate': ['cap_multiplier'], 'cover': ['order', 'covers']}
defs['scoreRule'] = obj(("One rule of the model (§3.7 `rules[]`): in `stage` order, with its `kind`, its parameters (present by kind, below), a `why` id, a `doc` anchor and an "
                         "engine-computed `illustration`. No prose: a page keeps its words keyed by id; an unknown id renders as `<code>id</code>` with the arithmetic its kind implies."), dict({
    'id': open_set(RULE_IDS, ("The rule id, without parameters. An open set: the ids model 1 uses are listed in `x-known-values`, and a later model may add one; "
                              "an unknown id renders as `<code>id</code>`."), HYPHEN),
    'stage': d('Its place in the order of operations; stages are unique, 1–16 in model 1.', INT1),
    'kind': open_set(RULE_KINDS, 'What the rule does (§3.7). An open set (§8.2): an unknown kind renders the id and the finding\'s contribution only.'),
    'applies_to': open_set(['flags', 'maintenance', 'security', 'advisory', 'total', 'findings', 'gate'], 'What the rule acts on. Open.'),
    'why': open_set(WHY_IDS, "Why the rule exists, an id the page words; an unknown `why` id is omitted (§3.7). Open (§8.2).", HYPHEN),
    'doc': d('The anchor of its doc section under `docs` (`score-divide-reach`).', {'type': 'string', 'pattern': HYPHEN}),
    'illustration': nul(("An example lockrot computes with the same engine as every score, so a rule the report never exercises still shows its numbers; "
                         "keys vary by rule (`before`, `after`, `band_before`, `band_after`, `severity`, `flag`, `total`, …); null when the rule has none."),
                        {'type': 'object', 'additionalProperties': {'type': ['integer', 'number', 'string']}}),
}, **RULE_PARAMS), required=['id', 'stage', 'kind', 'applies_to', 'why', 'doc', 'illustration'],
    anyOf=[{'properties': {'kind': {'enum': [k]}}, 'required': KIND_REQUIRES.get(k, [])} for k in RULE_KINDS] + [{
        'description': 'A rule kind this schema does not list: no parameter is required.', 'not': {'properties': {'kind': {'enum': RULE_KINDS}}}}])
for br in defs['scoreRule']['anyOf']:  # draft-04 rejects an empty `required`
    if 'required' in br and not br['required']:
        del br['required']
defs['scoreModel'] = obj(("The score model, self-described (§3.7): ordered flags, severities, fix kinds and bands, the rules with ids and illustrations, the sort keys with JSON paths. "
                          "A \"How the score works\" panel is built from it alone. Any change to a number, a band floor, a rule's parameters, the doubling set, the sort keys or the gate "
                          "or cover rule bumps `id`."), {
    'id': d('The model id (`score.model`, `score_model_id` in baseline-2): an integer; a document with no `score_model` (report-1) counts as model 0 (§3.7).', INT1),
    'score_text_grammar': d("The version of §3.2's ScoreText grammar only. `run.text_grammar` versions the sentence grammars.", INT1),
    'flag_order': arr('The frozen flag order (§2.1).', ref('flag'), minItems=1, uniqueItems=True),
    'parts': arr('The two parts, in order.', obj('A part.', {
        'id': closed(['maintenance', 'security'], 'The part id (the vocabulary of `terms[].part`, closed).'),
        'flag_ids': arr('Its flag ids, in flag order.', ref('flag'), minItems=1),
        'combine': open_set(['lead_plus_share', 'max'], 'How its terms combine. Open (§3.7 bump policy: every value a model change can add is open).'),
    })),
    'flags': arr('One weight-table row per flag (§3.7).', obj('A flag row.', {
        'id': ref('flag'),
        'part': closed(['maintenance', 'security'], "The flag's part."),
        'points': nul('Its points; null for `vulnerable` (points come from `severities`).', INT1),
        'band': nul('The band its points reach alone (the pill tone); null for `vulnerable`.', ref('grade')),
        'can_corroborate': d('Whether it can follow another counted maintenance flag.', {'type': 'boolean'}),
        'corroborating_points': nul('Its quarter; null for `abandoned`, `silent` and `vulnerable`.', INT1),
        'max_maintenance': nul('The most corroboration can add under it as lead.', INT1),
        'max_maintenance_flags': nul('The flag set that reaches `max_maintenance` (round 4).', arr('Flags.', ref('flag'), minItems=1)),
        'raised_by': arr('The signals that raise it.', ref('signalId'), minItems=1),
        'thresholds': arr('The `run.thresholds` keys it reads.', open_set(['release-warn-years', 'release-high-years', 'push-warn-years', 'push-high-years'], 'A threshold key.', HYPHEN)),
        'basis': obj('How its points are formed (round 4).', {
            'v013_base': nul('The 0.13 band it is anchored to; null for `vulnerable`.', ref('grade')),
            'points': open_set(['band_floor', 'severities'], '`band_floor` (its points are that band\'s floor) or `severities`. Open.'),
        }),
        'points_from': nul('The table its points come from: `severities` on `vulnerable`; null on a maintenance row, whose `points` are its own (schema round: written on every row, as every report-2 key is). Open.',
                           {'type': 'string', 'pattern': SNAKE, 'x-known-values': ['severities']}),
    })),
    'severities': arr('The severity rows in the one order (critical, high, medium, unrated, low; §5.2).', obj('A severity row.', {
        'id': ref('severity'),
        'points': d('Its points.', INT1),
        'band': ref('grade'),
        'points_no_reachable_fix': d('Its points × the multiplier.', INT1),
        'band_no_reachable_fix': ref('grade'),
        'gate_rank': d('Its rank for `vulnerable-*` and re-rating (unrated has medium\'s rank, §6.4).', INT1),
        'counts_as': nul('The severity it counts as (`unrated` → `medium`); null otherwise.', ref('severity')),
        'basis': obj('How its points are formed (round 4): `{points: band_floor}`, `{points: counts_as}`, or `{points: share, of, share {num, den}}`.', {
            'points': open_set(['band_floor', 'counts_as', 'share'], 'The formation. Open.'),
            'of': d('On `share`: the severity it is a share of.', ref('severity')),
            'share': d('On `share`: the fraction.', SHARE),
        }, required=['points']),
    })),
    'fix_kinds': arr('The fix legend in ease order, with the `doubles` badge (§3.7).', obj('A fix kind row.', {
        'id': ref('fixKind'), 'ease': d('Its ease, 1 easiest.', INT1), 'doubles': d('Whether it doubles an advisory (× 2).', {'type': 'boolean'})})),
    'bands': arr('The band ruler, worst first.', obj('A band.', {'verdict': ref('grade'), 'floor': d('Its floor.', INT1)})),
    'zero_verdicts': arr(('The conditions of the three score-0 words, in evaluation precedence (the first whose condition holds applies: an allowlisted package reads '
                          '`finished` even without metadata); display order is `run.verdicts` (attack round 1).'), obj('A score-0 word.', {
        'verdict': closed(['finished', 'unknown', 'ok'], 'A score-0 verdict.'),
        'when': open_set(['allowlisted', 'no_metadata', 'otherwise'], 'When it applies. Open.')})),
    'exact_unit': d('The unit of `exact` and every contribution: 0.5.', {'type': 'number', 'minimum': 0}),
    'rounding': open_set(['floor_once'], 'How `exact` becomes `total`. Open.'),
    'max_total': d('The largest total the model can give (104), the bar\'s overflow cap.', INT1),
    'bar_max': d('The bar\'s axis maximum (64; one block = 2 points).', INT1),
    'bar_overflow': open_set(['clip'], 'How a total above `bar_max` is drawn (round 4). Open.'),
    'exclusive_groups': arr('Flags that never show together.', obj('A group.', {
        'id': open_set(['liveness', 'branch', 'release_age', 'release_push'], 'The group id. Open.'), 'flag_ids': arr('Its flag ids.', ref('flag'), minItems=2),
        'holds_unless': nul(("When the group holds only under a threshold relation: it holds unless `threshold` is below `below` (`release_push`: stale beside "
                             "left-behind needs push-warn-years below release-warn-years); null when it always holds."),
                            obj('A threshold relation.', {'threshold': open_set(['push-warn-years', 'push-high-years', 'release-warn-years', 'release-high-years'], 'A threshold id. Open.', HYPHEN),
                                                          'below': open_set(['push-warn-years', 'push-high-years', 'release-warn-years', 'release-high-years'], 'A threshold id. Open.', HYPHEN)}))})),
    'sort': arr('The default order of `findings[]` (§3.2): each key with its JSON path, direction, default for an absent path and, for the verdict, the order itself.', obj('A sort key.', {
        'key': open_set(['verdict', 'security', 'score', 'direct', 'package'], 'The key name. Open.'),
        'path': d('Its JSON path in a finding (`score.parts.security.contribution`).', {'type': 'string', 'minLength': 1}),
        'dir': open_set(['order', 'desc', 'asc', 'true_first'], 'Its direction. Open.'),
        'collation': nul(("On an `asc` string key (`package`): `bytes` orders by UTF-8 bytes (PHP `strcmp`; in JavaScript compare code units, never "
                          "`localeCompare` or `Intl.Collator`, which put `_` before `-` and digits, so `foo/bar_x` and `foo/bar-x` would swap); null on "
                          "every other key. Open."), {'type': 'string', 'pattern': SNAKE, 'x-known-values': ['bytes']}),
        'default': d('The value when the path is absent (0 on the score-0 shape); null when the path is always present.', {'type': ['integer', 'number', 'string', 'boolean', 'null']}),
        'order': d('On `dir: order`: the groups, first first (finished and ok are one group).', arr('Groups.', arr('A group.', ref('verdict'), minItems=1), minItems=1)),
    }, required=['key', 'path', 'dir', 'default', 'collation'])),
    'rules': arr('The rules in stage order (§3.7).', ref('scoreRule'), minItems=1),
    'docs': d('The page that explains this model, versioned by model (`https://lockrot.dev/verdicts/model-1/`).', {'type': 'string', 'minLength': 1}),
})

# run
R1RUN = R1['definitions']['run']['properties']
defs['gateValue'] = obj("One parsed `--fail-on` value (§6.1): `run.gates[]`.", {
    'value': d('The value as parsed: trimmed, lower-cased (`high`, `left-behind`, `vulnerable-high`, `unchecked`).', {'type': 'string', 'pattern': HYPHEN}),
    'kind': open_set(['grade', 'flag', 'vulnerable', 'unchecked'], "`grade` (0.13's `priority`), `flag` (0.13's `verdict`), `vulnerable`, `unchecked`. An open set (§8.2), reason-like (underscores, attack round 1); no kind keeps the token `verdict`."),
    'threshold': nul('The grade, the flag or the minimum severity the value names (`vulnerable` → `low`, `vulnerable-high` → `high`); null exactly for `unchecked`.', {'type': 'string', 'pattern': HYPHEN}),
}, **{'not': {'description': '`none` is the absence of a gate (§6.1, schema round): `--fail-on=none` writes `gates: []`, never a `none` entry.',
              'properties': {'value': {'description': 'The value `none`.', 'enum': ['none']}}, 'required': ['value']},
      'anyOf': [{'description': 'A known kind but `unchecked` names its threshold (attack round 1).', 'properties': {'kind': {'enum': ['grade', 'flag', 'vulnerable']}, 'threshold': {'type': 'string'}}},
                {'description': '`unchecked` names none.', 'properties': {'kind': {'enum': ['unchecked']}, 'threshold': {'type': 'null'}}},
                {'description': 'A kind this schema does not list is not constrained.', 'not': {'properties': {'kind': {'enum': ['grade', 'flag', 'vulnerable', 'unchecked']}}}}]})
RULES_USED_KNOWN = RULE_IDS
defs['run'] = obj("What the run was told to do (§7.6 \"The report-2 run keys\", `RUN_KEYS`): every key always written.", {
    'project': d("What the report calls the project (report-1).", copy.deepcopy(R1RUN['project'])),
    'root_package': d("The root package's own `name` (report-1); null where the manifest has none or no composer.json was read.", {'oneOf': R1RUN['root_package']['oneOf']}),
    'target_php': d('The target PHP minor (`8.4`) from the first link of the target chain that sets it, as `PhpReleaseDates::minorOf` writes it (`8` reads `8.0`, `8.4.7` reads `8.4`), the form baseline-2 stores; '
                    'always a string with `target_php_source` beside it (§8.2).', {'type': 'string', 'pattern': '^[0-9]+\\.[0-9]+$'}),
    'target_php_source': closed(['option', 'env', 'config', 'platform', 'runtime'], "Which link of the chain set it (`config` = `extra.lockrot.target-php`, `platform` = `config.platform.php`). Closed (§8.2)."),
    'project_php': nul("The project's `require.php` as written (report-1); never parse it.", {'type': 'string', 'minLength': 1}),
    'project_php_lowest': nul("The lowest PHP `require.php` admits: `PhpFloor`'s lower bound normalised to MAJOR.MINOR.PATCH, a fourth segment and a stability suffix dropped "
                              "(`7.2.5.0-dev` is `7.2.5`, `^7.4.0-RC1` gives `7.4.0`; `7.2.0` for `^7.2 || ^8.0.0`; an exclusive `>7.4` gives `7.4.0`, a patch the project excludes "
                              "in a minor it allows). Null exactly when `PhpFloor` has no lowest: no `require.php`, `*`, an alternative with no lower bound (`<8`), or a constraint "
                              "it cannot parse (§5.3). Print it with a trailing `.0` patch dropped.",
                              {'type': 'string', 'pattern': '^[0-9]+\\.[0-9]+\\.[0-9]+$'}),
    'lock_file': nul('The name of the lock (report-1), never its path.', {'type': 'string'}),
    'fail_on': d("The parsed values in kind order (grade, flag, vulnerable, unchecked; a list holds one value per kind), joined with `,`, no spaces, lower case (`high`, "
                 "`left-behind,vulnerable-high`, `none`): one gate writes one string whatever order it was typed in (§6.1). report-1's string type.",
                 {'type': 'string', 'pattern': '^[a-z][a-z0-9-]*(,[a-z][a-z0-9-]*)*$'}),
    'fail_on_source': closed(['option', 'env', 'config', 'default'], 'Which source gave `fail_on` (§6.1). Closed (§8.2).'),
    'gates': arr('The parsed list (§6.1), at most one value per kind, in the kind order of `fail_on`; `[]` for `--fail-on=none` (schema round).', ref('gateValue')),
    'strict_network': d("Whether `--strict-network` was on (report-1).", {'type': 'boolean'}),
    'include_dev': d("Whether packages-dev were analysed (`--dev`): the graph then walks `require-dev` too, so a reach sentence names the requirements it walked. Equal to the report's root `include_dev`.",
                     {'type': 'boolean'}),
    'mode': copy.deepcopy(R1RUN['mode']),
    'thresholds': d("The ages, in years, that separate the liveness words (report-1).", copy.deepcopy(R1RUN['thresholds'])),
    'flag_ids': arr('The seven flag ids in flag order (the vocabulary used, not a setting).', ref('flag'), minItems=1, uniqueItems=True),
    'verdicts': arr('The seven verdicts, highest first.', ref('verdict'), minItems=1, uniqueItems=True),
    'graded_verdicts': arr('The grades, highest first (replaces `flagged_verdicts`).', ref('grade'), minItems=1, uniqueItems=True),
    'signal_ids': arr('The ten check ids, for the Checks strip ("3 fired · 7 quiet").', ref('signalId'), minItems=1, uniqueItems=True),
    'fix_model': d("The fix classification's id (§8.3); baselines compare fixes only under equal fix models.", INT1),
    'text_grammar': d('The version of the sentence grammars: MoveText, GateText, FlagSentence, SignalSentence, NoteSentence, the suffix and the command renderings (§7.11, round 4). ScoreText is versioned apart, by `score_model.score_text_grammar`.', INT1),
    'score_model': ref('scoreModel'),
    'score_rules_used': {'description': ("Rule id → the number of findings on which the rule changed or decided a number (each count's predicate is a field test: "
                                         "graded findings; score-0 findings for `zero-verdicts`; every finding with a non-empty `score.accepted[]` for `counted`), null only for "
                                         "`sort`. Keyed by `score_model.rules[].id`; an id absent from it shows no count. Keys follow the rule-id grammar, so a later id validates "
                                         "and a misspelt one (`corroborating_share`) does not; no property list, so it compiles as a TypeScript index signature. `x-known-keys` "
                                         "names model 1's ids, which a strict reader takes as the only keys."),
                         'type': 'object', 'additionalProperties': False,
                         'patternProperties': {'^sort$': {'description': '`sort` orders, it changes no number: always null.', 'type': 'null'},
                                               rule_id_except('sort'): {'description': 'A count: a rule id other than `sort`.', 'type': 'integer', 'minimum': 0}},
                         'x-known-keys': RULES_USED_KNOWN},
})
defs['run']['properties']['mode']['description'] = "What the run was asked to do: `check` or `generate_baseline` (report-1). An open set."
RUN_RELATION = {'description': '`fail_on` is `none` exactly when `gates` is empty (`--fail-on=none` is the absence of a gate).',
                'anyOf': [{'properties': {'fail_on': {'enum': ['none']}, 'gates': {'maxItems': 0}}},
                          {'properties': {'fail_on': {'not': {'enum': ['none']}}, 'gates': {'minItems': 1}}}]}
defs['run']['allOf'] = [RUN_RELATION]
defs['run']['properties']['project']['description'] = "What the report calls the project: composer.json's `name`, or `extra.lockrot.project` (report-1); null where neither says."

# root blocks
COUNT = lambda what: d(what, INT0)
defs['rootFlagMaintenance'] = obj("A maintenance flag's root counts (§7.6).", {
    'carrying': COUNT('Graded findings with this flag counted.'),
    'leading': COUNT("Findings whose `lead` is this flag: the number 0.13 counted under this verdict word (`counts.<flag>` in report-1), so a series read from report-1 continues."),
    'accepted': obj('The fired flags the allowlist accepts.', {'all': COUNT('On every finding.'), 'in_graded': COUNT('On graded findings ("Accepted by the allowlist: none in flagged packages").')}),
    'by_verdict': obj('`carrying` split by grade (the html ledger).', {g: COUNT(f'Carrying findings graded `{g}`.') for g in GRADES}),
})
defs['rootFlagVulnerable'] = obj("`vulnerable`'s root counts (§7.6): it has no lead and cannot be accepted.", {
    'carrying': COUNT('Graded findings with a counted advisory.'),
    'leading': d('Always null: `vulnerable` is never a lead.', {'type': 'null'}),
    'accepted': d('Always null: advisories are accepted in Composer\'s audit policy, never by the allowlist (§6.5).', {'type': 'null'}),
    'by_verdict': obj('`carrying` split by grade.', {g: COUNT(f'Carrying findings graded `{g}`.') for g in GRADES}),
})
NOTE_CODES = ['offline', 'metadata_unavailable', 'monorepo_parent_unavailable', 'advisory_ignore_unreadable', 'advisories_disabled_by_policy',
              'advisories_unavailable', 'advisories_not_checked', 'repository_activity_not_checked', 'repository_activity_anonymous_cap',
              'repository_activity_rate_limited', 'repository_activity_unreachable', 'repository_activity_not_found', 'not_from_composer_repository']
NOTE_DEFS = {c: 'note' + ''.join(w.capitalize() for w in c.split('_')) for c in NOTE_CODES}
for c in NOTE_CODES:
    if c == 'advisories_disabled_by_policy':
        continue
    defs[NOTE_DEFS[c]] = copy.deepcopy(R1['definitions'][NOTE_DEFS[c]])
defs['noteAdvisoriesNotChecked']['description'] = ("`advisories_not_checked` (0.13's code and data, reworded, §6.2): no advisory was looked up, so no finding is graded "
    "for an advisory and each move's score after it assumes its versions carry none. One lookup per run asks every advisory of every name, so the installed "
    "versions and the moves' versions are checked together or not at all.")
defs['noteAdvisoriesDisabledByPolicy'] = obj(("`advisories_disabled_by_policy` (§5.1, §6.2): Composer's policy stops Composer blocking installs and updates on "
                                               "advisories. It only informs: lockrot still asks and counts every advisory."), {
    'policy_key': open_set(['policy', 'policy.advisories', 'policy.advisories.audit', 'COMPOSER_POLICY'],
                           'The full Composer config key, or the environment variable `COMPOSER_POLICY`. Open.', POLICY_KEY),
    'value': d('Its value as configured (`false`, `"ignore"`, `"0"`).', {'type': ['boolean', 'string']}),
})
defs['noteCode'] = copy.deepcopy(R1['definitions']['noteCode'])
defs['noteCode']['x-known-values'] = NOTE_CODES
defs['noteDetail'] = copy.deepcopy(R1['definitions']['noteDetail'])
defs['noteDetail']['description'] = ("One thing the run could not see or changed (report-1's typed notes, §6.2): `code`, `data` typed per code, `text` the sentence every format prints. "
                                     "Every note's `text` is prose rendered from its `data` (NoteSentence, §7.11).")
defs['noteDetail']['properties']['text']['x-rendered-from'] = ['note_details[].data']
defs['noteDetail']['properties']['sets_network_failures']['description'] = ("Whether this note is part of why the root `network_failures` is true (report-1); false for every code report-2 adds.")
defs['noteDetail']['anyOf'] = [{'properties': {'code': {'enum': [c]}, 'data': ref(NOTE_DEFS[c])}} for c in NOTE_CODES] + [{
    'description': 'A note whose code this schema does not list, from a later release or not from lockrot: its data is any object.',
    'not': {'properties': {'code': {'enum': NOTE_CODES}}}}]
defs['noteNotFromComposerRepository']['description'] = ("`not_from_composer_repository`: `package_count` packages carry no Composer notification-url, "
    "so lockrot read no release metadata and no repository activity for them. Equal to the report's `not_from_composer_repository`.")
defs['baselineComparison'] = copy.deepcopy(R1['definitions']['baselineComparison'])
defs['baselineComparison']['description'] = 'How the run compared with the baseline file (report-1, unchanged): the counts of findings by standing and the stale entries.'
defs['rootGate'] = obj("The run's gate (report-1's, §6.2) plus the finding counts the `gate:` line prints (§7.6).", {
    'fails': copy.deepcopy(R1['definitions']['gate']['properties']['fails']),
    'tripped_by': copy.deepcopy(R1['definitions']['gate']['properties']['tripped_by']),
    'fail_on_applied': copy.deepcopy(R1['definitions']['gate']['properties']['fail_on_applied']),
    'reaching': COUNT('Findings whose `gate.reaches_fail_on` is true ("5 findings reach it").'),
    'failing': COUNT('Findings whose `gate.fails` is true.'),
    'exempt': obj('Findings that reach and are exempt, by `exempt_by` value.', {'baseline': COUNT('Exempt by the baseline.')},
                  additionalProperties=INT0),
})
defs['rootSecurity'] = obj("The root security block (SPEC root block, §7.6, §8.2).", {
    'check': open_set(['complete', 'partial', 'not_run'], ("How the run's advisory lookup went (schema round: counted from the findings): the findings' one `security.check` value "
                      "when they share it, `partial` when they differ, `complete` for a lock with no finding. An open set.")),
    'packages': obj('Packages by `security.status`, plus those with an ignored advisory.', {
        'vulnerable': COUNT('Findings with `security.status` vulnerable.'), 'unchecked': COUNT('Findings with `security.status` unchecked (never counted as clear).'),
        'ignored': COUNT('Findings with an ignored advisory.'), 'clear': COUNT('Findings with `security.status` clear.')}),
    'advisories': obj('Advisories counted and ignored.', {'counted': COUNT('Counted advisories.'), 'ignored': COUNT('Ignored advisories.')}),
    'severities': obj('Counted advisories per severity, every severity present.', {k: COUNT(f'Counted `{k}` advisories.') for k in SEVERITIES}),
    'fixes': obj('Vulnerable findings per `security.fix_kind`, every known fix kind present (fix kinds are open: a later kind is an extra key).',
                 {k: COUNT(f'Findings whose `security.fix_kind` is `{k}`.') for k in FIX_KINDS}, additionalProperties=INT0),
    'update_now': arr('The packages whose `security.fix_kind` is `update` (§5.3).', ref('packageName')),
    'update_now_command': nul(("The one command the overview's \"Update now\" copies, as an argv list (`[\"composer\", \"update\", <update_now…>]`); null exactly when "
                               "`update_now` is empty, and then the line is hidden (attack round 1: a page never builds a command, and a bare `composer update` updates the whole lock)."),
                              {'description': 'The argv: `composer`, `update`, then each package of `update_now` in its order.', 'type': 'array',
                               'items': [{'enum': ['composer']}, {'enum': ['update']}], 'additionalItems': {'$ref': '#/definitions/packageName'}, 'minItems': 3}),
    'fix_unknown': COUNT('Vulnerable findings whose `security.fix_kind` is `unknown` (§5.3; equal to `fixes.unknown`, kept for the CLI line).'),
})
LIBY = copy.deepcopy(R1['properties']['libyears'])
LIBY['required'] = LIBY['required'] + ['packages']
LIBY['properties']['packages'] = d('The number of findings in the document (`packages_checked`): `measured` plus the `unmeasured` counts (all 108 generated documents; attack round 1 corrected this description).', INT0)
LIBY['description'] = LIBY['description'].replace(' Absent from documents written before 0.11.0.', '') + ' report-2 adds `packages`.'
# report-2 sums the published values, so a total row equals the column above it (report-1 summed the unrounded ones)
_old_rule = ("`total` is the sum of the unrounded per-finding values (so the sum of the printed two-decimal values agrees with it to within 0.005 per "
             "measured finding), `direct_requirements` the same over `direct: true`")
assert _old_rule in LIBY['description'], 'report-1 libyears wording changed'
LIBY['description'] = LIBY['description'].replace(_old_rule, ("`total` is the sum of the published two-decimal per-finding values, rounded to two decimals (report-1 summed the "
                                                              "unrounded values, so its total could differ from the sum of its own rows by up to 0.005 per finding), "
                                                              "`direct_requirements` the same over `direct: true`"))

ROOT = {
    '$schema': d('The URL of this schema: `https://lockrot.dev/schema/report-2.json`.', {'type': 'string', 'enum': ['https://lockrot.dev/schema/report-2.json']}),
    'lockrot': ref('envelope'),
    'generated_at': d('When the run ran: the run clock every years field is measured against (§2.1).', ref('dateTime')),
    'run': ref('run'),
    'activity_cache_oldest_at': copy.deepcopy(R1['properties']['activity_cache_oldest_at']),
    'activity_cache_age_hours': nul(("Hours from `activity_cache_oldest_at` to `generated_at` on the run clock, rounded to one decimal half away from zero (§7.6, schema round); "
                                     "null exactly when `activity_cache_oldest_at` is null (every answer was fetched in this run, or no activity was asked)."), {'type': 'number', 'minimum': 0}),
    'packages_checked': COUNT('Findings in the document.'),
    'packages_flagged': COUNT('Graded findings: the number at the top of the report (§8.4).'),
    'packages_multi_flag': COUNT('Graded findings with two or more counted flags (the `flags:` line, §7.6).'),
    'include_dev': d('Whether packages-dev were analysed (report-1).', {'type': 'boolean'}),
    'not_from_composer_repository': copy.deepcopy(R1['properties']['not_from_composer_repository']),
    'network_failures': d('Whether some note sets it (report-1).', {'type': 'boolean'}),
    'counts': obj('Findings per verdict, all seven required (§8.2; `.counts.<cause>` is null in report-2: read `.flags.<cause>.leading`).', {v: COUNT(f'Findings whose verdict is `{v}`.') for v in VERDICTS}),
    'abandoned': obj("`total` = `flags.abandoned.carrying`; `with_replacement` keeps report-1's meaning (the finding's `replacement` names a package), "
                     "`with_suggestion` counts the rest whose S1 suggests something that names no package.", {
        'total': COUNT('Graded findings with a counted `abandoned`.'),
        'with_replacement': COUNT("… whose `replacement` (report-1's key) names a package."),
        'with_suggestion': COUNT("… whose S1 suggests a replacement that names no package (free text or a URL; `next_step.replacement.package` null)."),
    }),
    'priorities': obj('The deprecated `priority` alias over the four grades + none (§8.2).', {p: COUNT(f'Findings whose priority is `{p}`.') for p in GRADES + ['none']}),
    'flags': obj('Per flag, all seven (§7.6): an object keyed by flag id at the root (§8.2).', {f: ref('rootFlagVulnerable' if f == 'vulnerable' else 'rootFlagMaintenance') for f in FLAGS}),
    'exposure': d("Direct requirements that pull in graded transitive packages, `[{package, flagged}]`, one per finding that carries S7, its `flagged` equal to S7's: "
                   "every graded transitive package reached from 1 to `exposure_rule.max_fan_in` direct requirements is named by each of them (vulnerable-only ones included).",
                   copy.deepcopy(R1['properties']['exposure'])),
    'exposure_rule': copy.deepcopy(R1['properties']['exposure_rule']),
    'unattributed': arr("Graded transitive packages reached from more than `exposure_rule.max_fan_in` direct requirements, vulnerable-only ones included; report-2 adds `lead` "
                         "and `flag_ids` (the counted flags), and `verdict` is the grade. With S7 they name every graded package a direct requirement reaches.",
                        obj('An unattributed package.', {'package': ref('packageName'), 'verdict': ref('grade'), 'lead': nul('Its lead (jq: read `.lead`, §8.4).', ref('maintenanceFlag')),
                                                         'flag_ids': arr('Its counted flag ids.', ref('flag'), minItems=1, uniqueItems=True),
                                                         'fan_in': d('How many direct requirements reach it.', INT1)})),
    'libyears': LIBY,
    'baseline': nul("How the run compared with the baseline file: non-null whenever the run read one (report-1's rule), its counts from the findings' `baseline.status` "
                     "(zeros allowed: a run whose remaining findings all score 0 still names the file and its stale entries); null when no baseline file was read.",
                     ref('baselineComparison')),
    'gate': ref('rootGate'),
    'security': ref('rootSecurity'),
    'data_date': nul(("The oldest non-null finding `data_date`, a datetime as on the finding (round 3); null when no finding read repository data (§7.6, schema round: "
                      "a lock with no finding, or one whose packages all came from vcs or path)."), ref('dateTime')),
    'notes': arr("The run notes' text, one per `note_details` entry at the same index.", d(NOT_CONTRACT, {'type': 'string', 'x-rendered-from': ['note_details[].data']})),
    'note_details': arr('The run notes, typed (report-1; §6.2 adds six codes).', ref('noteDetail')),
    'findings': arr('One per analysed package, in `rank` order (§3.2, I20).', ref('finding')),
}
ROOT['activity_cache_oldest_at']['description'] = R1['properties']['activity_cache_oldest_at']['description'] + ' (report-1)'

# report-1 copies that carried no description on some members (report-1 described the object, not each member)
R1_MEMBERS = {
    ('exposure', 'flagged'): 'Graded packages the requirement pulls in, as its S7 `data.flagged` (§7.6).',
    ('furthest', 'version'): "The finding's installed version.",
    ('furthest', 'libyears'): "The finding's `libyears`.",
    ('forgeRepository', 'host'): 'The host as Composer names it (`github.com`, possibly with a port or a path prefix).',
    ('forgeRepository', 'repo'): 'The path on the host (`owner/repo`, or `group/sub/project` on GitLab).',
    ('failedForgeRepository', 'host'): 'The host as Composer names it.',
    ('failedForgeRepository', 'repo'): 'The path on the host.',
    ('noteMetadataUnavailable', 'package_count'): 'Packages whose metadata did not come.',
    ('noteMetadataUnavailable', 'reasons'): 'The failures grouped by message, in the order the text lists them.',
    ('noteMetadataUnavailableReason', 'package_count'): 'Packages in this group.',
    ('noteAdvisoriesUnavailable', 'composer_repository'): "Composer's name for the repository, its URL cut as `message` cuts one.",
    ('noteAdvisoriesNotChecked', 'composer_repositories_checked'): 'Advisory-capable repositories asked before the check stopped (report-1).',
    ('noteRepositoryActivityAnonymousCap', 'checked'): 'Repositories asked.',
    ('noteRepositoryActivityAnonymousCap', 'skipped_no_token'): 'Packages that were no candidates (S10 `no_token`).',
    ('noteRepositoryActivityAnonymousCap', 'skipped_budget'): 'Candidates beyond the budget (S10 `anonymous_budget`).',
    ('noteRepositoryActivityRateLimited', 'repositories'): 'Every repository of this kind of host that got no answer, with its message.',
    ('noteRepositoryActivityUnreachable', 'repositories'): 'The repositories the host did not answer for.',
    ('noteRepositoryActivityNotFound', 'repositories'): 'The repositories the host answered 404 for.',
    ('noteNotFromComposerRepository', 'package_count'): "Packages with no Composer notification-url; equal to the root `not_from_composer_repository`.",
    ('baselineComparison', 'known'): 'Findings whose `baseline.status` is `known`.',
    ('baselineComparison', 'new'): 'Findings whose `baseline.status` is `new`.',
    ('baselineComparison', 'worsened'): 'Findings whose `baseline.status` is `worsened`.',
}
for (where, member), text in R1_MEMBERS.items():
    if where == 'exposure':
        node = ROOT['exposure']['items']['properties']
    elif where == 'furthest':
        node = LIBY['properties']['furthest_behind']['oneOf'][0]['properties']
    elif where == 'noteMetadataUnavailableReason':
        node = defs['noteMetadataUnavailable']['properties']['reasons']['items']['properties']
    else:
        node = defs[where]['properties']
    node[member] = dict({'description': text}, **{k: v for k, v in node[member].items() if k != 'description'})

# the cross-field relations, the same groups that explain-2 carries
# applied through a name map so both schemas hold one set of rules
def add_relations_r2(defs):
    import sys as _sys
    _sys.path.insert(0, HERE)
    import build_other_schemas as BO
    alone_m, alone_s = defs['partMaintenance']['properties']['alone'], defs['partSecurity']['properties']['alone']
    s9 = {'properties': {'fix': defs['s9fix']}}
    view = {
        'finding': defs['finding'], 'nextStep': defs['move'], 'move': defs['alsoMove'],
        'gateBasis': defs['findingGate']['properties']['basis'], 'findingGate': defs['findingGate'], 'band': defs['band'],
        'partAlone': alone_m, 'partMaintenance': defs['partMaintenance'], 'partSecurity': defs['partSecurity'],
        'securityVulnerable': defs['securityVulnerable'], 'securityOther': defs['securityClear'],
        'findingBaseline': defs['findingBaseline'], 'flagBaseline': defs['factBaseline'], 'allowlist': defs['allowlist'], 's9row': s9,
        'scoreGraded': defs['scoreGraded'], 'modifier': defs['modifier'], 'withoutRow': defs['withoutRow'], 'gateValue': defs['gateValue'],
        'findingFlag': defs['findingFlag'],
    }
    BO.add_relations(view)
    # the clear/unchecked shapes are two definitions here (one in explain-2): each takes the same groups
    defs['securityUnchecked']['allOf'] = copy.deepcopy(defs['securityClear']['allOf'])
    alone_s['allOf'] = copy.deepcopy(alone_m['allOf'])
    counted = s9['properties']['counted']
    defs['s9row']['properties']['counted'] = dict(counted, description=defs['s9row']['properties']['counted']['description'] + ' Always true (an enum of one).')


add_relations_r2(defs)


def add_relations_round4(defs):
    """The rules that the field descriptions state and draft-04 can say, beyond the other relation groups."""
    import build_other_schemas as BO
    P, E, NOT, NULL, STR, OBJ, MIN1, EMPTY, CONTAINS, NONE, relate = (BO.P, BO.E, BO.NOT, BO.NULL, BO.STR, BO.OBJ, BO.MIN1, BO.EMPTY, BO.CONTAINS,
                                                                          BO.NONE, BO.relate)
    MODEL_NOT_1 = P(score=P(model=NOT(1)))
    f = defs['finding']
    nomove = ['blocked', 'no-tag', 'no-move', 'no-fix', 'no-single-fix', 'replace', 'find-alternative', 'test']
    sev1 = {'critical': 32, 'high': 16, 'medium': 8, 'unrated': 8, 'low': 2}
    relate(f, ['`evidence` is empty on the score-0 shape (no flag counts).',
               'An entry that accepts the whole package (`allowlist.flag_ids` null) leaves no maintenance term and no lead.',
               "A transitive or unreached package's `require` or `tag` move writes no `composer require` command (the package itself is never required at the root); its only commands are `composer why-not` checks.",
               "Each `also[]` move of a transitive or unreached package's `require` or `tag` kind writes no command.",
               'Without a counted advisory no move carries a fix kind: `next_step.fix_kind` and every `also[].fix_kind` are null.',
               "`installed_php.requires` null (the lock entry declares no php) makes both of its answers null.",
               'An accepted flag\'s `if_counted.at_least` is true only for `stale` (decided without S4).',
               "Under score model 1 an accepted flag's `weight` is model 1's weight for it.",
               "Under score model 1 an S9 row's `points` is its severity's points, doubled for the fix kinds that double (`none`, `blocked`); a fix kind this schema does not list is not constrained.",
               'A snapshot whose release metadata was read carries the `release_branch` skip (`branch_snapshot`) in `checks_skipped`.'],
           [P(score={'required': ['terms']}), P(evidence={'maxLength': 0})],
           [P(allowlist=P(flag_ids={'not': NULL})), P(lead=NULL, score=P(terms=NONE(P(part=E('maintenance')))))],
           [P(direct=E(True)), P(next_step=P(kind=NOT('require', 'tag'))), P(next_step=P(commands={'items': {'not': {'items': [{'enum': ['composer']}, {'enum': ['require']}]}}}))],
           [P(direct=E(True)), P(next_step=P(also={'items': {'anyOf': [P(kind=NOT('require', 'tag')), P(commands=EMPTY)]}}))],
           [P(security=P(status=NOT('clear', 'unchecked'))), P(next_step=P(fix_kind=NULL, also={'items': P(fix_kind=NULL)}))],
           [P(installed_php=P(requires=STR)), P(installed_php=P(requires=NULL, project_allows=NULL, target_runs=NULL))],
           [P(score=dict(P(accepted=dict({'items': {'anyOf': [P(flag=E('stale')), P(if_counted=P(at_least=E(False)))]}}, description='Each accepted flag.')),
                         description='The score.'))],
           [MODEL_NOT_1, P(score=P(accepted={'items': {'anyOf': [P(flag=E(m), weight=E(w)) for m, w in BO.WEIGHT_M1.items()]}}))],
           [MODEL_NOT_1, P(signals={'items': {'anyOf': [P(id=NOT('S9')), P(data=P(advisories={'items': {'anyOf':
               [P(severity=E(sv), fix=P(kind=E('none', 'blocked')), points=E(2 * p)) for sv, p in sev1.items()]
               + [P(severity=E(sv), fix=P(kind=E('update', 'upgrade', 'raise-php', 'unknown')), points=E(p)) for sv, p in sev1.items()]
               + [P(fix=P(kind=NOT(*BO.FIX_KINDS)))]}}))]}})],
           [P(branch=STR), P(metadata=P(status=NOT('read'))), P(checks_skipped=CONTAINS(P(check=E('release_branch'), reason=E('branch_snapshot'))))])
    for name in ('move', 'alsoMove'):
        m = defs[name]
        relate(m, ['`quiet_years` is set only on a `find-alternative` move from `silent`, and always there; a kind this schema does not list is not constrained.',
                   '`version` is null on the kinds that name no release to install (`blocked replace find-alternative test` and the `no-*` kinds).',
                   '`to_branch` is null on the kinds that name no branch (`replace find-alternative test` and the `no-*` kinds).',
                   '`clears` is empty on the kinds that move nothing (`blocked replace find-alternative test` and the `no-*` kinds).',
                   "`php_check.requires` null (the branch declares no php) makes both of its answers null.",
                   '`php_check.raise_to` and `php_check.raise_size` are set together: a floor to write exists only when the branch\'s floor is above the project\'s lowest.'],
               [P(kind=E('find-alternative'), source=E('silent'), quiet_years={'type': 'number'}), P(kind=E(*[k for k in BO.MOVE_KINDS if k != 'find-alternative']), quiet_years=NULL),
                P(kind=E('find-alternative'), source=NOT('silent'), quiet_years=NULL), P(kind=NOT(*BO.MOVE_KINDS))],
               [P(kind=E(*nomove), version=NULL), P(kind=NOT(*nomove))],
               [P(kind=E(*[k for k in nomove if k != 'blocked']), to_branch=NULL), P(kind=NOT(*[k for k in nomove if k != 'blocked']))],
               [P(kind=E(*nomove), clears=EMPTY), P(kind=NOT(*nomove))],
               [P(php_check=P(requires=STR)), P(php_check=P(requires=NULL, project_allows=NULL, target_runs=NULL))],
               [P(php_check=P(raise_to=NULL, raise_size=NULL)), P(php_check=P(raise_to=STR, raise_size=STR))])
    relate(defs['heldBy'], '`source` root has no `package`, `version` or `holder`; `source` package names the package in `package`.',
           [P(source=E('root'), package=NULL, version=NULL, holder=NULL), P(source=E('package'), package=STR)])
    relate(defs['findingFlag'], '`headline.source` is null when `headline.value` is.', [P(headline=P(value={'not': NULL})), P(headline=P(source=NULL))])
    relate(defs['withoutRow'], '`at_least` is true only on a row that removes `abandoned` or `pinned` (the rows whose rerun lockrot could not fully read).',
           [P(at_least=E(False)), P(remove=P(kind=E('flag'), id=E('abandoned', 'pinned')))])
    relate(defs['rootSecurity'], '`update_now_command` is null exactly when `update_now` is empty.',
           [P(update_now=EMPTY, update_now_command=NULL), P(update_now=MIN1, update_now_command={'type': 'array'})])
    run = defs['run']
    relate(run, ['`project_php_lowest` is null when `project_php` is (no `require.php`, so no lowest).',
                 '`verdicts` is the seven verdicts in their fixed order.', '`graded_verdicts` is the four grades in their fixed order.',
                 '`flag_ids` is the seven flags in flag order.', '`signal_ids` begins with S1 to S10 in order (a later check is appended).',
                 '`none` stands alone in `fail_on`.'],
           [P(project_php=STR), P(project_php=NULL, project_php_lowest=NULL)],
           [P(verdicts=dict(E(['critical', 'high', 'medium', 'low', 'unknown', 'finished', 'ok']), description='The seven verdicts, in order.'))],
           [P(graded_verdicts=dict(E(['critical', 'high', 'medium', 'low']), description='The four grades, in order.'))],
           [P(flag_ids=dict(E(['abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'vulnerable']), description='The seven flags, in flag order.'))],
           [P(signal_ids={'description': 'S1 to S10 first, in order.', 'items': [E(x) for x in BO.SIGNAL_IDS], 'minItems': len(BO.SIGNAL_IDS)})],
           [P(fail_on=E('none')), P(fail_on={'not': {'pattern': '(^|,)none(,|$)'}})])
    relate(defs['s6'], '`last_stable_version` is null exactly when `last_stable_release` is.',
           [P(last_stable_release=NULL, last_stable_version=NULL), P(last_stable_release=STR, last_stable_version=STR)])
    blocks_live = lambda: P(blocks=CONTAINS(E('S2', 'S4')))
    relate(f, ['A degree\'s `liveness_complete` reads false only beside a `checks_skipped[]` or `checks_missing[]` entry that blocks S2 or S4.',
               'A degree\'s `liveness_complete` reads true only when no `checks_skipped[]` or `checks_missing[]` entry blocks S2 or S4.',
               '`from_composer_repository` is true exactly when `origin.kind` is `packagist` or `composer`; a kind this schema does not list is not constrained.'],
           [P(flags=NONE(P(degree={'allOf': [OBJ, P(liveness_complete=E(False))]}))), P(checks_skipped=CONTAINS(blocks_live())), P(checks_missing=CONTAINS(blocks_live()))],
           [P(flags=NONE(P(degree={'allOf': [OBJ, P(liveness_complete=E(True))]}))), P(checks_skipped=NONE(blocks_live()), checks_missing=NONE(blocks_live()))],
           [P(from_composer_repository=E(True), origin=P(kind=E('packagist', 'composer'))),
            P(from_composer_repository=E(False), origin=P(kind=E('vcs', 'path', 'artifact', 'package', 'unknown'))),
            P(origin=P(kind=NOT('packagist', 'composer', 'vcs', 'path', 'artifact', 'package', 'unknown')))])
    relate(defs['s9fix'], '`php` is null for `unknown` and `none` (no fixing release known).', [P(kind=NOT('unknown', 'none')), P(php=NULL)])
    # P2: a branch row's way out exists only where the branch fixes something, and a blocked row has no score after it
    relate(defs['branchFixes'], 'A branch that fixes nothing has no class, no candidate, no holder and no score after it; a branch that fixes one or more has a class '
                                'and a newest release (its candidate can be null: the whole branch fixes them).',
           [P(fixed=E(0), fix_kind=NULL, lowest=NULL, held_by=EMPTY, if_applied=NULL), P(fixed={'minimum': 1}, fix_kind=STR, newest=STR)])
    relate(defs['branchFixes'], 'A `blocked` branch has no score after it: `if_applied` is null.', [P(fix_kind=NOT('blocked')), P(if_applied=NULL)])
    rec = BO.inner(BO.inner(defs['findingBaseline'])['properties']['recorded'])
    relate(rec, '`lead` is null exactly when `flags` is (an advisory-only entry).', [P(lead=NULL, flags=NULL), P(lead=STR, flags=OBJ)])
    thr = [(g, g) for g in BO.GRADES] + [(m, m) for m in BO.MAINT] + [('vulnerable', 'low')] + [('vulnerable-' + x, x) for x in ('medium', 'high', 'critical')]
    relate(defs['gateValue'], "The threshold follows the value: a grade or a flag is its own threshold, `vulnerable` reads `low`, `vulnerable-<severity>` the severity, "
                              "`unchecked` none; a value this schema does not list is not constrained.",
           [P(value=E(v), threshold=E(t)) for v, t in thr] + [P(value=E('unchecked'), threshold=NULL), P(value=NOT(*([v for v, _ in thr] + ['unchecked'])))])


add_relations_round4(defs)


ROOT_RELATIONS = [
    {'description': "`activity_cache_age_hours` is null exactly when `activity_cache_oldest_at` is.",
     'anyOf': [{'properties': {'activity_cache_oldest_at': {'type': 'null'}, 'activity_cache_age_hours': {'type': 'null'}}},
               {'properties': {'activity_cache_oldest_at': {'type': 'string'}, 'activity_cache_age_hours': {'type': 'number'}}}]},
    {'description': "A finding stands against a baseline only when the run read one: the root `baseline` is null only when every finding's `baseline` is null.",
     'anyOf': [{'properties': {'baseline': {'type': 'object'}}},
               {'properties': {'baseline': {'type': 'null'}, 'findings': {'items': {'properties': {'baseline': {'type': 'null'}}}}}}]},
    {'description': "The root's `include_dev` (report-1) equals `run.include_dev`.",
     'anyOf': [{'properties': {'include_dev': {'enum': [True]}, 'run': {'properties': {'include_dev': {'enum': [True]}}}}},
               {'properties': {'include_dev': {'enum': [False]}, 'run': {'properties': {'include_dev': {'enum': [False]}}}}}]},
]
# the run gate's relations (Gate::decide), stated in rootGate's descriptions and encoded here
_P = lambda **k: {'properties': k}
_E = lambda *v: {'enum': list(v)}
_NOT = lambda *v: {'not': {'enum': list(v)}}
_FAILS = _P(gate=_P(fails=_E(True)))
ROOT_RELATIONS += [
    {'description': "The run fails exactly when something tripped it: `gate.fails` ⇔ `gate.tripped_by` is not empty.",
     'anyOf': [_P(gate=_P(fails=_E(True), tripped_by={'minItems': 1})), _P(gate=_P(fails=_E(False), tripped_by={'maxItems': 0}))]},
    {'description': "A `check` run applies fail-on and a `generate_baseline` run does not (`gate.fail_on_applied`); a mode this schema does not list is not constrained.",
     'anyOf': [_P(run=_P(mode=_E('check')), gate=_P(fail_on_applied=_E(True))), _P(run=_P(mode=_E('generate_baseline')), gate=_P(fail_on_applied=_E(False))),
               _P(run=_P(mode=_NOT('check', 'generate_baseline')))]},
    {'description': "Where fail-on is not applied no finding fails.",
     'anyOf': [_P(gate=_P(fail_on_applied=_E(True))), _P(findings={'items': _P(gate=_P(fails=_E(False)))})]},
    {'description': "Where fail-on is applied a finding that reaches it fails unless something exempts it (Gate::decide).",
     'anyOf': [_P(gate=_P(fail_on_applied=_E(False))), _P(findings={'items': {'not': _P(gate=_P(reaches_fail_on=_E(True), fails=_E(False), exempt_by={'type': 'null'}))}})]},
    {'description': "`fail_on` is among `gate.tripped_by` exactly when some finding fails, and `gate.failing` is 0 exactly when none does.",
     'anyOf': [_P(gate=_P(tripped_by={'not': {'items': {'not': _E('fail_on')}}}, failing={'minimum': 1}), findings={'not': {'items': {'not': _FAILS}}}),
               _P(gate=_P(tripped_by={'items': {'not': _E('fail_on')}}, failing=_E(0)), findings={'items': {'not': _FAILS}})]},
]
schema = {
    '$schema': 'http://json-schema.org/draft-04/schema#',
    'id': 'https://lockrot.dev/schema/report-2.json',
    'title': 'lockrot report',
    'description': ("The document `composer lockrot --format=json` prints, report-2 (lockrot 0.14.0; SPEC-flags r3 §7.6, §8.2): every flag shown, a verdict scored from them, the score "
                    "basis, the move, the gate and the baseline as data. Every key is always written and every null has a stated meaning. Objects are open: a field added later "
                    "validates against this file unchanged; the number in the id changes only when a field is removed or renamed. Closed sets are `enum`s; sets that grow in minor "
                    "releases (including every set a score-model or fix-model change can touch) are strings with a `pattern` and `x-known-values`, which a strict reader takes as "
                    "the enum. Every human string is a rendering of fields in the same object or in `run` (§7.11): such strings carry `x-rendered-from` and are prose, not contract."),
    'type': 'object',
    'required': list(ROOT),
    'properties': ROOT,
    'allOf': ROOT_RELATIONS,
    'definitions': defs,
}


def check_descriptions(node, path, missing):
    """every property schema carries a description (the task's rule); `$ref`-only properties take the definition's."""
    if isinstance(node, dict):
        for k in ('properties',):
            for name, sub in (node.get(k) or {}).items():
                if isinstance(sub, dict) and 'description' not in sub and '$ref' not in sub:
                    missing.append(path + '.' + name)
        for k, v in node.items():
            check_descriptions(v, path + '/' + k, missing)
    elif isinstance(node, list):
        for i, v in enumerate(node):
            check_descriptions(v, path + f'[{i}]', missing)


if __name__ == '__main__':
    miss = []
    check_descriptions(schema, '#', miss)
    # an anyOf branch restates a discriminator that its definition describes, so it needs no description
    miss = [m for m in miss if '/anyOf[' not in m and '/not/' not in m]
    # the shipping pass (ship_text.py): no description cites the spec or its review rounds
    from ship_text import ship_schema, leftovers
    ship_schema(schema)
    lo = leftovers(schema)
    assert not lo, lo[:5]
    out = OUT
    with open(out, 'w') as fh:
        json.dump(schema, fh, indent=2, ensure_ascii=False)
        fh.write('\n')
    print(f'written {out}: {len(json.dumps(schema)):,} B compact, {len(defs)} definitions; properties without a description: {len(miss)}')
    for m in miss[:40]:
        print('  ', m)
