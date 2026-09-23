"""What lockrot claimed about each package, audited against the repository data the same run read.

The p2 documents come out of the very cache the run consumed, so a disagreement is an
interpretation and never Packagist moving underneath. That only holds while the cache has not been
refreshed since; `corpus check` records the cache root and the run's day for exactly that reason.

The five assertions, each stated as a precondition and a consequence:

  A1  an installed tag on a commit its siblings share, with no parent to date it, has no end to
      measure from — libyears must be null.
  A2  both ends dated and trusted — a number must come out.
  A3  and the number must be the difference between them.
  A4  an S2 signal that credits no parent must name the newest release this tool also finds.
  A5  an S10 `undated_releases` entry must not sit on a package whose newest release is datable.

A3 additionally keeps a signed error per finding, because a per-item tolerance hides a systematic
bias smaller than itself: fifty findings each 0.004 years early is a real drift and no single row
reports it. The mean is asserted separately, in aggregate_problems().

Selection for A4 and A5 deliberately requires neither the lock entry nor the installed version's
stability. In the scratchpad ancestor those two `continue`s sat above the signal loop and suppressed
A4 and A5 as well, although neither depends on them — the largest silent-coverage hole in the
original, inherited by accident rather than decided.
"""

import datetime
import math

from .checks import Check, Decline, Selection, Verdict, decline, ok, problem, select
from .metadata import Metadata
from .metadata import parse_time  # noqa: F401 - re-exported shape kept beside Metadata
from .semver import SECONDS_PER_YEAR, normalize, release_branch, stability

# Half of the last digit lockrot prints. The report rounds once, at output, to two decimals and sums
# the unrounded values; PHP rounds half away from zero where Python rounds half to even, so a value
# landing exactly on the boundary may differ in the last digit and only there.
LIBYEARS_TOLERANCE = 0.005

# lockrot rounds libyears once, at output, to two decimals. So every recomputation here differs
# from the reported number by a rounding error uniform in +/- half of that last digit, which over a
# population has a standard deviation of ULP/sqrt(12) and a standard error of that over sqrt(n).
# A fixed threshold is wrong in both directions: on 4,000 findings it is looser than the noise it
# was meant to catch, and on 40 it fires on runs that are correct.
LIBYEARS_ULP = 0.01
MEAN_SIGMAS = 3

# The `reason` lockrot writes into an S10 entry when the releases carry no date. A rename in lockrot
# does not make this check lie — it makes A5 select nothing, which trips its floor and fails the run.
UNDATED_RELEASES = 'undated_releases'


class Claim:
    """One finding, with the lock entry and the independently derived metadata beside it."""

    kind = 'claim'

    def __init__(self, project: str, finding: dict, entry: 'dict | None',
                 metadata: 'Metadata | None', parent_date: 'datetime.datetime | None',
                 parent: 'str | None' = None, ambiguous: bool = False) -> None:
        self.project = project
        self.finding = finding
        self.entry = entry
        self.metadata = metadata
        self.parent_date = parent_date
        self.parent = parent
        self.ambiguous = ambiguous
        self.name = '%s %s %s' % (project, finding.get('package'), finding.get('version'))

    @property
    def libyears(self) -> 'float | None':
        return self.finding.get('libyears')

    @property
    def noted(self) -> bool:
        """lockrot declaring a fallback of its own. Auditing those as clean cases invents findings."""
        return self.finding.get('note') is not None

    @property
    def installed_normalized(self) -> 'str | None':
        pretty = (self.entry or {}).get('version') or ''
        normalized = normalize(pretty)

        return normalized

    @property
    def version_mismatch(self) -> bool:
        """The report and the lock disagree about which version is installed.

        Which means they came from different trees: a report kept from an older fetch, or a corpus
        refreshed under a finished run. Every claim below is about a version, so auditing the two
        against each other produces findings that belong to neither.
        """
        return self.entry is not None and self.entry.get('version') != self.finding.get('version')

    @property
    def shares_commit(self) -> bool:
        normalized = self.installed_normalized

        return normalized is not None and normalized in self.metadata.shared_commit_versions

    def signals(self, ident: str) -> 'list[dict]':
        return [signal for signal in self.finding.get('signals', [])
                if isinstance(signal, dict) and signal.get('id') == ident]


