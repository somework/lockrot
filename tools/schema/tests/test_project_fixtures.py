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



class AWorldGivesThePackagesReleases(unittest.TestCase):
    PROJECTED = [{'version': 'v6.4.40', 'time': None, 'php': '>=8.1'}, {'version': 'v6.4.41', 'time': '2026-07-04T10:00:00+00:00', 'php': '>=8.1', 'shared_commit': True}]

    def test_a_release_takes_the_php_and_the_time_that_the_world_gives_it(self):
        world = [{'version': 'v6.4.40', 'require': {'php': '>=8.1 <8.5'}, 'time': '2026-07-03T10:00:00+00:00'}]

        self.assertEqual({'version': 'v6.4.40', 'time': '2026-07-03T10:00:00+00:00', 'php': '>=8.1 <8.5'}, project_fixtures.world_releases(world, self.PROJECTED)[0])

    def test_a_release_that_only_the_world_holds_joins_and_one_that_it_lacks_stays(self):
        world = [{'version': 'v6.4.39', 'require': {}, 'time': None}]

        releases = project_fixtures.world_releases(world, self.PROJECTED)

        self.assertEqual([{'version': 'v6.4.39', 'time': None, 'php': None}] + self.PROJECTED, releases)


class TheMoveCheckNamesThePhpOfTheSolve(unittest.TestCase):
    RUN = {'composer': '2.10.3', 'baseline': 'refused', 'reason': None}

    def test_a_world_gives_its_platform_php(self):
        world = {'world': {'platform_php': '8.4.3'}}

        self.assertEqual('8.4.3', project_fixtures.move_check_of(self.RUN, world, {'target': {'php': '8.4.99'}})['php'])

    def test_a_run_without_a_world_takes_the_php_of_the_window_target(self):
        self.assertEqual(dict(self.RUN, php='8.4.99'), project_fixtures.move_check_of(self.RUN, None, {'target': {'php': '8.4.99', 'inside': False}}))

    def test_a_run_without_a_world_or_a_window_names_no_php(self):
        self.assertEqual(self.RUN, project_fixtures.move_check_of(self.RUN, None, None))


if __name__ == '__main__':
    unittest.main()
