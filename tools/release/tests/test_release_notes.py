import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))

import release_notes  # noqa: E402

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..')

CHANGELOG = (
    '# Changelog\n\n'
    '## [Unreleased]\n\n- **CLI:** next.\n\n'
    '## [0.14.0] - 2026-11-20\n\n'
    'Regenerate the baseline.\n\n'
    '### Added\n\n'
    '- **All formats:** the score ([The score](docs/verdicts.md#score), [Install](docs/index.md#install),\n'
    '  [Upgrading](docs/changes/0.14.md), [Model](docs/verdicts/model-1.md#score-lead)).\n'
    '- **sarif:** no `[text](uri)` link, see [SECURITY.md](SECURITY.md#what) and [spec](https://example.test/a.md).\n\n'
    '## [0.13.0] - 2026-10-01\n\n'
    '- **CLI:** old.\n\n'
    '[Unreleased]: https://github.com/somework/lockrot/compare/v0.14.0...HEAD\n'
    '[0.14.0]: https://github.com/somework/lockrot/compare/v0.13.0...v0.14.0\n'
    '[0.13.0]: https://github.com/somework/lockrot/compare/v0.12.0...v0.13.0\n'
)


class TheReleaseNotes(unittest.TestCase):
    def test_hold_the_section_of_the_version_and_nothing_after_it(self):
        notes = release_notes.notes('0.14.0', CHANGELOG, False)

        self.assertTrue(notes.startswith('Regenerate the baseline.\n\n### Added\n'), notes)
        self.assertNotIn('old.', notes)
        self.assertNotIn('next.', notes)

    def test_of_the_oldest_section_stop_at_the_link_definitions(self):
        notes = release_notes.notes('0.13.0', CHANGELOG, False)

        self.assertEqual('- **CLI:** old.', notes)

    def test_point_each_docs_link_at_its_lockrot_dev_page(self):
        notes = release_notes.notes('0.14.0', CHANGELOG, False)

        self.assertIn('[The score](https://lockrot.dev/verdicts/#score)', notes)
        self.assertIn('[Install](https://lockrot.dev/#install)', notes)
        self.assertIn('[Upgrading](https://lockrot.dev/changes/0.14/)', notes)
        self.assertIn('[Model](https://lockrot.dev/verdicts/model-1/#score-lead)', notes)

    def test_point_another_relative_link_at_the_file_of_the_tag(self):
        notes = release_notes.notes('0.14.0', CHANGELOG, False)

        self.assertIn('[SECURITY.md](https://github.com/somework/lockrot/blob/v0.14.0/SECURITY.md#what)', notes)
        self.assertIn('[spec](https://example.test/a.md)', notes)

    def test_keep_a_link_inside_a_code_span(self):
        self.assertIn('`[text](uri)`', release_notes.notes('0.14.0', CHANGELOG, False))

    def test_open_with_the_upgrade_page_when_the_minor_has_one(self):
        notes = release_notes.notes('0.14.0', CHANGELOG, True)

        self.assertTrue(notes.startswith('[Upgrading to 0.14](https://lockrot.dev/changes/0.14/) has the steps for each install.\n\nRegenerate'), notes)

    def test_fail_on_a_version_without_a_dated_section(self):
        for version in ('0.15.0', 'Unreleased', '0.14'):
            with self.subTest(version=version), self.assertRaises(release_notes.NoSection):
                release_notes.notes(version, CHANGELOG, False)

    def test_fail_on_an_empty_section(self):
        with self.assertRaises(release_notes.NoSection):
            release_notes.notes('0.14.0', '## [0.14.0] - 2026-11-20\n\n## [0.13.0] - 2026-10-01\n\n- x.\n', False)


class TheChangelogOfThisRepository(unittest.TestCase):
    def test_gives_each_dated_release_its_notes_without_a_relative_link(self):
        with open(os.path.join(ROOT, 'CHANGELOG.md'), encoding='utf-8') as handle:
            changelog = handle.read()
        versions = release_notes.DATED.findall(changelog)
        self.assertIn('0.13.0', versions)

        for version in versions:
            with self.subTest(version=version):
                notes = release_notes.notes(version, changelog, False)

                self.assertNotIn('\n## ', notes)
                self.assertNotRegex(notes, r'\]\((?!https?://|#)')
        self.assertTrue(release_notes.notes('0.13.0', changelog, False).startswith('Act if you publish reports'))

    def test_main_reads_the_upgrade_page_of_the_minor(self):
        self.assertTrue(os.path.isfile(os.path.join(ROOT, release_notes.upgrade_page('0.14.0'))))
        self.assertFalse(os.path.isfile(os.path.join(ROOT, release_notes.upgrade_page('0.13.0'))))


if __name__ == '__main__':
    unittest.main()
