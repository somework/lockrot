"""Composer's version semantics, re-derived here and not asked of Composer.

A checker that imports `composer/semver` or `Lockrot\\Data\\Repository\\ReleaseBranch` agrees with
lockrot because it called lockrot, and no test can tell the difference. Each function here is
checked against the recorded oracle in fixtures/semver-oracle.json, because p2 documents carry
Composer's own `version_normalized`. None means "does not parse", and a caller must not turn it
into a default: a parse regression then reads as a corpus of dev versions and prints a clean census.
"""

import re

# The `SHARED_COMMIT_TAGS` constant of `PackageMetadata`. Three or more stable tags on one source
# commit mean that none of them has a release date of its own.
SHARED_COMMIT_TAGS = 3

# 365.25 days, the year that lockrot counts libyears in (`Clock::SECONDS_PER_YEAR`).
SECONDS_PER_YEAR = 31557600

# Composer's stability ordering within one version number. The empty suffix is a plain release and
# sorts above every pre-release and below a patch-level suffix.
_SUFFIX_ORDER = {'alpha': 0, 'a': 0, 'beta': 1, 'b': 1, 'rc': 2, '': 3, 'p': 4, 'pl': 4, 'patch': 4}

# The suffixes that make a version a pre-release and not a stable one. `p`, `pl` and `patch` are
# absent because Composer reads them as stable.
_PRE_RELEASE = {'alpha': 'alpha', 'a': 'alpha', 'beta': 'beta', 'b': 'beta', 'rc': 'RC'}

# How Composer spells a suffix once normalized: short forms expand, and `rc` becomes `RC`.
_EXPAND = {'a': 'alpha', 'alpha': 'alpha', 'b': 'beta', 'beta': 'beta', 'rc': 'RC',
           'p': 'patch', 'pl': 'patch', 'patch': 'patch'}

# `1.0.0-stable` is a plain release with a modifier, and Composer drops the word.
_STABLE_WORD = 'stable'

STABILITIES = ('dev', 'alpha', 'beta', 'RC', 'stable')

_NORMALIZED = re.compile(r'^(\d+)\.(\d+)\.(\d+)\.(\d+)(?:-([A-Za-z]+)\.?(\d*))?$')
# Composer's own shape: an optional `v` in either case, up to four numeric components, then an
# optional stability modifier whose number a dot or a dash can separate.
_PRETTY = re.compile(r'^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.(\d+))?(?:[-._]?([A-Za-z]+)[-._]?(\d*))?$')


def normalize(pretty: object) -> 'str | None':
    """A lock's pretty version as Composer's four-component normalized form, or None.

    composer.lock entries carry no `version_normalized`, only p2 documents do, so this function
    parses the lock's `version` by hand.
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

    Keep the `.lower()`: Packagist carries tags spelled `RC1` as often as `rc1`, and an uppercase
    `RC` that falls through to "stable" puts a pre-release into the shared-commit count.
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

    None does not mean "sorts lowest". A caller that drops an unparsed tag can change which tag is
    the highest, and that tag decides whether the newest release date is trusted. Callers count what
    they drop.
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

    Mirrors `ReleaseBranch::of()`: the key of the caret range that the version satisfies, which
    for a 0.x version is narrower than the major.
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
    """Whether branch `key` is above `other`.

    Keys compare as strings split on dots, numeric per component. A comparison on mixed types can
    order `'0.0.3'` and `'0.3'` differently from lockrot's own comparisons.
    """
    return _branch_tuple(key) > _branch_tuple(other)


def _branch_tuple(key: str) -> 'tuple[int, ...]':
    return tuple(int(part) if part.isdigit() else -1 for part in str(key).split('.'))
