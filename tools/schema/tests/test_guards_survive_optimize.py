"""The schema tools refuse a changed pinned input or a lossy shipping pass also under `python -O` and PYTHONOPTIMIZE."""

import ast
import os
import subprocess
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
TOOLS = os.path.normpath(os.path.join(HERE, '..'))


def tool_sources():
    for root, dirs, files in os.walk(TOOLS):
        dirs[:] = [d for d in dirs if d not in ('tests', 'node_modules', '__pycache__')]
        for name in files:
            if name.endswith('.py'):
                yield os.path.join(root, name)


class NoToolCheckIsAnAssertStatement(unittest.TestCase):
    def test_no_tool_source_holds_an_assert_statement(self):
        found = []
        for path in tool_sources():
            tree = ast.parse(open(path, encoding='utf-8').read(), path)
            found += ['%s:%d' % (os.path.relpath(path, TOOLS), node.lineno) for node in ast.walk(tree) if isinstance(node, ast.Assert)]

        self.assertEqual([], found)


class TheGuardRaisesUnderOptimize(unittest.TestCase):
    def run_guard(self, ok):
        code = 'import guard; guard.require(%s, "a pinned input changed", "x.json")' % ok
        return subprocess.run([sys.executable, '-O', '-c', code], cwd=TOOLS, capture_output=True, text=True,
                              env=dict(os.environ, PYTHONOPTIMIZE='2'))

    def test_a_failed_check_exits_non_zero_with_its_reason(self):
        run = self.run_guard(False)

        self.assertEqual((1, 'refused: a pinned input changed, x.json\n'), (run.returncode, run.stderr))

    def test_a_passed_check_exits_zero(self):
        self.assertEqual(0, self.run_guard(True).returncode)


if __name__ == '__main__':
    unittest.main()
