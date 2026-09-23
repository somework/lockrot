"""Composer's version semantics, re-derived here rather than asked of Composer.

This is the part of the tool that looks like duplication and is not. A checker that imports
`composer/semver` — or worse, `Lockrot\\Data\\Repository\\ReleaseBranch` — agrees with lockrot
because it called lockrot, and no test in this repository could tell the difference. Every bug the
corpus checks have caught was a claim about the wrong subject; asking the subject what it is
about closes the only door they come through.

The tax for that is real and has been paid four times already: an uppercase `RC` the suffix table
did not match, a p2 array read from the wrong end, a `version_normalized` field that composer.lock
entries do not carry, and a newest-release date picked by comparing ISO strings. All four were
mistakes in this module's ancestor, not in lockrot, and each cost a release afternoon. Which is why
every function here is checked against a recorded oracle — p2 documents carry Composer's own
`version_normalized` beside the pretty `version`, so Composer's output is available as data without
Composer being on the path. See tests/test_semver.py and fixtures/semver-oracle.json.

None means "this does not parse", everywhere, and no caller may turn it into a default. `normalize()`
answering None coerced to `''` is how a parse regression reads as a corpus of dev versions: every
finding lands in a skip counter and the run prints a clean census over nothing.
"""

import re

# Read from src/Data/Repository/PackageMetadata.php:34 on 2026-09-23. Three or more stable tags on
# one source commit means none of them is dated by a release of its own.
SHARED_COMMIT_TAGS = 3

# Read from src/Clock.php:10 on 2026-09-23: 365.25 days, the year every libyear is counted in.
SECONDS_PER_YEAR = 31557600

# Composer's stability ordering within one version number. The empty suffix is a plain release and
# sorts above every pre-release and below a patch-level suffix.
_SUFFIX_ORDER = {'alpha': 0, 'a': 0, 'beta': 1, 'b': 1, 'rc': 2, '': 3, 'p': 4, 'pl': 4, 'patch': 4}

# The suffixes that make a version a pre-release rather than a stable one. `p`/`pl`/`patch` are
# deliberately absent: Composer reads those as stable.
_PRE_RELEASE = {'alpha': 'alpha', 'a': 'alpha', 'beta': 'beta', 'b': 'beta', 'rc': 'RC'}

# How Composer spells a suffix once normalized: the short forms expand, and `rc` is the one that
# comes back in capitals. Checked against 96,599 recorded (pretty, version_normalized) pairs; before
# this expansion existed, 3,287 of them disagreed, all of them RC. It changes nothing lockrot
# currently reads — the maps a normalized version is looked up in hold stable tags only — which is
# exactly why it would have sat here unnoticed until the day something asked about a pre-release.
_EXPAND = {'a': 'alpha', 'alpha': 'alpha', 'b': 'beta', 'beta': 'beta', 'rc': 'RC',
           'p': 'patch', 'pl': 'patch', 'patch': 'patch'}

# `1.0.0-stable` is a plain release wearing a modifier, and Composer drops the word.
_STABLE_WORD = 'stable'

STABILITIES = ('dev', 'alpha', 'beta', 'RC', 'stable')

_NORMALIZED = re.compile(r'^(\d+)\.(\d+)\.(\d+)\.(\d+)(?:-([A-Za-z]+)\.?(\d*))?$')
# Composer's own shape: an optional `v` in either case, up to four numeric components, then an
# optional stability modifier whose number may be separated by a dot or a dash. Both of those last
# two details were missing when this was first written, and the oracle caught them: 59 recorded
# versions — `V3.5.6` and `v2.15.0-alpha-1` among them — parsed to None, and a version that does not
# parse is a finding quietly dropped from the audit rather than a finding reported.
_PRETTY = re.compile(r'^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.(\d+))?(?:[-._]?([A-Za-z]+)[-._]?(\d*))?$')


