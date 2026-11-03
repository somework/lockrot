"""The explain-2 run block carries two keys of report-2's `run.php_window`, so it holds no run relation that reads the window."""

import json
import os
import unittest

ROOT = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..'))


class ExplainRunHoldsNoWindowRelation(unittest.TestCase):
    def test_no_explain_run_relation_reads_php_window(self):
        with open(os.path.join(ROOT, 'resources', 'lockrot-explain-2.schema.json'), encoding='utf-8') as f:
            relations = json.load(f)['definitions']['explainRun'].get('allOf', [])

        self.assertEqual([], [r.get('description', '') for r in relations if 'php_window' in json.dumps(r)])


if __name__ == '__main__':
    unittest.main()
