"""The shipping pass over every description string of a schema, applied as the builders write the files.

The builders keep their provenance notes in Python comments. A shipped description says what a field is, never which
design section or review put it there. `SHIP_BANNED` is the pattern that SchemaDescriptionsTest applies to the shipped
files.
"""
import re

# revision-3 with a hyphen, measured counts ("79 of 79"), "generated", lockrot.dev's, and a parenthesis opened by a
# verb whose subject the token pass removed ("(calls them …")
SHIP_BANNED = re.compile(r'§|SPEC|\bround \d|attack round|schema round|critic round|revision[- ]?\d|‡|r3/|\bcorpus\b|\bI\d+[a-c]?\b'
                         r'|\b\d+ of \d+\b|\bgenerated\b|lockrot\.dev\'s|\((?:calls|says)\b'
                         # history an editor shows on hover ("config-1 unchanged."), internal PHP symbols
                         # (`Legacy\Priority013`, `ReleaseBranch::label`) and repository-relative doc paths a consumer cannot open
                         r'|config-1 unchanged|report-1 unchanged|\b[A-Z][A-Za-z]+(?:\\[A-Z][A-Za-z0-9]+)+|::[a-z]+|docs/[a-z-]+\.md'
                         # a backticked PHP class name; the grammar names a renderer implements are public
                         r'|`(?!(?:FlagSentence|GateText|MoveText|ScoreText|SignalSentence|NoteSentence|RunGateText|AgeText|GitHub|GitLab)`)[A-Z][a-z]+[A-Z][A-Za-z]*`'
                         # an item id of the design (C69, W12) is review history, as a spec section is
                         r'|\b[CW]\d{2}\b'
                         # a maintainer decision id (Q1-Q8, O1-O15) is review history too
                         r'|\b[QO]\d{1,2}\b'
                         # the history of drafts that never shipped ("Renamed from `do`.", "`move` are gone", "is dropped:")
                         r'|[Rr]enamed from|\bare gone\b|\bis gone\b|\bis dropped[:.]|\bwas flagged\b')
TOKEN = r'(?:§\s?\d+(?:\.\d+)*(?:\s?"[^"]*")?(?:\'s)?|SPEC(?:-flags r3)?(?:\s?§\s?\d+(?:\.\d+)*)?|(?:attack |schema |critic )?round(?: \d+)?|revision \d+|I\d+[a-c]?|the critic round)'
PURE = re.compile(r'^\s*' + TOKEN + r'(?:\s*(?:,|;|and)\s*' + TOKEN + r')*\s*$')
PREFIX = re.compile(r'^(?:' + TOKEN + r')(?:\s*(?:,|;)\s*' + TOKEN + r')*\s*[:;,]\s*')


def _paren(m):
    inner = m.group(1)
    if PURE.match(inner):
        return ''
    parts = [p for p in re.split(r';\s*', inner) if not PURE.match(p)]
    parts = [PREFIX.sub('', p) for p in parts]
    parts = [re.sub(r',\s*' + TOKEN + r'\s*$', '', p) for p in parts]
    parts = [p for p in parts if p.strip()]
    return (' (' + '; '.join(parts) + ')') if parts else ''


