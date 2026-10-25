"""The projection of the model's cases onto the hydrator's inputs (tools/schema/project_fixtures.py)."""

import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))

import project_fixtures  # noqa: E402

REFUSED = {'result': 'refused', 'form': None, 'reason': None, 'holds': [], 'problem': 'Problem 1'}
MOVE = {'result': 'not_checked', 'form': None, 'reason': 'baseline_refused', 'holds': []}


class TheRunInputsAddTheirChecks(unittest.TestCase):
    def test_the_answers_of_the_run_inputs_join_the_recorded_checks(self):
        checks = project_fixtures.merged_checks({'require a/b ^2.0 ': MOVE}, {'_checks': {'baseline': REFUSED}})

        self.assertEqual({'require a/b ^2.0 ': MOVE, 'baseline': REFUSED}, checks)

    def test_a_case_without_run_inputs_keeps_its_recorded_checks(self):
        self.assertEqual({'require a/b ^2.0 ': MOVE}, project_fixtures.merged_checks({'require a/b ^2.0 ': MOVE}, {}))

    def test_the_answer_of_the_run_inputs_wins_over_a_recorded_one_of_the_same_request(self):
        checks = project_fixtures.merged_checks({'baseline': MOVE}, {'_checks': {'baseline': REFUSED}})

        self.assertEqual({'baseline': REFUSED}, checks)


if __name__ == '__main__':
    unittest.main()