NO_PROVIDER = Decline(
    'no cached p2 document',
    'the package was served by a repository this cache does not hold, so there is nothing to audit '
    'the claim against',
    max_share=0.05,
    measured_on='2026-09-23, 17 of 4124 findings',
)

AMBIGUOUS = Decline(
    'cached under several repositories',
    'more than one repository cached this package and the lock does not say which served it; '
    'auditing against the wrong one invents findings',
    max_share=0.02,
    measured_on='2026-09-23, none of 4124 findings',
)

NO_ENTRY = Decline(
    'no lock entry',
    'the report names a package the lock this tool read does not carry, which means the two came '
    'from different trees',
    max_share=0.02,
    measured_on='2026-09-23, none of 4124 findings',
)

PARENTED = Decline(
    'a monorepo parent stands behind this package',
    'PackageMetadata::datedBy() rewrites a child wholesale — its per-branch dates, its newest '
    'release and the map its own releases are read from — and a check that modelled only the '
    'version-for-version handover would accuse lockrot of leaving measurable packages unmeasured. '
    'The parent path is deliberately out of scope here rather than half-modelled',
    max_share=0.15,
    measured_on='2026-09-23',
)

VERSION_MISMATCH = Decline(
    'the report and the lock name different versions',
    'the two came from different trees, so every claim about this version is about neither of them',
    max_share=0.01,
    measured_on='2026-09-23, none of 4124 findings',
)

OFF_BRANCH = Decline(
    'the installed version is a snapshot or off a release branch',
    'a dev version has no release date of its own and the shared-commit rule does not reach it',
    max_share=0.10,
    measured_on='2026-09-23, 20 of 4124 findings',
)

NOTED = Decline(
    'the finding carries a note',
    'lockrot said in the finding that it fell back to something; auditing that as a clean case '
    'produces findings lockrot does not have',
    max_share=0.05,
    measured_on='2026-09-23, 1 of 4124 findings',
)


def _needs_metadata(claim: Claim) -> 'Selection | None':
    if claim.ambiguous:
        return decline(AMBIGUOUS.reason)
    if claim.metadata is None:
        return decline(NO_PROVIDER.reason)

    return None


def _needs_stable_entry(claim: Claim) -> 'Selection | None':
    declined = _needs_metadata(claim)
    if declined is not None:
        return declined
    if claim.entry is None:
        return decline(NO_ENTRY.reason)
    if claim.version_mismatch:
        return decline(VERSION_MISMATCH.reason)
    normalized = claim.installed_normalized
    if normalized is None or stability(normalized) != 'stable' or release_branch(normalized) is None:
        return decline(OFF_BRANCH.reason)

    return None


# --- A1: a shared commit is not a date ------------------------------------------------------------

def _a1_selects(claim: Claim) -> Selection:
    declined = _needs_stable_entry(claim)
    if declined is not None:
        return declined
    if claim.noted:
        return decline(NOTED.reason)
    if not claim.shares_commit:
        return decline('the installed tag does not share its commit')
    if claim.parent is not None:
        return decline(PARENTED.reason)

    return select(claim)


def _a1_assert(claim: Claim) -> Verdict:
    if claim.libyears is not None:
        return problem('libyears measured from a commit three tags share',
                       '%s libyears=%s' % (claim.name, claim.libyears))

    return ok()


# --- A2 and A3: both ends dated, so a number must come out and must be the right one ----------------

def _clean_pair(claim: Claim) -> Selection:
    """The case where neither end needs a fallback: the lock dates the install, the repository dates
    the newest release, no parent is involved and the tag is not on a shared commit."""
    declined = _needs_stable_entry(claim)
    if declined is not None:
        return declined
    if claim.noted:
        return decline(NOTED.reason)
    if claim.shares_commit:
        return decline('the installed tag shares its commit')
    if claim.parent is not None:
        return decline(PARENTED.reason)
    if not claim.entry.get('time'):
        return decline('the lock carries no date for the installed version')
    if claim.metadata.last_stable_release_at is None:
        return decline('the repository has no newest release this tool trusts')

    return select(claim)


def _a2_assert(claim: Claim) -> Verdict:
    if claim.libyears is None:
        return problem('libyears left unmeasured with both ends dated', claim.name)

    return ok()


