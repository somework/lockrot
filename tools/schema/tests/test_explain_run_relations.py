"""The explain-2 run block carries two keys of report-2's `run.php_window`, so it holds no run relation that reads another key of the window."""

import json
import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.normpath(os.path.join(HERE, '..', '..', '..'))
sys.path.insert(0, os.path.join(HERE, '..'))

import build_other_schemas  # noqa: E402

WINDOW_KEYS = ['minors', 'platform_override', 'target', 'target_fix']
KEPT = ('platform_override', 'target')


def relation(*keys):
    return {'if': {'properties': {'php_window': {'properties': {k: {'not': {'type': 'null'}} for k in keys}}}}}


class ExplainRunHoldsNoWindowRelation(unittest.TestCase):
    def test_a_relation_on_a_window_key_that_explain_drops_leaves(self):
        relations = [relation('target_fix'), relation('target'), relation('minors', 'target'), {'if': {'properties': {'gate': {}}}}]

        kept = build_other_schemas.explain_run_relations(relations, WINDOW_KEYS, KEPT)

        self.assertEqual([relation('target'), {'if': {'properties': {'gate': {}}}}], kept)

    def test_no_explain_run_relation_reads_a_dropped_window_key(self):
        with open(os.path.join(ROOT, 'resources', 'lockrot-explain-2.schema.json'), encoding='utf-8') as f:
            relations = json.load(f)['definitions']['explainRun'].get('allOf', [])

        self.assertEqual([], [r.get('description', '') for r in relations if '"target_fix"' in json.dumps(r)])


if __name__ == '__main__':
    unittest.main()
