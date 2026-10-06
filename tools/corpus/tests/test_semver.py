"""Composer's version semantics, checked against Composer's own recorded answers.

The oracle rows come from `tools/corpus/record --semver-oracle` and are never computed here: a test
that generates its expectations with the function under test is a tautology. The boundary rows
cover the shapes that break a parser, because the corpus is almost entirely well-formed.
"""

import os
import unittest

import support

from lockrot_corpus.jsonio import read_json
from lockrot_corpus.semver import branch_is_above, normalize, order_key, release_branch, stability

ORACLE = read_json(os.path.join(support.FIXTURES, 'semver-oracle.json'))

# Shapes that the corpus lacks, or contains so rarely that a regression is invisible.
BOUNDARIES = [
    ('1.0.0-RC1', '1.0.0.0-RC1'),
    ('1.0.0-rc1', '1.0.0.0-RC1'),   # Composer spells it in capitals either way
    ('v1.0.0', '1.0.0.0'),
    ('V1.0.0', '1.0.0.0'),
    ('1.0.0+build.5', '1.0.0.0'),   # build metadata does not order and is dropped
    ('1.0.0-beta-7', '1.0.0.0-beta7'),
    ('1.0.0a1', '1.0.0.0-alpha1'),
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
        """A divergence that is written down is a decision. One that is not is a bug in waiting.

        The recorded divergence is a pre-release that nothing in the audit path reads: Composer
        normalizes `v0.14.1-alpha0` to `0.14.1.0-alpha` and drops the zero. If this list grows,
        someone must look.
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

        This is the oracle for order_key(). Its silent None on an unknown suffix changes which tag is
        the highest, and that tag decides whether the newest release date of a package is trusted.
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
