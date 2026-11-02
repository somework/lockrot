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


class TheRunInputsGiveThePackagesReleases(unittest.TestCase):
    PROJECTED = [{'version': 'v3.11.2', 'time': None, 'php': '>=8.1'}, {'version': 'v3.6.0', 'time': None, 'php': '>=8.1'}]

    def test_a_release_takes_the_php_and_the_time_that_the_run_inputs_give_it(self):
        given = [{'version': 'v3.6.0', 'time': '2020-10-06T15:12:11+00:00', 'php': '>=7.1.3'}]

        releases = project_fixtures.run_releases(given, self.PROJECTED)

        self.assertEqual({'version': 'v3.6.0', 'time': '2020-10-06T15:12:11+00:00', 'php': '>=7.1.3'}, releases[1])
        self.assertEqual(self.PROJECTED[0], releases[0])

    def test_a_release_that_only_the_run_inputs_hold_joins(self):
        given = [{'version': 'v3.5.1', 'time': '2020-01-01T00:00:00+00:00', 'php': '>=7.1.3'}]

        self.assertIn({'version': 'v3.5.1', 'time': '2020-01-01T00:00:00+00:00', 'php': '>=7.1.3'}, project_fixtures.run_releases(given, self.PROJECTED))


class TheMoveCheckNamesThePhpOfTheSolve(unittest.TestCase):
    RUN = {'composer': '2.10.3', 'baseline': 'refused', 'reason': None}

    def test_a_world_gives_its_platform_php(self):
        world = {'world': {'platform_php': '8.4.3'}}

        self.assertEqual('8.4.3', project_fixtures.move_check_of(self.RUN, world, {'target': {'php': '8.4.99'}})['php'])

    def test_a_run_without_a_world_takes_the_php_of_the_window_target(self):
        self.assertEqual(dict(self.RUN, php='8.4.99'), project_fixtures.move_check_of(self.RUN, None, {'target': {'php': '8.4.99', 'inside': False}}))

    def test_a_run_without_a_world_or_a_window_names_no_php(self):
        self.assertEqual(self.RUN, project_fixtures.move_check_of(self.RUN, None, None))


class TheRunInputsGiveTheLockItsLinks(unittest.TestCase):
    def test_a_link_of_the_run_inputs_replaces_the_projected_link_and_sets_the_version(self):
        lock = {'acme/h': {'name': 'acme/h', 'version': '1.0.0', 'dev': False, 'origin': 'packagist', 'require': {'guzzlehttp/guzzle': '*', 'psr/log': '*'}}}

        project_fixtures.lock_links(lock, {'acme/h': {'version': '1.2.0', 'require': {'guzzlehttp/guzzle': '^7.10'}}})

        self.assertEqual({'name': 'acme/h', 'version': '1.2.0', 'dev': False, 'origin': 'packagist',
                          'require': {'guzzlehttp/guzzle': '^7.10', 'psr/log': '*'}}, lock['acme/h'])

    def test_a_name_that_the_lock_lacks_joins_it_from_packagist(self):
        lock = {}

        project_fixtures.lock_links(lock, {'acme/san-cap': {'version': '1.0.0', 'conflict': {'typo3/html-sanitizer': '~2.3.5'}}})

        self.assertEqual({'acme/san-cap': {'name': 'acme/san-cap', 'version': '1.0.0', 'dev': False, 'origin': 'packagist',
                                           'conflict': {'typo3/html-sanitizer': '~2.3.5'}}}, lock)


