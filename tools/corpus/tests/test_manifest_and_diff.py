"""The pinning, and the refusals that make a comparison meaningful.

The run metadata exists for the refusal paths. A differ that compares whatever it gets files the
calendar, an expired cache or a missing token as a code regression.
"""

import os
import shutil
import tempfile
import unittest

import support

from lockrot_corpus import diff, fetch, manifest
from lockrot_corpus.jsonio import write_json_atomic


def _project(name, commit='a' * 40, unavailable=None):
    entry = {'name': name, 'kind': 'github', 'repo': 'owner/' + name, 'commit': commit,
             'files': {'composer.lock': {'sha256': 'b' * 64, 'bytes': 10},
                       'composer.json': {'sha256': 'c' * 64, 'bytes': 10}}}
    if unavailable:
        entry['unavailable'] = unavailable

    return entry


class Manifest(unittest.TestCase):
    def test_an_unavailable_project_is_kept_in_the_file_and_left_out_of_the_run(self):
        document = {'projects': [_project('one'), _project('two', unavailable='2026-09-23')]}
        self.assertEqual(['one'], [item['name'] for item in manifest.available(document)])
        self.assertEqual(2, len(document['projects']),
                         'a project that stopped resolving must stay a visible line in the diff')

    def test_the_corpus_digest_moves_when_a_pin_moves(self):
        before = manifest.digest_of({'projects': [_project('one')]})
        after = manifest.digest_of({'projects': [_project('one', commit='d' * 40)]})
        self.assertNotEqual(before, after)

    def test_the_corpus_digest_ignores_the_order_of_unrelated_fields(self):
        one = manifest.digest_of({'projects': [_project('one')]})
        other = dict(_project('one'))
        other['note'] = 'a field that decides nothing'
        self.assertEqual(one, manifest.digest_of({'projects': [other]}))


class RefreshChurn(unittest.TestCase):
    """A refresh that finds nothing new must leave no diff, unavailable projects included.

    The `unavailable` date records the day a project first went missing, so a refresh must not
    rewrite it while the files stay missing.
    """

    def setUp(self):
        self.root = tempfile.mkdtemp(prefix='corpus-refresh-')
        self.saved = (manifest._resolve_head, manifest._fetch_raw, manifest.load,
                      manifest.write_json_atomic)
        self.written = []
        manifest._resolve_head = lambda owner_repo: 'e' * 40
        manifest.write_json_atomic = lambda path, document: self.written.append(document)

    def tearDown(self):
        (manifest._resolve_head, manifest._fetch_raw, manifest.load,
         manifest.write_json_atomic) = self.saved
        shutil.rmtree(self.root, ignore_errors=True)

    def _refresh(self, document, present, today='2026-10-01'):
        manifest.load = lambda repo_root: document
        manifest._fetch_raw = lambda owner_repo, commit, name: (b'{}' if present else None)

        return manifest.refresh(self.root, today)

    def test_a_project_whose_files_are_still_missing_is_not_rewritten(self):
        document = {'projects': [_project('one', unavailable='2026-09-23')]}
        self.assertEqual(0, self._refresh(document, present=False))
        self.assertEqual([], self.written, 'a refresh that found nothing new rewrote the file')
        self.assertEqual('2026-09-23', document['projects'][0]['unavailable'],
                         'the day it first went missing was overwritten with today')

    def test_a_project_whose_files_have_come_back_drops_the_marker_once(self):
        # Pinned to the commit _resolve_head answers with, so the marker is the only thing moving.
        document = {'projects': [_project('one', commit='e' * 40, unavailable='2026-09-23')]}
        self.assertEqual(1, self._refresh(document, present=True))
        self.assertNotIn('unavailable', document['projects'][0])

    def test_a_project_that_has_just_gone_missing_is_dated_today(self):
        document = {'projects': [_project('one')]}
        self.assertEqual(1, self._refresh(document, present=False))
        self.assertEqual('2026-10-01', document['projects'][0]['unavailable'])


class FetchRefusals(unittest.TestCase):
    def setUp(self):
        self.root = tempfile.mkdtemp(prefix='corpus-fetch-')

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)

    def test_a_file_whose_digest_does_not_match_is_not_accepted(self):
        target = os.path.join(self.root, 'projects', 'one')
        os.makedirs(target)
        with open(os.path.join(target, 'composer.lock'), 'w', encoding='utf-8') as handle:
            handle.write('{"packages": []}')
        self.assertFalse(fetch._already_correct(_project('one'), target),
                         'bytes that hash to something else are not the pinned bytes')

    def test_a_fixture_project_is_copied_from_the_tree_rather_than_fetched(self):
        document = {'projects': [{'name': 'a-fixture', 'kind': 'fixture',
                                  'path': 'tools/corpus/fixtures/claims/projects/'
                                          + _some_fixture_project()}]}
        written, _, failures = fetch.materialise(document, support.REPO_ROOT,
                                                 os.path.join(self.root, 'projects'))
        self.assertEqual([], failures)
        self.assertEqual(1, written)
        self.assertTrue(os.path.isfile(
            os.path.join(self.root, 'projects', 'a-fixture', 'composer.lock')))