class _A3:
    """A3 keeps its errors, so the population can be asked a question no single row answers."""

    def __init__(self) -> None:
        # (error, the exact value it is an error in). The second is needed because the sign of a
        # rounding error is only a coin toss away from zero: libyears cannot be negative, so every
        # value under half a printed digit rounds down to 0.00 and its error is negative by
        # construction. On the 2026-09-23 corpus that is 2,156 of 3,958 findings, and counting them
        # turns a clean run into a 5-sigma accusation.
        self.errors: 'list[tuple[float, float]]' = []

    def selects(self, claim: Claim) -> Selection:
        selection = _clean_pair(claim)
        if not selection.selected:
            return selection
        if claim.libyears is None:
            # A2 is the check that reports this; A3 has no number to compare.
            return decline('no number was measured')

        return selection

    def assert_(self, claim: Claim) -> Verdict:
        installed = parse_time(claim.entry.get('time'))
        expected = max(0.0, (claim.metadata.last_stable_release_at - installed).total_seconds()
                       / SECONDS_PER_YEAR)
        error = float(claim.libyears) - expected
        self.errors.append((error, expected))
        if abs(error) > LIBYEARS_TOLERANCE:
            return problem('the libyears reported is not the difference between the two dates',
                           '%s reported=%s recomputed=%.4f' % (claim.name, claim.libyears, expected))

        return ok()

    def aggregate_problems(self) -> 'list[tuple[str, str]]':
        """What the population says that no single row does.

        Two questions, because a drift can hide from either one alone. The mean catches a bias in
        the same direction everywhere, measured against the noise floor rounding alone would
        produce. The sign balance catches a bias that is large in a subset and absent elsewhere,
        which a mean dilutes towards zero: rounding is a coin toss, so a population where four in
        five errors share a sign is not rounding.
        """
        count = len(self.errors)
        if count < 30:
            # Below that neither statistic says anything: the mean's own noise floor is wider than
            # the per-finding tolerance, and the sign test has no power.
            return []
        problems = []
        mean = sum(error for error, _ in self.errors) / count
        limit = MEAN_SIGMAS * LIBYEARS_ULP / math.sqrt(12 * count)
        if abs(mean) > limit:
            problems.append((
                'libyears drifts systematically across the whole population',
                'mean signed error %.6g years over %d findings, against a rounding noise floor of '
                '%.6g at %d sigma — every row was inside the per-finding tolerance of %s'
                % (mean, count, limit, MEAN_SIGMAS, LIBYEARS_TOLERANCE)))
        # Only where rounding could have gone either way. A value further than half a printed digit
        # from zero has nothing pushing its error one way; below that the floor does, and including
        # those findings measures the floor rather than lockrot.
        signed = [error for error, exact in self.errors
                  if error and exact >= LIBYEARS_ULP / 2]
        positive = sum(1 for error in signed if error > 0)
        span = MEAN_SIGMAS * math.sqrt(len(signed)) / 2
        if len(signed) >= 30 and abs(positive - len(signed) / 2) > span:
            problems.append((
                'libyears errs in one direction far more often than rounding would',
                '%d of %d errors on values rounding could have taken either way are positive; '
                'rounding alone would put that within %.0f of half'
                % (positive, len(signed), span)))

        return problems


# --- A4: what S2 calls the newest release -----------------------------------------------------------

def _s2_unparented(claim: Claim) -> 'list[dict]':
    return [signal for signal in claim.signals('S2')
            if (signal.get('data') or {}).get('dated_by') is None]


def _a4_selects(claim: Claim) -> Selection:
    declined = _needs_metadata(claim)
    if declined is not None:
        return declined
    if not _s2_unparented(claim):
        return decline('no S2 signal that credits no parent')

    return select(claim)


def _a4_assert(claim: Claim) -> Verdict:
    for signal in _s2_unparented(claim):
        claimed = (signal.get('data') or {}).get('last_release')
        if claim.metadata.last_stable_release_at is None:
            return problem('S2 on a package whose newest release this tool does not trust',
                           '%s claimed=%s' % (claim.name, claimed))
        recomputed = claim.metadata.last_stable_release_at
        parsed = parse_time(claimed)
        if parsed is None:
            return problem('S2 names a last_release that is not a timestamp',
                           '%s claimed=%r' % (claim.name, claimed))
        if abs((parsed - recomputed).total_seconds()) > 60:
            return problem('the last_release S2 names is not the newest release this tool finds',
                           '%s claimed=%s recomputed=%s'
                           % (claim.name, claimed, recomputed.isoformat()))

    return ok()


