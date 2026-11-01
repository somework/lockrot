import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))

import version_bump  # noqa: E402


def version_php(version):
    return "<?php\n\nnamespace Lockrot;\n\nfinal class Version\n{\n    public const STRING = '%s';\n}\n" % version


def changelog(*headings):
    return '# Changelog\n\n## [Unreleased]\n\n' + ''.join('## %s\n\n- **CLI:** x.\n\n' % h for h in headings)


def schema_test(*versions):
    rows = ''.join("        '%s' => [\n            'lockrot-report-2.schema.json' => 'ab',\n        ],\n" % v for v in versions)
    return 'final class SchemaEvolutionTest\n{\n    private const RELEASED_SCHEMAS = [\n' + rows + '    ];\n}\n'


RELEASED = {
    'changelog': changelog('[0.14.0] - 2026-11-20', '[0.13.0] - 2026-10-01'),
    'schemas': schema_test('0.13.0', '0.14.0'),
}


class AReleasePullRequest(unittest.TestCase):
    def check(self, base, head, changelog_text=RELEASED['changelog'], schemas=RELEASED['schemas']):
        return version_bump.problems(version_php(base), version_php(head), changelog_text, schemas)

    def test_a_correct_minor_release_passes(self):
        self.assertEqual([], self.check('0.13.0', '0.14.0'))

    def test_a_patch_and_a_major_release_pass(self):
        released = changelog('[1.0.0] - 2026-12-01', '[0.13.1] - 2026-11-02')
        schemas = schema_test('0.13.1', '1.0.0')
        self.assertEqual([], self.check('0.13.0', '0.13.1', released, schemas))
        self.assertEqual([], self.check('0.13.1', '1.0.0', released, schemas))

    def test_a_release_without_the_bump_fails(self):
        self.assertEqual(
            ['Version::STRING is 0.13.0 on both sides: a release pull request bumps it'],
            self.check('0.13.0', '0.13.0', changelog('[Unreleased]', '[0.13.0] - 2026-10-01'), schema_test('0.13.0')),
        )

    def test_a_skipped_or_backward_version_fails(self):
        self.assertEqual(['Version::STRING 0.15.0 is no bump of 0.13.0: take the next patch, minor or major'], self.check('0.13.0', '0.15.0', changelog('[0.15.0] - 2026-11-20'), schema_test('0.15.0')))
        self.assertEqual(['Version::STRING 0.12.0 is no bump of 0.13.0: take the next patch, minor or major'], self.check('0.13.0', '0.12.0', changelog('[0.12.0] - 2026-11-20'), schema_test('0.12.0')))
        self.assertEqual(['Version::STRING 0.13.2 is no bump of 0.13.0: take the next patch, minor or major'], self.check('0.13.0', '0.13.2', changelog('[0.13.2] - 2026-11-20'), schema_test('0.13.2')))

    def test_a_bump_without_a_dated_changelog_heading_fails(self):
        self.assertEqual(
            ['CHANGELOG.md has no "## [0.14.0] - YYYY-MM-DD" heading'],
            self.check('0.13.0', '0.14.0', changelog('[Unreleased]', '[0.13.0] - 2026-10-01'), RELEASED['schemas']),
        )

    def test_a_bump_without_its_schema_copies_fails(self):
        self.assertEqual(
            ['RELEASED_SCHEMAS of tests/Integration/SchemaEvolutionTest.php has no 0.14.0 entry'],
            self.check('0.13.0', '0.14.0', RELEASED['changelog'], schema_test('0.13.0')),
        )

    def test_an_unreadable_version_fails(self):
        self.assertEqual(
            ['src/Version.php of the head holds no X.Y.Z STRING'],
            version_bump.problems(version_php('0.13.0'), version_php('0.14.0-rc.9'), RELEASED['changelog'], RELEASED['schemas']),
        )


class TheRepository(unittest.TestCase):
    def test_reads_the_head_files_that_the_guard_names(self):
        root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..')
        for path in version_bump.HEAD_FILES:
            self.assertTrue(os.path.isfile(os.path.join(root, path)), path)
        with open(os.path.join(root, 'tests/Integration/SchemaEvolutionTest.php')) as handle:
            self.assertIn('0.13.0', version_bump.released_schemas(handle.read()))


if __name__ == '__main__':
    unittest.main()
