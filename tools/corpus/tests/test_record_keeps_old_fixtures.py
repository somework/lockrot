"""`tools/corpus/record` refuses a run that lacks a wanted shape, and then leaves the recorded fixtures as they were."""

import importlib.machinery
import json
import importlib.util
import os
import tempfile
import types
import unittest

import support

RECORD = os.path.join(support.TOOL, 'record')


def _record(fixtures, claims, pairs):
    loader = importlib.machinery.SourceFileLoader('corpus_record', RECORD)
    spec = importlib.util.spec_from_file_location('corpus_record', RECORD, loader=loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    module.FIXTURES = fixtures
    module.load = types.SimpleNamespace(load_claims=lambda *args: (claims, []), load_pairs=lambda *args: (pairs, []))
    module.runner = types.SimpleNamespace(load_manifest=lambda path: {})

    return module


class AShapeThatTheRunLacks(unittest.TestCase):
    def test_exits_1_and_keeps_every_recorded_fixture(self):
        with tempfile.TemporaryDirectory() as work:
            fixtures = os.path.join(work, 'fixtures')
            kept = {os.path.join(fixtures, 'claims', 'reports-1', 'old.json'): '{"findings": []}',
                    os.path.join(fixtures, 'explain', 'old.txt'): 'old text'}
            for path, contents in kept.items():
                os.makedirs(os.path.dirname(path), exist_ok=True)
                with open(path, 'w') as handle:
                    handle.write(contents)
            record = _record(fixtures, claims=[types.SimpleNamespace(finding={'package': 'a/b'})],
                             pairs=[types.SimpleNamespace(name='a-b')])

            code = record.main(['--work', work, '--out', 'run', '--explain-out', 'run'])

            self.assertEqual(1, code)
            self.assertEqual(kept, {path: _read(path) for path in kept if os.path.exists(path)})


class ACachedP2DocumentThatTheRecordingCannotCopy(unittest.TestCase):
    def test_is_named_when_it_is_missing_or_lacks_the_package(self):
        with tempfile.TemporaryDirectory() as work:
            host = os.path.join(work, 'repo', 'https---repo.packagist.org')
            os.makedirs(host)
            for name, document in {'a~good': {'packages': {'a/good': []}}, 'a~other': {'packages': {'a/else': []}}}.items():
                with open(os.path.join(host, 'provider-%s.json' % name), 'w') as handle:
                    json.dump(document, handle)
            chosen = {'one': types.SimpleNamespace(finding={'package': 'a/good'}, parent='a/missing'),
                      'two': types.SimpleNamespace(finding={'package': 'a/other'}, parent=None)}

            missing = _record(work, [], []).uncopyable_providers(chosen, work)

            self.assertEqual(['no cached p2 for a/missing', 'no cached p2 for a/other'], missing)


def _read(path):
    with open(path) as handle:
        return handle.read()


if __name__ == '__main__':
    unittest.main()