# --- A5: what S10 calls an undated release ------------------------------------------------------------

def _s10_undated(claim: Claim) -> 'list[dict]':
    entries = []
    for signal in claim.signals('S10'):
        for entry in (signal.get('data') or {}).get('unchecked', []):
            if isinstance(entry, dict) and entry.get('reason') == UNDATED_RELEASES:
                entries.append(entry)

    return entries


def _a5_selects(claim: Claim) -> Selection:
    declined = _needs_metadata(claim)
    if declined is not None:
        return declined
    if not _s10_undated(claim):
        return decline('no S10 entry about undated releases')

    return select(claim)


def _a5_assert(claim: Claim) -> Verdict:
    if claim.metadata.last_stable_release_at is not None:
        return problem('S10 says the releases carry no date, and this tool dates the newest one',
                       '%s newest=%s' % (claim.name, claim.metadata.last_stable_release_at.isoformat()))

    return ok()


class ClaimChecks:
    """The five checks plus the aggregate, held together so A3's population can be asked about bias."""

    def __init__(self) -> None:
        self.a3 = _A3()

    def checks(self) -> 'list[Check]':
        ordinary = [NO_PROVIDER, AMBIGUOUS, NO_ENTRY, VERSION_MISMATCH, OFF_BRANCH, NOTED]
        parent = PARENTED
        no_time = Decline('the lock carries no date for the installed version',
                          'nothing to measure from', max_share=0.05,
                          measured_on='2026-09-23, none of 4124 findings')
        no_newest = Decline('the repository has no newest release this tool trusts',
                            'nothing to measure to', max_share=0.10,
                            measured_on='2026-09-23, 16 of 4124 findings')
        shares = Decline('the installed tag shares its commit',
                         'A1 is the check for that case', max_share=0.10,
                         measured_on='2026-09-23, 103 of 4124 findings')

        return [
            Check('A1', 'a tag on a shared commit is not measured',
                  _a1_selects, _a1_assert,
                  declines=ordinary + [
                      parent,
                      Decline('the installed tag does not share its commit',
                              'the ordinary case', max_share=1.0,
                              measured_on='2026-09-23, 3983 of 4124 findings'),
                  ],
                  min_corpus=50, min_fixtures=1, measured_on='2026-09-23, 97 of 4124 selected'),
            Check('A2', 'both ends dated means a number',
                  _clean_pair, _a2_assert,
                  declines=ordinary + [
                      parent, no_time, no_newest, shares,
                  ],
                  min_corpus=3000, min_fixtures=1,
                  measured_on='2026-09-23, 3965 of 4124 selected'),
            Check('A3', 'the number is the difference between the two dates',
                  self.a3.selects, self.a3.assert_,
                  declines=ordinary + [
                      parent, no_time, no_newest, shares,
                      Decline('no number was measured',
                              'A2 is the check that reports a missing number',
                              max_share=0.05,
                              measured_on='2026-09-23, none of 4124 findings'),
                  ],
                  min_corpus=3000, min_fixtures=1,
                  measured_on='2026-09-23, 3965 of 4124 selected'),
            Check('A4', 'S2 names the newest release this tool also finds',
                  _a4_selects, _a4_assert,
                  declines=[NO_PROVIDER, AMBIGUOUS,
                            Decline('no S2 signal that credits no parent',
                                    'most packages have no S2 at all', max_share=1.0,
                                    measured_on='2026-09-23, 3617 of 4124 findings')],
                  min_corpus=300, min_fixtures=1, measured_on='2026-09-23, 490 of 4124 selected'),
            Check('A5', 'S10 does not call a datable release undated',
                  _a5_selects, _a5_assert,
                  declines=[NO_PROVIDER, AMBIGUOUS,
                            Decline('no S10 entry about undated releases',
                                    'most packages have no S10 at all', max_share=1.0,
                                    measured_on='2026-09-23, 4099 of 4124 findings')],
                  min_corpus=4, min_fixtures=1, measured_on='2026-09-23, 8 of 4124 selected'),
        ]

    def aggregate_problems(self) -> 'list[tuple[str, str]]':
        return self.a3.aggregate_problems()
