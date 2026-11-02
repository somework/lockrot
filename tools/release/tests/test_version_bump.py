import json
import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))

import version_bump  # noqa: E402


def version_php(version):
    return "<?php\n\nnamespace Lockrot;\n\nfinal class Version\n{\n    public const STRING = '%s';\n}\n" % version


LINKS_0_14 = (
    '[Unreleased]: https://github.com/somework/lockrot/compare/v0.14.0...HEAD\n'
    '[0.14.0]: https://github.com/somework/lockrot/compare/v0.13.0...v0.14.0\n'
    '[0.13.0]: https://github.com/somework/lockrot/compare/v0.12.0...v0.13.0\n'
)


def changelog(*headings, links=LINKS_0_14):
    return '# Changelog\n\n## [Unreleased]\n\n' + ''.join('## %s\n\n- **CLI:** x.\n\n' % h for h in headings) + links


def manifest(version):
    return json.dumps({'name': 'lockrot-report', 'version': version})


def package_lock(version, root_version=None):
    return json.dumps({'name': 'lockrot-report', 'version': version, 'packages': {'': {'name': 'lockrot-report', 'version': version if root_version is None else root_version}}})


def schema_test(*versions):
    rows = ''.join("        '%s' => [\n            'lockrot-report-2.schema.json' => 'ab',\n        ],\n" % v for v in versions)
    return 'final class SchemaEvolutionTest\n{\n    private const RELEASED_SCHEMAS = [\n' + rows + '    ];\n}\n'


RELEASED = {
    'changelog': changelog('[0.14.0] - 2026-11-20', '[0.13.0] - 2026-10-01'),
    'schemas': schema_test('0.13.0', '0.14.0'),
}