def normalize(pretty: object) -> 'str | None':
    """A lock's pretty version as Composer's four-component normalized form, or None.

    composer.lock entries carry no `version_normalized` — only p2 documents do — so the lock's
    `version` has to be parsed by hand. Reading `entry['version_normalized']` instead answers None
    for every entry in every lock, which is the shape of a checker that checks nothing.
    """
    value = str(pretty).strip()
    if value.startswith('dev-') or value.endswith('-dev') or value.startswith('#'):
        return None
    value = re.sub(r'^[vV]', '', value)
    value = re.sub(r'\+.*$', '', value)  # build metadata does not order and Composer drops it
    match = _PRETTY.match(value)
    if match is None:
        return None
    core = '.'.join(match.group(index) or '0' for index in range(1, 5))
    suffix = (match.group(5) or '').lower()
    if suffix == '' or suffix == _STABLE_WORD:
        return core
    if suffix not in _EXPAND:
        return None

    return core + '-' + _EXPAND[suffix] + (match.group(6) or '')


def stability(normalized: object) -> str:
    """One of STABILITIES for a normalized version.

    The `.lower()` is load-bearing and has its own oracle row: Packagist carries tags spelled `RC1`
    as often as `rc1`, and an un-lowercased `RC` falling through to "stable" puts a pre-release into
    the shared-commit count, where it manufactures findings against packages lockrot measured
    correctly.
    """
    value = str(normalized)
    if value.startswith('dev-') or value.endswith('-dev'):
        return 'dev'
    match = _NORMALIZED.match(value)
    if match is None:
        return 'dev'

    return _PRE_RELEASE.get((match.group(5) or '').lower(), 'stable')


def order_key(normalized: object) -> 'tuple[int, ...] | None':
    """Composer's ordering over a normalized version, or None when the suffix is not one it knows.

    None is not "sorts lowest". A caller that drops an unparsed tag silently moves which tag is the
    highest, and the highest tag is what decides whether the newest release date is trusted at all —
    one dropped tag rewrites the answer for the whole package. Callers count what they drop.
    """
    match = _NORMALIZED.match(str(normalized))
    if match is None:
        return None
    suffix = (match.group(5) or '').lower()
    if suffix not in _SUFFIX_ORDER:
        return None

    return (
        int(match.group(1)), int(match.group(2)), int(match.group(3)), int(match.group(4)),
        _SUFFIX_ORDER[suffix], int(match.group(6) or 0),
    )


def release_branch(normalized: object) -> 'str | None':
    """The branch key a version belongs to, or None for a dev or unparsable one.

    Mirrors ReleaseBranch::of() (src/Data/Repository/ReleaseBranch.php:22-41): the key of the caret
    range that version satisfies, which for a 0.x version is narrower than the major.
    """
    if stability(normalized) == 'dev':
        return None
    match = re.match(r'^(\d+)\.(\d+)\.(\d+)\.', str(normalized))
    if match is None:
        return None
    if match.group(1) != '0':
        return match.group(1)

    return '0.0.' + match.group(3) if match.group(2) == '0' else '0.' + match.group(2)


def branch_is_above(key: str, other: str) -> bool:
    """Whether branch `key` is above `other`, over keys compared as strings throughout.

    lockrot compares branch keys with Composer's Comparator here (ReleaseBranch::isAbove) and with
    PHP's version_compare elsewhere (PackageMetadata::highestBranch), and PHP additionally coerces a
    key of `'1'` to the integer 1 when it is used as an array key. None of that reaches the JSON
    this tool reads, but a comparison done on mixed types here would silently order `'0.0.3'` and
    `'0.3'` differently from either. So: strings, split on dots, numeric per component.
    """
    return _branch_tuple(key) > _branch_tuple(other)


def _branch_tuple(key: str) -> 'tuple[int, ...]':
    return tuple(int(part) if part.isdigit() else -1 for part in str(key).split('.'))