def _some_fixture_project():
    root = os.path.join(support.FIXTURES, 'claims', 'projects')

    return sorted(os.listdir(root))[0]


class DiffRefusals(unittest.TestCase):
    def setUp(self):
        self.root = tempfile.mkdtemp(prefix='corpus-diff-')

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)

    def _run(self, name, **overrides):
        directory = os.path.join(self.root, name)
        document = {'today': '2026-09-23', 'token_mode': 'token', 'cache_root': '/a/cache',
                    'phar_sha256': name * 8, 'lockrot_version': '0.11.0',
                    'started': '2026-09-23T08:00:00+00:00',
                    'finished': '2026-09-23T09:00:00+00:00', 'targets': {}}
        document.update(overrides)
        write_json_atomic(os.path.join(directory, 'run.json'), document)

        return directory

    def _findings(self, directory, project, findings):
        write_json_atomic(os.path.join(directory, project + '.json'), {'findings': findings})

    def test_two_runs_pinned_to_different_days_are_refused(self):
        with self.assertRaises(diff.NotComparable) as raised:
            diff.compare(self._run('a'), self._run('b', today='2026-01-01'))
        self.assertIn('pinned day', str(raised.exception))

    def test_a_token_run_and_a_tokenless_run_are_refused(self):
        with self.assertRaises(diff.NotComparable) as raised:
            diff.compare(self._run('a'), self._run('b', token_mode='anonymous'))
        self.assertIn('token mode', str(raised.exception))

    def test_two_runs_over_different_caches_are_refused(self):
        with self.assertRaises(diff.NotComparable):
            diff.compare(self._run('a'), self._run('b', cache_root='/another/cache'))

    def test_a_directory_with_no_run_metadata_is_refused(self):
        bare = os.path.join(self.root, 'bare')
        os.makedirs(bare)
        with self.assertRaises(diff.NotComparable):
            diff.compare(self._run('a'), bare)

    def test_two_runs_further_apart_than_the_activity_cache_lives_are_refused(self):
        with self.assertRaises(diff.NotComparable) as raised:
            diff.compare(self._run('a'),
                         self._run('b', started='2026-09-25T08:00:00+00:00',
                                   finished='2026-09-25T09:00:00+00:00'))
        self.assertIn('hours apart', str(raised.exception))

    def test_a_run_that_recorded_no_time_at_all_cannot_be_shown_comparable(self):
        with self.assertRaises(diff.NotComparable) as raised:
            diff.compare(self._run('a'), self._run('b', started=None, finished=None))
        self.assertIn('no start or finish time', str(raised.exception))

    def test_the_same_archive_twice_is_refused(self):
        with self.assertRaises(diff.NotComparable) as raised:
            diff.compare(self._run('a'), self._run('b', phar_sha256='a' * 8))
        self.assertIn('same archive', str(raised.exception))


class DiffCounts(unittest.TestCase):
    def setUp(self):
        self.root = tempfile.mkdtemp(prefix='corpus-diffcount-')
        self.a = os.path.join(self.root, 'a')
        self.b = os.path.join(self.root, 'b')
        for directory, digest in ((self.a, 'a' * 8), (self.b, 'b' * 8)):
            write_json_atomic(os.path.join(directory, 'run.json'),
                              {'today': '2026-09-23', 'token_mode': 'token',
                               'cache_root': '/a/cache', 'phar_sha256': digest,
                               'lockrot_version': '0.11.0',
                               'started': '2026-09-23T08:00:00+00:00',
                               'finished': '2026-09-23T09:00:00+00:00', 'targets': {}})

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)

    def _write(self, directory, findings):
        write_json_atomic(os.path.join(directory, 'p.json'), {'findings': findings})

    def test_libyears_becoming_unmeasured_is_its_own_outcome(self):
        self._write(self.a, [{'package': 'x/y', 'verdict': 'ok', 'libyears': 3.0, 'signals': []}])
        self._write(self.b, [{'package': 'x/y', 'verdict': 'ok', 'libyears': None, 'signals': []}])
        result = diff.compare(self.a, self.b)
        self.assertIn('libyears measured -> unmeasured', result['changes'])

    def test_a_package_on_one_side_only_is_never_counted_as_a_verdict_change(self):
        self._write(self.a, [{'package': 'x/y', 'verdict': 'ok', 'signals': []}])
        self._write(self.b, [{'package': 'x/z', 'verdict': 'stale', 'signals': []}])
        result = diff.compare(self.a, self.b)
        self.assertEqual(2, sum(count for key, count in result['changes'].items()
                                if key.startswith('package only in')))
        self.assertFalse([key for key in result['changes'] if key.startswith('verdict')])

    def test_a_project_unreadable_on_one_side_is_named_rather_than_skipped(self):
        self._write(self.b, [{'package': 'x/y', 'verdict': 'ok', 'signals': []}])
        result = diff.compare(self.a, self.b)
        self.assertEqual(['p'], result['missing'])

    def test_a_project_that_died_on_the_second_side_is_still_named(self):
        """The side with no file is the one a listing of the other side cannot see."""
        self._write(self.a, [{'package': 'x/y', 'verdict': 'ok', 'signals': []}])
        result = diff.compare(self.a, self.b)
        self.assertEqual(['p'], result['missing'])



