"""The mutation runs are a function of the commit, and each one generates the mutants that the gate reads."""

import os
import re
import unittest

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..')
PIN = ('composer require --dev --no-update "composer/composer:$(jq -r \'.packages[] | select(.name == "composer/composer") '
       '| .version\' build/phar/composer.lock)"')
SHARD = re.compile(r"^\s+- \{name: (.+?), filter: '([^']+)', min_msi: \d+\}$", re.M)


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

    def test_infection_is_one_exact_release(self):
        self.assertRegex(read('.github', 'actions', 'mutation-shard', 'action.yml'), r'composer global require "infection/infection:\d+\.\d+\.\d+"')

    def test_the_infection_configuration_writes_the_json_log_that_the_gate_reads(self):
        self.assertIn('"json": "build/infection.json"', read('infection.json5'))

    def test_every_src_file_is_in_exactly_one_shard(self):
        shards = SHARD.findall(read('.github', 'workflows', 'ci.yml'))
        self.assertNotEqual([], shards)
        for directory, _, files in os.walk(os.path.join(ROOT, 'src')):
            for name in files:
                if not name.endswith('.php'):
                    continue
                path = os.path.relpath(os.path.join(directory, name), ROOT).replace(os.sep, '/')
                owners = [shard for shard, filter_ in shards
                          if any(path == p or path.startswith(p + '/') for p in filter_.split())]
                with self.subTest(path=path):
                    self.assertEqual(1, len(owners), owners)


if __name__ == '__main__':
    unittest.main()