class ACaseWithoutBranchRowsHydratesFromItsRunReleases(unittest.TestCase):
    """R4-17: a capped-* case carries no branch rows, and the model's `run_inputs._releases` give its releases."""
    FINDING = {'package': 'laminas/laminas-code', 'version': '4.7.1', 'dev': False, 'direct': False, 'named_in': [], 'chain': ['laminas/laminas-code'],
               'direct_dependents': [], 'origin': {'kind': 'packagist'}, 'installed_php': {'requires': '^7.4 || ~8.0.0'}, 'signals': [],
               'security': {'records': 0, 'ignored': [], 'unchecked_reason': None}, 'activity': {}, 'metadata': {'status': 'read'},
               'depth_unmeasured': None, 'next_step': None}
    RUN = {'root_package': 'acme/app', 'project_php': None, 'target_php': '7.4', 'fail_on': 'high', 'include_dev': False, 'move_check': None}
    RELEASES = [{'version': '4.17.0', 'time': '2025-01-01T00:00:00+00:00', 'php': '~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0'}]

    def test_a_case_without_branch_rows_and_with_run_releases_gets_inputs(self):
        inputs = project_fixtures.inputs_of({'finding': self.FINDING, 'run': self.RUN, 'run_inputs': {'_releases': self.RELEASES}})

        self.assertIsNotNone(inputs)
        self.assertIn({'version': '4.17.0', 'time': '2025-01-01T00:00:00+00:00', 'php': '~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0'}, inputs['releases'])

    def test_a_release_that_only_the_finding_names_stays_out_of_the_run_releases(self):
        finding = dict(self.FINDING, age={'release': {'version': '4.18.0', 'at': '2025-06-01T00:00:00+00:00'}})

        inputs = project_fixtures.inputs_of({'finding': finding, 'run': self.RUN, 'run_inputs': {'_releases': self.RELEASES}})

        self.assertEqual(['4.17.0', '4.7.1'], [r['version'] for r in inputs['releases']])

    def test_the_run_root_require_replaces_the_projected_constraint(self):
        finding = dict(self.FINDING, direct=True, named_in=['require'])

        inputs = project_fixtures.inputs_of({'finding': finding, 'run': self.RUN,
                                             'run_inputs': {'_releases': self.RELEASES, '_root_require': {'laminas/laminas-code': '~4.7.0'}}})

        self.assertEqual({'laminas/laminas-code': '~4.7.0'}, inputs['root']['require'])

    def test_a_lock_entry_without_a_time_takes_the_time_of_its_merged_release(self):
        given = self.RELEASES + [{'version': '4.7.1', 'time': '2015-06-25T11:21:15+00:00', 'php': '^7.4 || ~8.0.0', 'shared_commit': True}]

        inputs = project_fixtures.inputs_of({'finding': self.FINDING, 'run': self.RUN, 'run_inputs': {'_releases': given}})

        self.assertEqual('2015-06-25T11:21:15+00:00', inputs['lock'][0]['time'])

    def test_a_lock_entry_that_the_model_reads_as_undated_stays_without_a_time(self):
        finding = dict(self.FINDING, age={'installed_unmeasured': 'undated'})
        given = [{'version': '4.7.1', 'time': '2015-06-25T11:21:15+00:00', 'php': '^7.4 || ~8.0.0'}]

        inputs = project_fixtures.inputs_of({'finding': finding, 'run': self.RUN, 'run_inputs': {'_releases': given}})

        self.assertIsNone(inputs['lock'][0]['time'])

    def test_a_lock_entry_with_a_time_keeps_it_over_its_merged_release(self):
        finding = dict(self.FINDING, age={'installed': {'at': '2021-08-30T10:00:00+00:00'}})
        given = [{'version': '4.7.1', 'time': '2015-06-25T11:21:15+00:00', 'php': '^7.4 || ~8.0.0'}]

        inputs = project_fixtures.inputs_of({'finding': finding, 'run': self.RUN, 'run_inputs': {'_releases': given}})

        self.assertEqual('2021-08-30T10:00:00+00:00', inputs['lock'][0]['time'])

    def test_a_case_without_branch_rows_and_without_run_releases_gets_none(self):
        self.assertIsNone(project_fixtures.inputs_of({'finding': self.FINDING, 'run': self.RUN}))


class APackageThatIsNotFoundHasNoReleases(unittest.TestCase):
    FINDING = {'package': 'acme/routing-bridge', 'version': 'v4.4.41', 'dev': False, 'origin': {'kind': 'packagist'},
               'installed_php': {'requires': None}, 'signals': [], 'security': {'records': 0, 'ignored': []}, 'activity': {}}

    def test_a_package_that_the_registry_does_not_find_has_no_releases(self):
        facts = project_fixtures.package_facts(dict(self.FINDING, metadata={'status': 'not_found'}), [])

        self.assertNotIn('releases', facts)

    def test_a_package_that_the_registry_reads_keeps_its_installed_release(self):
        facts = project_fixtures.package_facts(dict(self.FINDING, metadata={'status': 'read'}), [])

        self.assertEqual([{'version': 'v4.4.41', 'time': None, 'php': None}], facts['releases'])


