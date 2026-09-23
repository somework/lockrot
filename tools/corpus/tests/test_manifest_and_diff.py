"""The pinning, and the refusals that make a comparison mean something.

The refusal paths are the reason the run metadata is recorded at all. A differ that compares
whatever it is handed is the mechanism by which the calendar, an expired cache or a missing token
gets filed as a code regression — which is exactly what happened to the first corpus comparison of
this project, where a package crossing the three-year threshold between two runs read as a change
the branch had made.
"""

import os
import shutil
import tempfile
import unittest

import support  # noqa: F401 - puts the package on the path

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

    The marker used to be dropped the moment the commit resolved and set again the moment the files
    did not, so a project whose composer.lock is simply still missing counted two changes and had
    its date rewritten on every single run — losing the one thing the date is for, which is the day
    it first went missing.
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


if __name__ == '__main__':
    unittest.main()