MANUAL = [  # running-text references the token pass cannot rewrite on its own
    (re.compile(r'\s*\((?:C\d\d|W12)\b[^()]*\)'), ''),  # a parenthesis that opens with an item id: "(C77)", "(C77 R5)", "(W12, in C77's frame)"
    (re.compile(r'\s*\((?:C\d\d|W12)\b[^()]*\([^()]*\)[^()]*\)'), ''),
    (re.compile(r"\bSPEC\.md §4\.1's two vocabularies\b"), "Composer's two vocabularies"),
    (re.compile(r'\s*\(lockrot 0\.14\.0; SPEC-flags r3 §7\.6, §8\.2\)'), ' (lockrot 0.14.0)'),
    (re.compile(r'\s*\(all 108 generated documents; attack round 1 corrected this description\)'), ''),
    (re.compile(r'\(report-1; §6\.2 adds six codes\)'), '(report-1, with six more codes)'),
    (re.compile(r' \(§\d+(?:\.\d+)* `[a-z_\[\]]+`\)'), ''),
    (re.compile(r' \(§5\.6 rule \d\)'), ''),
    (re.compile(r' \(§5\.6 `replacement`, split in round 3, `text` → `suggestion` in round 4\)'), ''),
    (re.compile(r'by §5\.6\'s quoting'), 'by the quoting rules of https://lockrot.dev/schema/'),
    (re.compile(r'; corpus-model nulls are ‡ in the fixtures'), ''),
    (re.compile(r'; corpus-model nulls are ‡'), ''),
    (re.compile(r' \(§7\.6 the details block `fixes`, §5\.3 `installed_branch_fixes`; round 4 names\)'), ''),
    (re.compile(r' \((?:§5\.1; )?round 4 moves them here from S9\)'), ', never in S9'),
    (re.compile(r';? ?(?:§5\.3; )?replaces revision 3\'s copy `move`'), ''),
    (re.compile(r' \((?:§2\.3, )?§7\.9 page vocabulary\)'), ''),
    (re.compile(r' \((?:§6\.4, round 3: )?revision 3\'s `unaccepted\[\]`\)'), ''),
    (re.compile(r" The corpus model's `branch_table_missing` \(‡[^)]*\)[^.]*\."), ''),
    (re.compile(r' \(§7\.6 S9 `data`\)'), ''),
    (re.compile(r' \(102 corpus titles hold backticks\)'), ''),
    (re.compile(r' \(§7\.6 field reference\)'), ''),
    (re.compile(r"Position in §3\.2's order"), "Position in the report's order (`run.score_model.sort`)"),
    (re.compile(r' \(§3\.7 `rules\[\]`\)'), ''),
    (re.compile(r"The version of §3\.2's ScoreText grammar only"), 'The version of the ScoreText grammar only'),
    (re.compile(r'\(§3\.7 bump policy: '), '(the bump policy: '),
    (re.compile(r' \(§5\.3 chain\)'), ' (`--target-php`, `LOCKROT_TARGET_PHP`, `extra.lockrot.target-php`, `config.platform.php`, the running PHP, in that order)'),
    (re.compile(r' \(SPEC root block(?:, §7\.6, §8\.2)?\)'), ''),
    (re.compile(r' Open with `x-known-values` \((?:§8\.2, )?revision 2 had it closed\),'), ' Open with `x-known-values`,'),
    (re.compile(r'An open set \(§8\.2 lists the rule ids as open; §3\.7 cal'), 'An open set (cal'),
    # each internal symbol, history note or repository path rewritten as what the field is
    (re.compile(r'by the quoting rules of `docs/schema\.md`'), 'by the quoting rules of https://lockrot.dev/schema/'),
    (re.compile(r'as `docs/verdicts\.md` lists them'), 'as https://lockrot.dev/verdicts/#the-signals lists them'),
    (re.compile(r'\(docs/install-time\.md#install-time-strict\)'), '(https://lockrot.dev/install-time/#install-time-strict)'),
    (re.compile(r'\s*config-1 unchanged\.'), ''),
    (re.compile(r'\s*\(Gate::decide\)'), ''),
    (re.compile(r" on the run clock \(`Clock::yearsSince` against ((?:the document's )?`generated_at`)\)"), r', counted up to \1'),
    (re.compile(r'The branch label as `ReleaseBranch::label(?:\(\))?` writes it'), 'The branch label'),
    (re.compile(r'as `ReleaseBranch::label(?:\(\))?` writes it'), 'as lockrot labels it'),
    (re.compile(r' \((?:§6\.2; )?`Output\\RecordMarks` is the single producer\)'), ''),
    (re.compile(r'`RepoRef::forge(?:\(\))?`; '), ''),
    (re.compile(r'as `PhpReleaseDates::minorOf` writes it'), 'as MAJOR.MINOR'),
    (re.compile(r"0\.13's computation \(`Legacy\\Priority013`\)\."), "0.13's computation, by a frozen copy of 0.13's rules."),
    (re.compile(r'\s*\(`Legacy\\Priority013`\)'), ''),    (re.compile(r"`PhpFloor`'s lower bound"), "its lower bound"),
    (re.compile(r'Null exactly when `PhpFloor` has no lowest'), 'Null exactly when the constraint has no lowest'),
    (re.compile(r', as `RepoRef` words it'), ''),
]