class AReleasePullRequest(unittest.TestCase):
    def check(self, base, head, changelog_text=RELEASED['changelog'], schemas=RELEASED['schemas'], changed=('src/Version.php', 'src/Analyzer/Analyzer.php'), lock=None):
        renderer = manifest(head)
        return version_bump.problems(version_php(base), version_php(head), changelog_text, schemas, renderer, package_lock(head) if lock is None else lock, list(changed))

    def test_a_correct_minor_release_passes(self):
        self.assertEqual([], self.check('0.13.0', '0.14.0'))

    def test_a_patch_and_a_major_release_pass(self):
        patch = changelog('[0.13.1] - 2026-11-02', links='[Unreleased]: https://github.com/somework/lockrot/compare/v0.13.1...HEAD\n[0.13.1]: https://github.com/somework/lockrot/compare/v0.13.0...v0.13.1\n')
        major = changelog('[1.0.0] - 2026-12-01', '[0.13.1] - 2026-11-02', links='[Unreleased]: https://github.com/somework/lockrot/compare/v1.0.0...HEAD\n[1.0.0]: https://github.com/somework/lockrot/compare/v0.13.1...v1.0.0\n')
        schemas = schema_test('0.13.1', '1.0.0')
        self.assertEqual([], self.check('0.13.0', '0.13.1', patch, schemas))
        self.assertEqual([], self.check('0.13.1', '1.0.0', major, schemas))

    def test_a_release_without_the_bump_fails(self):
        self.assertEqual(
            ['Version::STRING is 0.13.0 on both sides, but the pull request changes src/Analyzer/Analyzer.php, which the release ships: bump the version'],
            self.check('0.13.0', '0.13.0', changelog('[Unreleased]', '[0.13.0] - 2026-10-01'), schema_test('0.13.0'), ['src/Analyzer/Analyzer.php', 'CHANGELOG.md']),
        )

    def test_each_shipped_path_needs_the_bump(self):
        for path in ('src/Version.php', 'resources/lockrot-report-2.schema.json', 'bin/lockrot', 'composer.json'):
            self.assertEqual(1, len(self.check('0.13.0', '0.13.0', changed=[path])), path)

    def test_a_pull_request_that_ships_no_code_needs_no_bump(self):
        # CONTRIBUTING, "Cutting a release": a moved tag day goes to main as a pull request that
        # changes only that date and docs/example-run.md. Dependabot bumps workflow actions.
        self.assertEqual([], self.check('0.13.0', '0.13.0', changed=['CHANGELOG.md', 'docs/example-run.md']))
        self.assertEqual([], self.check('0.13.0', '0.13.0', changed=['.github/workflows/ci.yml']))
        self.assertEqual([], self.check('0.13.0', '0.13.0', changed=['composer.lock', 'build/phar/composer.lock', 'tests/Unit/VersionTest.php']))

    def test_a_skipped_or_backward_version_fails(self):
        self.assertEqual(['Version::STRING 0.15.0 is no bump of 0.13.0: take the next patch, minor or major'], self.check('0.13.0', '0.15.0', changelog('[0.15.0] - 2026-11-20', links='[Unreleased]: https://github.com/somework/lockrot/compare/v0.15.0...HEAD\n[0.15.0]: https://github.com/somework/lockrot/compare/v0.13.0...v0.15.0\n'), schema_test('0.15.0')))
        self.assertEqual(['Version::STRING 0.12.0 is no bump of 0.13.0: take the next patch, minor or major'], self.check('0.13.0', '0.12.0', changelog('[0.12.0] - 2026-11-20', links='[Unreleased]: https://github.com/somework/lockrot/compare/v0.12.0...HEAD\n[0.12.0]: https://github.com/somework/lockrot/compare/v0.13.0...v0.12.0\n'), schema_test('0.12.0')))
        self.assertEqual(['Version::STRING 0.13.2 is no bump of 0.13.0: take the next patch, minor or major'], self.check('0.13.0', '0.13.2', changelog('[0.13.2] - 2026-11-20', links='[Unreleased]: https://github.com/somework/lockrot/compare/v0.13.2...HEAD\n[0.13.2]: https://github.com/somework/lockrot/compare/v0.13.0...v0.13.2\n'), schema_test('0.13.2')))

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

    def test_a_bump_without_the_unreleased_compare_link_fails(self):
        stale = LINKS_0_14.replace('compare/v0.14.0...HEAD', 'compare/v0.13.0...HEAD')
        self.assertEqual(
            ['CHANGELOG.md has no "[Unreleased]: https://github.com/somework/lockrot/compare/v0.14.0...HEAD" link'],
            self.check('0.13.0', '0.14.0', changelog('[0.14.0] - 2026-11-20', links=stale)),
        )

    def test_a_bump_without_its_release_compare_link_fails(self):
        missing = LINKS_0_14.replace('[0.14.0]: https://github.com/somework/lockrot/compare/v0.13.0...v0.14.0\n', '')
        self.assertEqual(
            ['CHANGELOG.md has no "[0.14.0]: https://github.com/somework/lockrot/compare/v0.13.0...v0.14.0" link'],
            self.check('0.13.0', '0.14.0', changelog('[0.14.0] - 2026-11-20', links=missing)),
        )

    def test_a_renderer_lock_that_names_another_version_fails(self):
        self.assertEqual(
            ['package-lock.json of the vendored renderer has version 0.14.0-rc.9, but its manifest names 0.14.0: set both version fields with npm version 0.14.0 --no-git-tag-version'],
            self.check('0.13.0', '0.14.0', lock=package_lock('0.14.0-rc.9', '0.14.0')),
        )
        self.assertEqual(
            ['package-lock.json of the vendored renderer has packages[""].version 0.14.0-rc.9, but its manifest names 0.14.0: set both version fields with npm version 0.14.0 --no-git-tag-version'],
            self.check('0.13.0', '0.14.0', lock=package_lock('0.14.0', '0.14.0-rc.9')),
        )

    def test_an_unreadable_renderer_lock_fails(self):
        self.assertEqual(['package-lock.json of the vendored renderer is no JSON object'], self.check('0.13.0', '0.14.0', lock='<html>'))
        self.assertEqual(
            ['package-lock.json of the vendored renderer has packages[""].version nothing, but its manifest names 0.14.0: set both version fields with npm version 0.14.0 --no-git-tag-version'],
            self.check('0.13.0', '0.14.0', lock=json.dumps({'version': '0.14.0', 'packages': []})),
        )

    def test_a_pull_request_that_ships_no_code_reads_no_renderer_lock(self):
        self.assertEqual([], self.check('0.13.0', '0.13.0', changed=['docs/example-run.md'], lock='not read'))

    def test_an_unreadable_version_fails(self):
        self.assertEqual(
            ['src/Version.php of the head holds no X.Y.Z STRING'],
            version_bump.problems(version_php('0.13.0'), version_php('0.14.0-rc.9'), RELEASED['changelog'], RELEASED['schemas'], manifest('0.14.0'), package_lock('0.14.0'), ['src/Version.php']),
        )


class TheRepository(unittest.TestCase):
    def test_reads_the_head_files_that_the_guard_names(self):
        root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..')
        for path in version_bump.HEAD_FILES:
            self.assertTrue(os.path.isfile(os.path.join(root, path)), path)
        with open(os.path.join(root, 'tests/Integration/SchemaEvolutionTest.php')) as handle:
            self.assertIn('0.13.0', version_bump.released_schemas(handle.read()))

    def test_the_compare_links_match_the_ones_the_changelog_holds(self):
        root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..')
        with open(os.path.join(root, 'CHANGELOG.md')) as handle:
            text = handle.read()
        self.assertIn(version_bump.release_link('0.13.0', '0.12.0') + '\n', text)


if __name__ == '__main__':
    unittest.main()
