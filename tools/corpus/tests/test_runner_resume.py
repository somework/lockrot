"""The resume contract, proved against a stub archive rather than a real one.

The case that matters is not "a finished target is not re-run" — it is "a half-written one is not
treated as finished". A run killed at the timeout leaves a JSON of non-zero size, and the shell
guard it replaces (`[ -s file ] && continue`) accepted that forever: one reader aborted on it, one
counted it unreadable, none of them retried it.
"""

import os
import shutil
import stat
import tempfile
import unittest

import support  # noqa: F401 - puts the package on the path

from lockrot_corpus import runner
from lockrot_corpus.jsonio import read_json, write_text_atomic

STUB = """#!/bin/sh
# Stands in for lockrot.phar: prints a report and counts how often it was asked to.
echo "$PWD" >> "$COUNTER"
printf '%s' '{"lockrot":{"version":"0.0.0-stub"},"findings":[]}'
"""


class Resume(unittest.TestCase):
    def setUp(self):
        self.root = tempfile.mkdtemp(prefix='corpus-resume-')
        self.projects = os.path.join(self.root, 'projects')
        os.makedirs(os.path.join(self.projects, 'a-project'))
        write_text_atomic(os.path.join(self.projects, 'a-project', 'composer.lock'), '{}')
        self.counter = os.path.join(self.root, 'calls')
        self.phar = os.path.join(self.root, 'stub.sh')
        write_text_atomic(self.phar, STUB)
        os.chmod(self.phar, os.stat(self.phar).st_mode | stat.S_IEXEC)
        self.out = os.path.join(self.root, 'out')
        os.environ['GITHUB_TOKEN'] = 'not-a-real-token'
        os.environ['COUNTER'] = self.counter

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)
        os.environ.pop('COUNTER', None)

    def _run(self):
        # A shell stub stands in for the archive, because a real one cannot be asked to die
        # halfway through a write, and that is the case worth proving.
        return runner.run_reports(self.phar, self.projects, self.out,
                                  os.path.join(self.root, 'cache'), '2026-09-23',
                                  interpreter='/bin/sh')

    def _calls(self):
        try:
            with open(self.counter, encoding='utf-8') as handle:
                return len(handle.readlines())
        except FileNotFoundError:
            return 0

    def test_the_output_directory_is_created_rather_than_assumed(self):
        self.assertFalse(os.path.isdir(self.out))
        self._run()
        self.assertTrue(os.path.isfile(os.path.join(self.out, 'a-project.json')))

    def test_a_finished_target_is_not_run_again(self):
        self._run()
        first = self._calls()
        self._run()
        self.assertEqual(first, self._calls(), 'a completed target was run a second time')

    def test_a_target_whose_output_changed_underneath_is_run_again(self):
        self._run()
        first = self._calls()
        write_text_atomic(os.path.join(self.out, 'a-project.json'), '{"findings": [] }  ')
        self._run()
        self.assertGreater(self._calls(), first,
                           'the recorded digest no longer matched and the target was not redone')

    def test_a_non_empty_but_unrecorded_file_is_not_mistaken_for_a_finished_run(self):
        """The timeout case: bytes on disk, nothing in the manifest, and it must be redone."""
        os.makedirs(self.out, exist_ok=True)
        write_text_atomic(os.path.join(self.out, 'a-project.json'), '{"findings": [tru')
        self._run()
        self.assertEqual(1, self._calls())
        self.assertEqual({'findings': []}, {'findings': read_json(
            os.path.join(self.out, 'a-project.json'))['findings']})

    def test_stderr_is_kept_beside_the_output(self):
        self._run()
        self.assertTrue(os.path.isfile(os.path.join(self.out, 'a-project.err')))

    def test_the_run_records_its_day_its_token_mode_and_the_archive_digest(self):
        manifest = self._run()
        self.assertEqual('2026-09-23', manifest['today'])
        self.assertEqual('token', manifest['token_mode'])
        self.assertEqual(64, len(manifest['phar_sha256']))
        self.assertNotIn('not-a-real-token', str(manifest),
                         'the token itself must never reach a recorded file')

    def test_the_run_records_what_it_set_out_to_cover_before_it_covers_any_of_it(self):
        """A target reaches the manifest when the run reaches it; the intent has to be there first.

        Otherwise a run killed at the 35th of 39 projects holds 35 records and nothing anywhere
        names the other four — not the status list, not a file, not the directory.
        """
        os.makedirs(os.path.join(self.projects, 'b-project'))
        write_text_atomic(os.path.join(self.projects, 'b-project', 'composer.lock'), '{}')
        manifest = self._run()
        self.assertEqual(['a-project', 'b-project'], manifest['intended'])

    def test_a_resumed_run_stops_claiming_the_first_run_s_finish(self):
        """`finished` used to survive a resume, so a second interruption kept the first completion."""
        first = self._run()
        self.assertIsNotNone(first['finished'])
        write_text_atomic(os.path.join(self.out, 'a-project.json'), '{"findings": [ ')
        recorded = []

        def die(*args, **kwargs):
            recorded.append(read_json(os.path.join(self.out, 'run.json')))
            raise KeyboardInterrupt

        saved, runner.subprocess.run = runner.subprocess.run, die
        try:
            with self.assertRaises(KeyboardInterrupt):
                self._run()
        finally:
            runner.subprocess.run = saved
        self.assertIsNone(recorded[0]['finished'],
                          'the resumed run still carried the earlier run as finished')

    def test_resuming_a_directory_written_by_another_run_is_refused(self):
        self._run()
        with self.assertRaises(runner.RunError) as raised:
            runner.run_reports(self.phar, self.projects, self.out,
                               os.path.join(self.root, 'cache'), '2020-01-01',
                               interpreter='/bin/sh')
        self.assertIn('today', str(raised.exception))


class TokenMode(unittest.TestCase):
    def test_a_tokenless_run_has_to_be_asked_for(self):
        saved = os.environ.pop('GITHUB_TOKEN', None)
        path = os.environ.get('PATH', '')
        try:
            os.environ['PATH'] = '/nonexistent'  # no `gh` to fall back to
            with self.assertRaises(runner.RunError) as raised:
                runner.token_from_environment(anon=False)
            self.assertIn('--anon', str(raised.exception))
            self.assertIsNone(runner.token_from_environment(anon=True))
        finally:
            os.environ['PATH'] = path
            if saved is not None:
                os.environ['GITHUB_TOKEN'] = saved


if __name__ == '__main__':
    unittest.main()