class AnUndatedSharedCommitTagTakesTheLockTimeOfTheRunInputs(unittest.TestCase):
    """The installed tag shares its commit, and no case document dates it: the model's `run_inputs._lock_time` does."""
    LOCK_TIME = '2024-09-10T14:38:51+00:00'
    ROW = {'branch': '1.x', 'installed': True, 'highest': 'v1.38.0', 'highest_released': '2025-06-20T10:00:00+00:00', 'highest_commit_date': None,
           'newest_dated': 'v1.38.0', 'newest_dated_released': '2025-06-20T10:00:00+00:00', 'dated_by': None, 'php': '>=7.2', 'highest_php': '>=7.2',
           'fixes': None}
    FINDING = {'package': 'symfony/polyfill-intl-idn', 'version': 'v1.37.0', 'dev': False, 'direct': False, 'named_in': [],
               'chain': ['symfony/polyfill-intl-idn'], 'direct_dependents': [], 'origin': {'kind': 'packagist'}, 'installed_php': {'requires': '>=7.2'},
               'signals': [], 'security': {'records': 0, 'ignored': [], 'unchecked_reason': None}, 'activity': {}, 'metadata': {'status': 'read'},
               'depth_unmeasured': None, 'next_step': None, 'age': {'installed_unmeasured': 'shared_commit'}}
    RUN = {'root_package': 'acme/app', 'project_php': None, 'target_php': '8.4', 'fail_on': 'high', 'include_dev': False, 'move_check': None}

    def case(self, finding, run_inputs):
        return {'finding': finding, 'run': self.RUN, 'details': {'metadata': {'branches': [self.ROW]}}, 'run_inputs': run_inputs}

    def test_the_lock_entry_and_the_installed_release_take_the_lock_time(self):
        inputs = project_fixtures.inputs_of(self.case(self.FINDING, {'_lock_time': {'symfony/polyfill-intl-idn': self.LOCK_TIME}}))

        installed = next(r for r in inputs['releases'] if r['version'] == 'v1.37.0')
        self.assertEqual(self.LOCK_TIME, inputs['lock'][0]['time'])
        self.assertEqual({'time': self.LOCK_TIME, 'shared_commit': True}, {k: installed.get(k) for k in ('time', 'shared_commit')})

    def test_a_date_that_the_case_documents_wins_over_the_lock_time(self):
        s5 = {'id': 'S5', 'data': {'released': '2024-09-09T08:00:00+00:00'}}
        finding = dict(self.FINDING, signals=[s5])

        inputs = project_fixtures.inputs_of(self.case(finding, {'_lock_time': {'symfony/polyfill-intl-idn': self.LOCK_TIME}}))

        self.assertEqual('2024-09-09T08:00:00+00:00', inputs['lock'][0]['time'])

    def test_a_related_package_takes_its_lock_time(self):
        root = dict(self.FINDING, package='acme/root-lib', version='1.0.0', age={'installed': {'at': '2025-01-01T00:00:00+00:00'}})
        case = dict(self.case(root, {'_lock_time': {'symfony/polyfill-intl-idn': self.LOCK_TIME}}),
                    related={'symfony/polyfill-intl-idn': {'finding': self.FINDING, 'details': {'metadata': {'branches': [self.ROW]}}}})

        inputs = project_fixtures.inputs_of(case)

        self.assertEqual(self.LOCK_TIME, inputs['packages']['symfony/polyfill-intl-idn']['lock']['time'])


