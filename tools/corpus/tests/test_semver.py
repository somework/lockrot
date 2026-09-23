"""Composer's version semantics, checked against Composer's own recorded answers.

This is what makes a hand-rolled re-implementation defensible rather than reckless. p2 documents
carry `version_normalized` beside the pretty `version` and list their versions newest first, so
Composer's answer to both questions is available as data, offline, without Composer being anywhere
near the checker.

The oracle rows are recorded by `tools/corpus/record --semver-oracle` and never computed here: a
test that generates its expectations with the function under test is a tautology that reads like
coverage. The hand-written boundary rows below are the other half — the corpus is overwhelmingly
well-formed, so the shapes that break a parser have to be written down on purpose.
"""

import os
import unittest

import support

from lockrot_corpus.jsonio import read_json
from lockrot_corpus.semver import branch_is_above, normalize, order_key, release_branch, stability

ORACLE = read_json(os.path.join(support.FIXTURES, 'semver-oracle.json'))

# Shapes the corpus does not happen to contain, or contains so rarely that a regression would be
# invisible. Each one is a mistake this module has already made or could plausibly make.
BOUNDARIES = [
    ('1.0.0-RC1', '1.0.0.0-RC1'),   # uppercase RC: the trap that manufactured false findings
    ('1.0.0-rc1', '1.0.0.0-RC1'),   # and Composer spells it back in capitals either way
    ('v1.0.0', '1.0.0.0'),
    ('V1.0.0', '1.0.0.0'),          # an upper-case prefix, which 44 recorded tags use
    ('1.0.0+build.5', '1.0.0.0'),   # build metadata does not order and is dropped
    ('1.0.0-beta-7', '1.0.0.0-beta7'),   # a dash before the number
    ('1.0.0a1', '1.0.0.0-alpha1'),  # the short spelling expands
    ('1.0.0-pl2', '1.0.0.0-patch2'),
    ('1.0', '1.0.0.0'),
    ('1', '1.0.0.0'),
    ('1.2.3.4', '1.2.3.4'),
    ('dev-main', None),
    ('1.0.0-nonsense', None),
    ('not-a-version', None),
]


class Normalization(unittest.TestCase):
    def test_every_recorded_pretty_version_normalizes_the_way_composer_normalized_it(self):
        for row in ORACLE['normalization']:
            with self.subTest(pretty=row['pretty']):
                self.assertEqual(row['normalized'], normalize(row['pretty']))

    def test_the_boundary_shapes_the_corpus_does_not_contain(self):
        for pretty, expected in BOUNDARIES:
            with self.subTest(pretty=pretty):
                self.assertEqual(expected, normalize(pretty))

    def test_the_known_divergences_are_still_exactly_the_ones_recorded(self):
        """A divergence that is written down is a decision; one that is not is a bug in waiting.

        There is one, and it is a pre-release nothing in the audit path reads: Composer normalizes
        `v0.14.1-alpha0` to `0.14.1.0-alpha` and drops the zero. If this list grows, something
        changed and somebody has to look.
        """
        for row in ORACLE['known_divergences']:
            with self.subTest(pretty=row['pretty']):
                self.assertEqual(row['this_tool'], normalize(row['pretty']),
                                 'the divergence recorded for %s is no longer the one that happens'
                                 % row['pretty'])
        self.assertEqual(1, len(ORACLE['known_divergences']),
                         'the recorded divergences from Composer changed; read them before widening this')


class Ordering(unittest.TestCase):
    def test_every_recorded_version_list_is_newest_first_under_this_ordering(self):
        """p2 serves versions newest first, so the document order is Composer's ordering, recorded.

        This is the oracle for order_key(), whose silent None on an unknown suffix moves which tag
        is the highest — and the highest tag is what decides whether a package's newest release date
        is trusted at all.
        """
        for row in ORACLE['order']:
            with self.subTest(package=row['package']):
                keys = [order_key(version) for version in row['newest_first']]
                ordered = [key for key in keys if key is not None]
                self.assertEqual(sorted(ordered, reverse=True), ordered)
                self.assertGreater(len(ordered), 1)

    def test_an_unknown_suffix_is_none_rather_than_sorting_lowest(self):
        self.assertIsNone(order_key('1.0.0.0-nonsense1'))
        self.assertIsNone(order_key('dev-main'))

    def test_a_pre_release_sorts_below_the_release_it_precedes(self):
        self.assertLess(order_key('1.0.0.0-RC1'), order_key('1.0.0.0'))
        self.assertLess(order_key('1.0.0.0-alpha1'), order_key('1.0.0.0-beta1'))
        self.assertLess(order_key('1.0.0.0'), order_key('1.0.0.0-patch1'))


class Stability(unittest.TestCase):
    def test_the_recorded_versions_are_classified_as_composer_spells_them(self):
        for row in ORACLE['normalization']:
            with self.subTest(normalized=row['normalized']):
                self.assertNotEqual('dev', stability(row['normalized']))

    def test_case_does_not_decide_stability(self):
        self.assertEqual('RC', stability('1.0.0.0-RC1'))
        self.assertEqual('RC', stability('1.0.0.0-rc1'))
        self.assertEqual('stable', stability('1.0.0.0'))
        self.assertEqual('stable', stability('1.0.0.0-patch1'))
        self.assertEqual('dev', stability('dev-main'))
        self.assertEqual('dev', stability('not a version'))


class Branches(unittest.TestCase):
    def test_a_zero_major_branch_is_narrower_than_the_major(self):
        self.assertEqual('4', release_branch('4.2.1.0'))
        self.assertEqual('0.3', release_branch('0.3.9.0'))
        self.assertEqual('0.0.3', release_branch('0.0.3.0'))
        self.assertIsNone(release_branch('dev-main'))

    def test_branch_keys_compare_as_numbers_per_component(self):
        self.assertTrue(branch_is_above('10', '9'))
        self.assertTrue(branch_is_above('0.3', '0.0.3'))
        self.assertFalse(branch_is_above('1', '1'))


if __name__ == '__main__':
    unittest.main()
