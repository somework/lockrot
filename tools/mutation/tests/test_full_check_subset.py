import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))

import full_check_subset  # noqa: E402

WORKFLOW = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..', '.github', 'workflows', 'full-check.yml')
SHARDS = ['verdicts', 'signals and analyzer', 'data and the rest']
MATRIX = """\
        shard:
          - {name: verdicts, filter: 'src/Verdict', min_msi: 98}
          - {name: signals and analyzer, filter: 'src/Signal src/Analyzer', min_msi: 98}
          - {name: data and the rest, filter: 'src/Data/Forge src/Version.php', min_msi: 96}
"""


class TheFullCheckSubset(unittest.TestCase):
    def test_a_branch_without_a_subset_runs_every_shard(self):
        self.assertEqual(SHARDS, full_check_subset.selected('release/full-check-1ca57573', SHARDS))

    def test_an_rc_tag_runs_every_shard(self):
        self.assertEqual(SHARDS, full_check_subset.selected('v0.14.0-rc.1', SHARDS))

    def test_the_names_after_the_double_hyphen_choose_the_shards_in_matrix_order(self):
        self.assertEqual(
            ['verdicts', 'data and the rest'],
            full_check_subset.selected('release/full-check-1ca57573--data-and-the-rest,verdicts', SHARDS),
        )

    def test_a_name_given_twice_runs_its_shard_once(self):
        self.assertEqual(['verdicts'], full_check_subset.selected('release/full-check-1ca57573--verdicts,verdicts', SHARDS))

    def test_a_name_that_is_no_shard_fails_with_the_known_names(self):
        with self.assertRaises(ValueError) as raised:
            full_check_subset.selected('release/full-check-1ca57573--verdict', SHARDS)
        self.assertIn('verdict', str(raised.exception))
        self.assertIn('signals-and-analyzer', str(raised.exception))

    def test_an_empty_name_is_refused(self):
        for ref in ('release/full-check-1ca57573--', 'release/full-check-1ca57573--,', 'release/full-check-1ca57573--verdicts,', 'release/full-check-1ca57573--verdicts,,score'):
            with self.subTest(ref=ref), self.assertRaises(ValueError):
                full_check_subset.selected(ref, SHARDS)

    def test_the_shard_names_are_read_from_the_matrix(self):
        self.assertEqual(SHARDS, full_check_subset.shard_names(MATRIX))

    def test_the_workflow_names_a_shard_for_each_token_it_accepts(self):
        with open(WORKFLOW, encoding='utf-8') as handle:
            names = full_check_subset.shard_names(handle.read())

        self.assertNotEqual([], names)
        for name in names:
            with self.subTest(name=name):
                self.assertEqual([name], full_check_subset.selected('release/full-check-0--' + name.replace(' ', '-'), names))


if __name__ == '__main__':
    unittest.main()