class ACached404KeepsItsCacheTime(unittest.TestCase):
    FINDING = dict(AnUndatedSharedCommitTagTakesTheLockTimeOfTheRunInputs.FINDING, age={'installed': {'at': '2025-01-01T00:00:00+00:00'}})
    ACTIVITY = {'forge': 'github', 'host': 'github.com', 'repo': 'hoaproject/Consistency', 'event': None, 'archived': None, 'fetched_at': None,
                'cached_at': '2026-09-28T08:00:00+00:00'}

    def inputs(self, activity):
        case = AnUndatedSharedCommitTagTakesTheLockTimeOfTheRunInputs().case(dict(self.FINDING, activity=activity), None)
        return project_fixtures.inputs_of(case)['activity']

    def test_a_not_found_activity_gives_the_hydrator_its_cache_time(self):
        activity = self.inputs(dict(self.ACTIVITY, status='not_found', reason='repository_not_found'))

        self.assertEqual('2026-09-28T08:00:00+00:00', activity['cached_at'])

    def test_a_read_activity_gives_no_cache_time(self):
        activity = self.inputs(dict(self.ACTIVITY, status='read', reason=None, event='push', archived=False, fetched_at='2026-10-01T16:04:32+00:00'))

        self.assertNotIn('cached_at', activity)


if __name__ == '__main__':
    unittest.main()


class AParentThatDatesABranchRowHoldsItsTags(unittest.TestCase):
    ROW = {'branch': '2.x', 'installed': True, 'highest': 'v2.8.52', 'highest_released': '2019-11-13T08:36:42+00:00', 'highest_commit_date': None,
           'newest_dated': 'v2.6.13', 'newest_dated_released': '2021-08-30T10:00:00+00:00', 'dated_by': 'symfony/symfony', 'php': '>=5.3.9',
           'highest_php': '>=5.3.9', 'fixes': None}
    DATED = {'at': '2021-08-30T10:00:00+00:00', 'dated_by': 'symfony/symfony'}
    FINDING = {'package': 'symfony/monolog-bridge', 'version': 'v2.6.13', 'dev': False, 'origin': {'kind': 'packagist'}, 'installed_php': {'requires': '>=5.3.3'},
               'signals': [], 'security': {'records': 0, 'ignored': []}, 'activity': {}, 'metadata': {'status': 'read'},
               'age': {'installed': DATED, 'branch_release': dict(DATED, version='v2.6.13')}}

    def test_the_parent_holds_the_highest_and_the_newest_dated_tag_of_the_row_once_each(self):
        facts = project_fixtures.package_facts(self.FINDING, [self.ROW])

        self.assertEqual({'name': 'symfony/symfony', 'releases': [{'version': 'v2.6.13', 'time': '2021-08-30T10:00:00+00:00'},
                                                                  {'version': 'v2.8.52', 'time': '2019-11-13T08:36:42+00:00'}]}, facts['parent'])

    def test_the_highest_tag_of_a_row_that_the_parent_dates_shares_its_commit(self):
        releases = {r['version']: r for r in project_fixtures.package_facts(self.FINDING, [self.ROW])['releases']}

        self.assertTrue(releases['v2.8.52'].get('shared_commit'))

    def test_a_row_without_a_parent_gives_no_parent(self):
        finding = dict(self.FINDING, age={'installed': {'at': '2021-08-30T10:00:00+00:00'}})

        self.assertNotIn('parent', project_fixtures.package_facts(finding, [dict(self.ROW, dated_by=None)]))


class ACaseWhoseDesignedRowsMissItsNewestReleaseIsNotHydrated(unittest.TestCase):
    @staticmethod
    def case(newest, world=True):
        return {'run': {}, 'finding': {'metadata': {'status': 'read'}, 'age': {'release': {'version': newest}}},
                'details': {'metadata': {'branches': [{'branch': '2.x'}]}}, 'solver_world': {} if world else None}

    def test_a_world_whose_newest_release_opens_a_branch_without_a_row_is_not_hydrated(self):
        self.assertEqual('designed-rows', project_fixtures.unhydrated(self.case('3.0.0')))

    def test_a_newest_release_on_a_row_or_a_newest_pre_release_hydrates(self):
        self.assertIsNone(project_fixtures.unhydrated(self.case('2.12.3')))
        self.assertIsNone(project_fixtures.unhydrated(self.case('3.0.0-beta.1')))
        self.assertIsNone(project_fixtures.unhydrated(self.case('3.0.0', world=False)))
