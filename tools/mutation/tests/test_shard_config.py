"""A shard's Infection configuration for a pull request: the shard's directories as the configured source."""

import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))

import shard_config  # noqa: E402

CONFIG = '{\n    "source": {\n        "directories": ["src"]\n    },\n    // a comment\n    "timeout": 600\n}\n'


class TheShardConfig(unittest.TestCase):
    def test_the_source_becomes_the_shard_s_directories_and_nothing_else_moves(self):
        text = shard_config.config(CONFIG, ['src/Verdict', 'src/Data/Repository/'])
        self.assertIn('"directories": ["src/Verdict", "src/Data/Repository"]', text)
        self.assertIn('// a comment', text)
        self.assertIn('"timeout": 600', text)
        self.assertNotIn('["src"]', text)

    def test_a_file_cannot_be_a_source_directory(self):
        with self.assertRaises(ValueError):
            shard_config.config(CONFIG, ['src/Verdict', 'src/Version.php'])

    def test_src_itself_and_a_path_leaving_it_are_refused(self):
        for path in ('src', 'src/', 'src/../lib', 'src/Verdict/../../lib'):
            with self.subTest(path=path), self.assertRaises(ValueError):
                shard_config.config(CONFIG, [path])

    def test_a_path_outside_src_is_refused(self):
        with self.assertRaises(ValueError):
            shard_config.config(CONFIG, ['lib/Thing'])

    def test_a_shard_without_directories_is_refused(self):
        with self.assertRaises(ValueError):
            shard_config.config(CONFIG, [])

    def test_a_configuration_whose_source_moved_is_refused(self):
        with self.assertRaises(ValueError):
            shard_config.config('{"source": {"directories": ["lib"]}}', ['src/Verdict'])


if __name__ == '__main__':
    unittest.main()