class DiffSchemas(unittest.TestCase):
    """The `lockrot.schema` guard: one row per pair of document numbers. Each row reads the claims
    reports recorded under both numbers, so a guard that reads the wrong field fails on real output."""

    PROJECT = 'BookStackApp_BookStack'

    def setUp(self):
        self.root = tempfile.mkdtemp(prefix='corpus-diffschema-')
        self.a = os.path.join(self.root, 'a')
        self.b = os.path.join(self.root, 'b')
        for directory, digest in ((self.a, 'a' * 8), (self.b, 'b' * 8)):
            write_json_atomic(os.path.join(directory, 'run.json'),
                              {'today': '2026-10-14', 'token_mode': 'token', 'cache_root': '/a/cache',
                               'phar_sha256': digest, 'lockrot_version': '0.14.0',
                               'started': '2026-10-14T08:00:00+00:00',
                               'finished': '2026-10-14T09:00:00+00:00', 'targets': {}})

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)

    def _recorded(self, directory, schema_dir):
        shutil.copy(os.path.join(support.FIXTURES, 'claims', schema_dir, self.PROJECT + '.json'),
                    os.path.join(directory, self.PROJECT + '.json'))

    def _write(self, directory, document):
        write_json_atomic(os.path.join(directory, 'p.json'), document)

    def test_report_1_against_report_1_compares_verdict_and_priority(self):
        self._write(self.a, {'findings': [{'package': 'x/y', 'verdict': 'stale', 'priority': 'low', 'signals': []}]})
        self._write(self.b, {'findings': [{'package': 'x/y', 'verdict': 'silent', 'priority': 'high', 'signals': []}]})
        result = diff.compare(self.a, self.b)
        self.assertEqual({'verdict stale -> silent': 1, 'priority low -> high': 1}, result['changes'])
        self.assertNotIn('verdicts not compared', diff.render(result, self.a, self.b))

    def test_report_1_against_report_1_on_the_recorded_reports_finds_no_change(self):
        self._recorded(self.a, 'reports-1')
        self._recorded(self.b, 'reports-1')
        result = diff.compare(self.a, self.b)
        self.assertEqual([(1, 1)], result['schemas'])
        self.assertEqual({}, result['changes'])

    def test_report_1_against_report_2_compares_lead_with_the_old_verdict_only(self):
        self._write(self.a, {'findings': [
            {'package': 'x/y', 'verdict': 'stale', 'priority': 'low', 'signals': []},
            {'package': 'x/z', 'verdict': 'ok', 'priority': 'none', 'signals': []}]})
        self._write(self.b, {'lockrot': {'schema': 2}, 'findings': [
            {'package': 'x/y', 'verdict': 'medium', 'priority': 'medium', 'lead': 'silent', 'signals': []},
            {'package': 'x/z', 'verdict': 'critical', 'priority': 'critical', 'lead': None, 'signals': []}]})
        result = diff.compare(self.a, self.b)
        self.assertEqual({'lead stale -> silent': 1}, result['changes'])
        self.assertIn('report-1 vs report-2: verdicts not compared', diff.render(result, self.a, self.b))

    def test_report_1_against_report_2_on_the_recorded_reports_keeps_every_lead(self):
        self._recorded(self.a, 'reports-1')
        self._recorded(self.b, 'reports-2')
        result = diff.compare(self.a, self.b)
        self.assertEqual([(1, 2)], result['schemas'])
        self.assertEqual([], [key for key in result['changes'] if key.split(' ')[0] in ('verdict', 'priority', 'lead')])
        self.assertIn('report-1 vs report-2: verdicts not compared', diff.render(result, self.a, self.b))

    def test_report_2_against_report_2_compares_verdict_and_lead(self):
        self._write(self.a, {'lockrot': {'schema': 2}, 'findings': [
            {'package': 'x/y', 'verdict': 'high', 'priority': 'high', 'lead': 'stale', 'signals': []}]})
        self._write(self.b, {'lockrot': {'schema': 2}, 'findings': [
            {'package': 'x/y', 'verdict': 'critical', 'priority': 'critical', 'lead': 'abandoned', 'signals': []}]})
        result = diff.compare(self.a, self.b)
        self.assertEqual({'verdict high -> critical': 1, 'lead stale -> abandoned': 1}, result['changes'])
        self.assertNotIn('verdicts not compared', diff.render(result, self.a, self.b))

    def test_report_2_against_report_2_on_the_recorded_reports_finds_no_change(self):
        self._recorded(self.a, 'reports-2')
        self._recorded(self.b, 'reports-2')
        result = diff.compare(self.a, self.b)
        self.assertEqual([(2, 2)], result['schemas'])
        self.assertEqual({}, result['changes'])


if __name__ == '__main__':
    unittest.main()
