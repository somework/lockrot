"""The pull-request mutation gate: which escapes it lets through, and which it stops."""

import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))

import pr_gate  # noqa: E402

SOURCE = """<?php
final class Openness
{
    public function major(string $v): int
    {
        $parts = explode('.', $v);
        return (int) $parts[0] + 0;
    }

    public function minor(string $v): int
    {
        return 1;
    }

    public function patch(string $v): int
    {
        return 1;
    }
}
"""


def log(*escaped):
    out = ['Escaped mutants:', '================', '']
    for n, (line, mutator, original) in enumerate(escaped, 1):
        out += ['{}) /home/runner/work/lockrot/lockrot/src/Signal/Openness.php:{}    [M] {} [ID] {}'.format(n, line, mutator, 'a' * 32),
                '', '@@ @@', '     {', '-' + original, '+        changed', '']
    return '\n'.join(out + ['Timed Out mutants:', '==================', ''])


class Gate(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = self.directory.name
        os.makedirs(os.path.join(self.root, 'src', 'Signal'))
        with open(os.path.join(self.root, 'src', 'Signal', 'Openness.php'), 'w', encoding='utf-8') as handle:
            handle.write(SOURCE)

    def tearDown(self):
        self.directory.cleanup()

    def run_gate(self, logs, ledger):
        paths = []
        for n, text in enumerate(logs):
            paths.append(os.path.join(self.root, 'infection{}.log'.format(n)))
            with open(paths[-1], 'w', encoding='utf-8') as handle:
                handle.write(text)
        paths.append(os.path.join(self.root, 'equivalents.md'))
        with open(paths[-1], 'w', encoding='utf-8') as handle:
            handle.write(ledger)
        return pr_gate.main(paths, self.root)


class ReadingTheLog(unittest.TestCase):
    def test_only_escapes_are_read_each_with_its_original_line(self):
        text = log((7, 'Plus', '        return (int) $parts[0] + 0;'))
        text += '\n1) /x/src/Signal/Openness.php:12    [M] Plus [ID] ' + 'b' * 32 + '\n@@ @@\n-        return 1;\n'
        self.assertEqual([('src/Signal/Openness.php', 7, 'Plus', 'return (int) $parts[0] + 0;')],
                         [tuple(e) for e in pr_gate.escapes(text)])


class TheKey(Gate):
    def test_an_entry_matches_on_the_original_text_wherever_the_line_moved(self):
        escape = log((40, 'Plus', 'return (int) $parts[0] + 0;'))
        ledger = '- `src/Signal/Openness.php` Plus `return (int) $parts[0] + 0;` -- why.\n'
        self.assertEqual(0, self.run_gate([escape], ledger)[0])

    def test_another_mutator_on_the_documented_line_is_not_documented(self):
        escape = log((7, 'CastInt', '        return (int) $parts[0] + 0;'))
        code, summary = self.run_gate([escape], '- `src/Signal/Openness.php` Plus `return (int) $parts[0] + 0;` -- why.\n')
        self.assertEqual(1, code)
        self.assertIn('CastInt', summary)

    def test_several_mutators_share_one_code_span(self):
        escapes = log((7, 'Plus', 'return (int) $parts[0] + 0;'), (7, 'CastInt', 'return (int) $parts[0] + 0;'))
        ledger = '- `src/Signal/Openness.php` Plus, CastInt `return (int) $parts[0] + 0;` -- why.\n'
        self.assertEqual(0, self.run_gate([escapes], ledger)[0])

    def test_a_text_that_is_not_unique_needs_the_method_its_reason_names(self):
        documented = log((12, 'IncrementInteger', '        return 1;'))
        other_method = log((17, 'IncrementInteger', '        return 1;'))
        ledger = '- `src/Signal/Openness.php` IncrementInteger `return 1;` -- in minor(): unlike patch().\n'
        self.assertEqual(0, self.run_gate([documented], ledger)[0])
        self.assertEqual(1, self.run_gate([other_method], ledger)[0])
        unnamed = '- `src/Signal/Openness.php` IncrementInteger `return 1;` -- why, unlike minor().\n'
        self.assertEqual(1, self.run_gate([documented], unnamed)[0])

    def test_a_repeated_line_is_not_covered_by_an_entry_for_another_repeated_line_of_its_method(self):
        with open(os.path.join(self.root, 'src', 'Signal', 'Openness.php'), 'a', encoding='utf-8') as handle:
            handle.write('function both(): void\n{\n    $a = 1;\n    $b = 2;\n    $a = 1;\n    $b = 2;\n}\n')
        escape = log((23, 'IncrementInteger', '    $b = 2;'))
        self.assertEqual(1, self.run_gate([escape], '- `src/Signal/Openness.php` IncrementInteger `$a = 1;` -- in both(): why.\n')[0])
        self.assertEqual(0, self.run_gate([escape], '- `src/Signal/Openness.php` IncrementInteger `$b = 2;` -- in both(): why.\n')[0])

    def test_a_line_that_holds_a_backtick_goes_in_a_double_backtick_span(self):
        with open(os.path.join(self.root, 'src', 'Signal', 'Openness.php'), 'a', encoding='utf-8') as handle:
            handle.write("$fence = str_repeat('`', 3);\n")
        fence = log((21, 'IncrementInteger', "$fence = str_repeat('`', 3);"))
        ledger = "- `src/Signal/Openness.php` IncrementInteger ``$fence = str_repeat('`', 3);`` -- why.\n"
        self.assertEqual(0, self.run_gate([fence], ledger)[0])

    def test_a_unique_line_is_not_covered_by_an_entry_for_another_line_of_its_method(self):
        escape = log((6, 'Plus', "        $parts = explode('.', $v);"))
        ledger = '- `src/Signal/Openness.php` Plus `return (int) $parts[0] + 0;` -- in major().\n'
        self.assertEqual(1, self.run_gate([escape], ledger)[0])

    def test_x2_accounts_for_two_and_not_three(self):
        twice = log((7, 'Plus', 'return (int) $parts[0] + 0;'), (7, 'Plus', 'return (int) $parts[0] + 0;'))
        thrice = log(*[(7, 'Plus', 'return (int) $parts[0] + 0;')] * 3)
        ledger = '- `src/Signal/Openness.php` Plus x2 `return (int) $parts[0] + 0;` -- why.\n'
        self.assertEqual(0, self.run_gate([twice], ledger)[0])
        self.assertEqual(1, self.run_gate([thrice], ledger)[0])

    def test_a_line_number_is_not_a_key(self):
        ledger = '- `src/Signal/Openness.php:7` Plus -- why.\n'
        self.assertEqual(1, self.run_gate([log((7, 'Plus', 'return (int) $parts[0] + 0;'))], ledger)[0])

    def test_the_escapes_of_every_log_count_together(self):
        one = log((7, 'Plus', 'return (int) $parts[0] + 0;'))
        ledger = '- `src/Signal/Openness.php` Plus `return (int) $parts[0] + 0;` -- why.\n'
        self.assertEqual(1, self.run_gate([one, one], ledger)[0])


class TheVerdict(Gate):
    def test_no_escape_passes(self):
        code, summary = self.run_gate(['Escaped mutants:\n================\n\nTimed Out mutants:\n'], '')
        self.assertEqual(0, code)
        self.assertIn('No mutant escaped', summary)

    def test_the_summary_counts_the_documented_escapes_against_the_full_run(self):
        escapes = log((7, 'Plus', 'return (int) $parts[0] + 0;'), (7, 'CastInt', 'return (int) $parts[0] + 0;'))
        ledger = '- `src/Signal/Openness.php` Plus `return (int) $parts[0] + 0;` -- why.\n'
        _, partly = self.run_gate([escapes], ledger)
        _, none = self.run_gate([escapes], '')
        self.assertIn('1 escaped mutant(s) have an entry.', partly)
        self.assertNotIn('have an entry.', none)

    def test_a_missing_log_is_an_error_not_a_pass(self):
        code, _ = pr_gate.main([os.path.join(self.root, 'absent.log'), os.path.join(self.root, 'absent.md')], self.root)
        self.assertEqual(2, code)


if __name__ == '__main__':
    unittest.main()