def ship(text):
    t = text
    for rx, rep in MANUAL:
        t = rx.sub(rep, t)
    for _ in range(3):  # nested parentheses: innermost first
        t = re.sub(r'\s*\(((?:[^()]|\([^()]*\))*)\)', lambda m: _paren(m) if SHIP_BANNED.search(m.group(1)) else m.group(0), t)
    for rx, rep in MANUAL:  # again: a reference the token pass left alone once its neighbours went
        t = rx.sub(rep, t)
    t = re.sub(r'\s*\(\s*\)', '', t)
    t = re.sub(r'\s+([.,;:])', r'\1', t)
    t = re.sub(r'\s{2,}', ' ', t).strip()
    return t


CHANGED = []  # descriptions that lost words to the token pass: ship_schema refuses each one whose hash HAND_SHIPPED lacks
# the shipped texts whose stripping removed more than references (a quoted section title) and were read and kept
# a new one fails the build until it is rewritten in its builder or added here after reading it
HAND_SHIPPED = {
    '031139ebd7a0007a',
    '09157cfb44e92283',
    '11afde2df9f549e2',
    '53f5ab37f25c597c',
    '60b56d2e43ade255',
    '6bbd991c076eaf27',
    '73ee9a3dcc29a471',
    '8ef501610ff5d404',
    '8f6b4ae6f1237d5d',
    '9169c58f94f89bc2',
    '94c02c18218a2889',
    'a42692fb0b92cd40',
    'a7ab8f6e7c01709a',
    'b0884e6328aff279',
    'b2abd9a68042c806',
    'e50fd26698b74a5c',
    'ffc7f612bac39af8',
}


def ship_schema(node):
    """every `description` of a schema, shipped; the schema is changed in place and returned."""
    if isinstance(node, dict):
        for k, v in list(node.items()):
            if k == 'description' and isinstance(v, str):
                node[k] = ship(v)
                if node[k] != v and not any(rx.search(v) for rx, _ in MANUAL) and _stripped_words(v, node[k]):
                    CHANGED.append((v, node[k]))
                    import hashlib
                    assert hashlib.sha256(node[k].encode()).hexdigest()[:16] in HAND_SHIPPED, ('a description lost words to the shipping pass: rewrite it', node[k][:120])
            else:
                ship_schema(v)
    elif isinstance(node, list):
        for v in node:
            ship_schema(v)
    return node


def _stripped_words(before, after):
    """the words the token pass removed beyond the references themselves: a removed subject or verb garbles a sentence."""
    rest = re.sub(r'\s*\(\s*\)', '', SHIP_BANNED.sub('', before))
    words = lambda t: set(re.findall(r'[A-Za-z]{4,}', t))
    gone = words(rest) - words(after) - {'round', 'attack', 'schema', 'critic', 'revision', 'corpus', 'SPEC', 'flags'}
    return sorted(gone)


def leftovers(node, path='#', out=None):
    out = [] if out is None else out
    if isinstance(node, dict):
        for k, v in node.items():
            if k == 'description' and isinstance(v, str) and SHIP_BANNED.search(v):
                out.append((path, v))
            else:
                leftovers(v, path + '/' + k, out)
    elif isinstance(node, list):
        for i, v in enumerate(node):
            leftovers(v, path + '/%d' % i, out)
    return out
