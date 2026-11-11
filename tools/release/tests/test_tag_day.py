import os
import subprocess
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))

import tag_day  # noqa: E402

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..')

CHANGELOG = (
    '## [Unreleased]\n\n- next.\n\n'
    '## [0.14.0] - 2026-11-20\n\n- x.\n\n'
    '## [0.13.0] - 2026-10-01\n\n- y.\n'
)


class TheTagDay(unittest.TestCase):
    def test_is_the_date_of_the_version_heading(self):
        self.assertEqual('2026-11-20', tag_day.heading_day(CHANGELOG, '0.14.0'))
        self.assertEqual('2026-10-01', tag_day.heading_day(CHANGELOG, '0.13.0'))

    def test_has_no_problem_when_the_heading_names_the_tag_day(self):
        self.assertEqual([], tag_day.problems(CHANGELOG, '0.14.0', '2026-11-20'))

    def test_names_both_days_when_the_heading_is_another_day(self):
        found = tag_day.problems(CHANGELOG, '0.14.0', '2026-11-21')

        self.assertEqual(1, len(found))
        self.assertIn('2026-11-20', found[0])
        self.assertIn('2026-11-21', found[0])

    def test_fails_without_a_dated_heading_for_the_version(self):
        for version in ('0.15.0', 'Unreleased'):
            with self.subTest(version=version):
                self.assertEqual(1, len(tag_day.problems(CHANGELOG, version, '2026-11-20')))

    def test_rejects_a_tag_day_that_is_no_date(self):
        self.assertEqual(1, len(tag_day.problems(CHANGELOG, '0.14.0', 'today')))

    def test_main_exits_1_on_a_mismatch_and_0_on_a_match(self):
        script = os.path.join(ROOT, 'tools', 'release', 'tag_day.py')
        with open(os.path.join(ROOT, 'CHANGELOG.md'), encoding='utf-8') as handle:
            day = tag_day.heading_day(handle.read(), '0.13.0')

        match = subprocess.run([sys.executable, script, '0.13.0', day], capture_output=True, text=True)
        mismatch = subprocess.run([sys.executable, script, '0.13.0', '2000-01-01'], capture_output=True, text=True)
        usage = subprocess.run([sys.executable, script], capture_output=True, text=True)

        self.assertEqual((0, ''), (match.returncode, match.stdout))
        self.assertEqual(1, mismatch.returncode)
        self.assertIn('::error::', mismatch.stdout)
        self.assertEqual(2, usage.returncode)

    def test_main_with_warning_annotates_a_mismatch_and_exits_0(self):
        script = os.path.join(ROOT, 'tools', 'release', 'tag_day.py')

        warned = subprocess.run([sys.executable, script, '0.13.0', '2000-01-01', '--warning'], capture_output=True, text=True)

        self.assertEqual(0, warned.returncode)
        self.assertTrue(warned.stdout.startswith('::warning::'), warned.stdout)
        self.assertNotIn('::error::', warned.stdout)


if __name__ == '__main__':
    unittest.main()
