"""The prototype mode of tools/schema/ts/emit_dts.py, which tools/schema/node/dts_check.js runs on a worktree."""

import os
import subprocess
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.normpath(os.path.join(HERE, '..', '..', '..'))
EMIT = os.path.join(REPO, 'tools', 'schema', 'ts', 'emit_dts.py')
GENERATED = [os.path.join(REPO, 'types', 'lockrot-report-2.known.d.ts'), os.path.join(REPO, 'tools', 'schema', 'ts', 'known-map.json'),
             os.path.join(REPO, 'tools', 'schema', 'ts', 'probes.generated.ts')]


class ThePrototypeModeWritesOnlyThePrototype(unittest.TestCase):
    def test_the_generated_files_of_the_worktree_stay_untouched(self):
        before = {path: os.stat(path).st_mtime_ns for path in GENERATED}
        with tempfile.TemporaryDirectory() as work:
            prototype = os.path.join(work, 'prototype.d.ts')

            subprocess.run([sys.executable, EMIT, '--prototype', prototype], check=True, capture_output=True)

            self.assertIn('export type Report2 = ', open(prototype).read())
        self.assertEqual(before, {path: os.stat(path).st_mtime_ns for path in GENERATED})


if __name__ == '__main__':
    unittest.main()
