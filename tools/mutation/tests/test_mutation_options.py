"""The mutation runs are a function of the commit, and each one generates the mutants that the gate reads."""

import os
import re
import unittest

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..')
PIN = ('composer require --dev --no-update "composer/composer:$(jq -r \'.packages[] | select(.name == "composer/composer") '
       '| .version\' build/phar/composer.lock)"')
SHARD = re.compile(r"^\s+- \{name: (.+?), filter: '([^']+)', min_msi: (\d+)\}$", re.M)


def read(*path):
    with open(os.path.join(ROOT, *path), encoding='utf-8') as handle:
        return handle.read()


def infection_calls(text):
    return [line for line in text.splitlines() if re.search(r'/infection\s', line)]


class MutationOptionsTest(unittest.TestCase):
    def test_every_infection_run_mutates_uncovered_code_without_an_msi_floor(self):
        calls = infection_calls(read('.github', 'actions', 'mutation-shard', 'action.yml')) + infection_calls(read('.github', 'workflows', 'ci.yml'))

        self.assertEqual(3, len(calls))
        for call in calls:
            with self.subTest(call=call):
                self.assertIn('--with-uncovered', call)
                self.assertIn('--min-msi=0', call)

    def test_the_full_run_keeps_the_shards_covered_msi(self):
        (call,) = infection_calls(read('.github', 'actions', 'mutation-shard', 'action.yml'))

        self.assertIn('--min-covered-msi="$MIN_MSI"', call)

    def test_both_mutation_actions_pin_the_composer_of_the_phar(self):
        for action in ('mutation-shard', 'mutation-coverage'):
            with self.subTest(action=action):
                text = read('.github', 'actions', action, 'action.yml')
                self.assertIn(PIN, text)
                self.assertNotRegex(text, r'(?m)^\s*(- )?run: composer install')

    def test_the_coverage_run_excludes_the_groups_that_every_mutant_run_excludes(self):
        (call,) = [line for line in read('.github', 'actions', 'mutation-coverage', 'action.yml').splitlines() if 'vendor/bin/phpunit' in line]
        options = re.search(r'"testFrameworkOptions": "([^"]+)"', read('infection.json5')).group(1)

        self.assertEqual(re.findall(r'--exclude-group=\S+', options), re.findall(r'--exclude-group=\S+', call))

    def test_a_shard_runs_when_the_renderer_manifest_is_the_only_failing_test(self):
        # Until the renderer is vendored again, RendererManifestTest fails on a new contract bundle.
        # Its group keeps it out of the coverage run, so that the shards still run on the pull request.
        test = read('tests', 'Unit', 'Output', 'RendererManifestTest.php')
        options = re.search(r'"testFrameworkOptions": "([^"]+)"', read('infection.json5')).group(1)

        self.assertIn("#[Group('renderer-manifest')]", test)
        self.assertIn('@group renderer-manifest', test)
        self.assertIn('--exclude-group=renderer-manifest', options)

    def test_infection_is_one_exact_release(self):
        self.assertRegex(read('.github', 'actions', 'mutation-shard', 'action.yml'), r'composer global require "infection/infection:\d+\.\d+\.\d+"')

    def test_the_infection_configuration_writes_the_json_log_that_the_gate_reads(self):
        self.assertIn('"json": "build/infection.json"', read('infection.json5'))

    def test_no_configuration_or_annotation_hides_code_from_the_gate(self):
        # Infection writes no mutant for an ignore key, a source exclude or the annotation, so the gate cannot see that code.
        config = read('infection.json5')
        self.assertNotRegex(config, r'"(ignore|ignoreSourceCodeByRegex|excludes)"\s*:')
        for directory, _, files in os.walk(os.path.join(ROOT, 'src')):
            for name in files:
                with self.subTest(path=name):
                    with open(os.path.join(directory, name), encoding='utf-8') as handle:
                        self.assertNotIn('@infection-ignore-all', handle.read())

    def test_every_src_file_is_in_exactly_one_shard(self):
        shards = SHARD.findall(read('.github', 'workflows', 'ci.yml'))
        self.assertNotEqual([], shards)
        for directory, _, files in os.walk(os.path.join(ROOT, 'src')):
            for name in files:
                if not name.endswith('.php'):
                    continue
                path = os.path.relpath(os.path.join(directory, name), ROOT).replace(os.sep, '/')
                owners = [shard for shard, filter_, _ in shards
                          if any(path == p or path.startswith(p + '/') for p in filter_.split())]
                with self.subTest(path=path):
                    self.assertEqual(1, len(owners), owners)

    def test_the_files_of_one_src_directory_share_one_min_msi(self):
        # A shard cut from another keeps its min_msi, so a split never lowers the floor of a file.
        shards = SHARD.findall(read('.github', 'workflows', 'ci.yml'))
        self.assertNotEqual([], shards)
        floors = {}
        for directory, _, files in os.walk(os.path.join(ROOT, 'src')):
            for name in files:
                if not name.endswith('.php'):
                    continue
                path = os.path.relpath(os.path.join(directory, name), ROOT).replace(os.sep, '/')
                for _, filter_, min_msi in shards:
                    if any(path == p or path.startswith(p + '/') for p in filter_.split()):
                        floors.setdefault(os.path.dirname(path), {})[path] = min_msi
        for directory, files in sorted(floors.items()):
            with self.subTest(directory=directory):
                self.assertEqual(1, len(set(files.values())), files)


if __name__ == '__main__':
    unittest.main()
