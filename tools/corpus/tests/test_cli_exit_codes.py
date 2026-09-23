"""The four answers this tool is allowed to give, and which trouble maps to which.

The header of `corpus` promises 0 clean, 1 found something, 2 cannot tell, 3 you asked wrong. Two
paths used to break that promise in the direction that matters most: a bug in this tool, and a
mistyped flag, both came back as a code that reads as a verdict about lockrot's output.
"""

import importlib.machinery
import importlib.util
import os
import unittest

import support  # noqa: F401 - puts the package on the path

from lockrot_corpus import checks, report

CLI = os.path.join(support.TOOL, 'corpus')


def _cli():
    """The entry point imported under a name of its own; it has no .py extension to import by."""
    loader = importlib.machinery.SourceFileLoader('corpus_cli', CLI)
    spec = importlib.util.spec_from_file_location('corpus_cli', CLI, loader=loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)

    return module


class ExitCodes(unittest.TestCase):
    def setUp(self):
        self.cli = _cli()

    def test_a_bug_in_this_tool_is_not_reported_as_a_problem_with_the_output(self):
        """FrameworkError means the checker is broken. Uncaught it exits 1 — the verdict code."""
        def broken(options):
            raise checks.FrameworkError('C9.assert_ returned None for a selected pair')

        self.cli._selftest = broken
        self.assertEqual(report.CANNOT_TELL, self.cli.main(['selftest']))

    def test_a_missing_required_flag_is_a_usage_error_and_not_a_cannot_tell(self):
        with self.assertRaises(SystemExit) as raised:
            self.cli.main(['run', '--out', 'x', '--today', '2026-09-24'])
        self.assertEqual(report.USAGE, raised.exception.code)

    def test_an_unknown_subcommand_is_a_usage_error(self):
        with self.assertRaises(SystemExit) as raised:
            self.cli.main(['no-such-command'])
        self.assertEqual(report.USAGE, raised.exception.code)

    def test_no_subcommand_at_all_is_the_same_usage_error(self):
        self.assertEqual(report.USAGE, self.cli.main([]))


if __name__ == '__main__':
    unittest.main()
