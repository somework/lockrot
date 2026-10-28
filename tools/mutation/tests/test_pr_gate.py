"""The pull-request mutation gate: which escapes it lets through, and which it stops."""

import json
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
# The checkout a fixture log names. Gate.run_gate swaps it for the test's own checkout.
CHECKOUT = '/home/runner/work/lockrot/lockrot'


def row(line, mutator, original, checkout=CHECKOUT):
    return {'mutator': {'mutatorName': mutator, 'originalSourceCode': '', 'mutatedSourceCode': '',
                        'originalFilePath': checkout + '/src/Signal/Openness.php', 'originalStartLine': line},
            'diff': '@@ @@\n     {\n-' + original + '\n+        changed\n', 'processOutput': ''}


def log(*escaped, uncovered=(), ignored=(), skipped=0, stats=None, checkout=CHECKOUT):
    data = {'stats': {'escapedCount': len(escaped), 'notCoveredCount': len(uncovered), 'ignoredCount': len(ignored),
                      'skippedCount': skipped, 'timeOutCount': 0},
            'escaped': [row(*e, checkout) for e in escaped], 'uncovered': [row(*u, checkout) for u in uncovered],
            'ignored': [row(*i, checkout) for i in ignored], 'killed': [], 'timeouted': []}
    data['stats'].update(stats or {})
    return json.dumps(data)


class Gate(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = self.directory.name
        self.check_out(self.root)

    @staticmethod
    def check_out(root):
        os.makedirs(os.path.join(root, 'src', 'Signal'))
        with open(os.path.join(root, 'src', 'Signal', 'Openness.php'), 'w', encoding='utf-8') as handle:
            handle.write(SOURCE)

    def tearDown(self):
        self.directory.cleanup()

    def run_gate(self, logs, ledger, options=(), root=None):
        root = root or self.root
        paths = list(options)
        for n, text in enumerate(logs):
            paths.append(os.path.join(self.root, 'infection{}.json'.format(n)))
            with open(paths[-1], 'w', encoding='utf-8') as handle:
                handle.write(text.replace(CHECKOUT, root))
        paths.append(os.path.join(self.root, 'equivalents.md'))
        with open(paths[-1], 'w', encoding='utf-8') as handle:
            handle.write(ledger)
        return pr_gate.main(paths, root)


class ReadingTheLog(Gate):
    def test_each_mutant_keeps_its_section_and_its_original_line(self):
        text = log((7, 'Plus', '        return (int) $parts[0] + 0;'), uncovered=[(12, 'IncrementInteger', '        return 1;')])
        self.assertEqual([('escaped', 'src/Signal/Openness.php', 7, 'Plus', 'return (int) $parts[0] + 0;'),
                          ('uncovered', 'src/Signal/Openness.php', 12, 'IncrementInteger', 'return 1;')],
                         [tuple(m) for m in pr_gate.read_log(text, CHECKOUT).mutants])

    def test_a_log_without_the_uncovered_array_is_an_error(self):
        data = json.loads(log())
        del data['uncovered']
        self.assertEqual(2, self.run_gate([json.dumps(data)], '')[0])

    def test_an_array_whose_length_differs_from_its_stats_count_is_an_error(self):
        self.assertEqual(2, self.run_gate([log((7, 'Plus', 'x'), stats={'escapedCount': 2})], '')[0])
        self.assertEqual(2, self.run_gate([log(stats={'notCoveredCount': 1})], '')[0])

    def test_a_text_log_is_an_error_not_a_pass(self):
        self.assertEqual(2, self.run_gate(['Escaped mutants:\n================\n'], '')[0])

    def test_a_checkout_under_a_directory_named_src_keeps_its_own_paths(self):
        checkout = os.path.join(self.root, 'src', 'lockrot')
        self.check_out(checkout)

        code, summary = self.run_gate([log((7, 'Plus', PLUS))], '', ('--paths', 'src/Signal/Openness.php'), checkout)

        self.assertEqual(1, code, summary)
        self.assertIn('`src/Signal/Openness.php` Plus (line 7)', summary)

    def test_a_log_of_another_checkout_is_an_error_not_a_pass(self):
        escape = log((7, 'Plus', PLUS), checkout='/elsewhere/src/lockrot')

        for options in (('--paths', 'src/Signal'), ('--shard', 'src/Signal'), ('--report-only',)):
            with self.subTest(options=options):
                self.assertEqual(2, self.run_gate([escape], '', options)[0])


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
        code, summary = self.run_gate([log()], '')
        self.assertEqual(0, code)
        self.assertIn('No mutant escaped', summary)

    def test_the_summary_counts_the_documented_escapes_against_the_full_run(self):
        escapes = log((7, 'Plus', 'return (int) $parts[0] + 0;'), (7, 'CastInt', 'return (int) $parts[0] + 0;'))
        ledger = '- `src/Signal/Openness.php` Plus `return (int) $parts[0] + 0;` -- why.\n'
        _, partly = self.run_gate([escapes], ledger)
        _, none = self.run_gate([escapes], '')
        self.assertIn('1 mutant(s) have an entry.', partly)
        self.assertNotIn('have an entry.', none)

    def test_a_missing_log_is_an_error_not_a_pass(self):
        code, _ = pr_gate.main([os.path.join(self.root, 'absent.json'), os.path.join(self.root, 'absent.md')], self.root)
        self.assertEqual(2, code)


PLUS = 'return (int) $parts[0] + 0;'
DOCUMENTED_PLUS = '- `src/Signal/Openness.php` Plus `' + PLUS + '` -- why.\n'


class Uncovered(Gate):
    def test_an_uncovered_mutant_without_an_entry_fails(self):
        code, summary = self.run_gate([log(uncovered=[(7, 'Plus', PLUS)])], '')
        self.assertEqual(1, code)
        self.assertIn('| uncovered |', summary)

    def test_an_escape_entry_does_not_excuse_an_uncovered_mutant_on_its_line(self):
        self.assertEqual(1, self.run_gate([log(uncovered=[(7, 'Plus', PLUS)])], DOCUMENTED_PLUS)[0])

    def test_a_not_covered_entry_does_not_excuse_an_escape_on_its_line(self):
        ledger = '- `src/Signal/Openness.php` NotCovered `' + PLUS + '` -- a matrix row runs it.\n'
        self.assertEqual(1, self.run_gate([log((7, 'Plus', PLUS))], ledger)[0])
        self.assertEqual(0, self.run_gate([log(uncovered=[(7, 'Plus', PLUS)])], ledger)[0])

    def test_a_not_covered_entry_covers_any_mutator_of_its_line_up_to_its_count(self):
        ledger = '- `src/Signal/Openness.php` NotCovered x2 `' + PLUS + '` -- why.\n'
        self.assertEqual(0, self.run_gate([log(uncovered=[(7, 'Plus', PLUS), (7, 'CastInt', PLUS)])], ledger)[0])
        self.assertEqual(1, self.run_gate([log(uncovered=[(7, 'Plus', PLUS), (7, 'CastInt', PLUS), (7, 'Minus', PLUS)])], ledger)[0])

    def test_not_covered_method_accounts_for_its_count_of_the_method(self):
        two = [(6, 'UnwrapExplode', "$parts = explode('.', $v);"), (7, 'Plus', PLUS)]
        ledger = '- `src/Signal/Openness.php` NotCoveredMethod x2 `major()` -- e2e only.\n'
        self.assertEqual(0, self.run_gate([log(uncovered=two)], ledger)[0])
        self.assertEqual(1, self.run_gate([log(uncovered=two + [(7, 'CastInt', PLUS)])], ledger)[0])

    def test_not_covered_method_needs_its_count(self):
        ledger = '- `src/Signal/Openness.php` NotCoveredMethod `major()` -- e2e only.\n'
        self.assertEqual(1, self.run_gate([log(uncovered=[(7, 'Plus', PLUS)])], ledger)[0])

    def test_a_not_covered_entry_of_a_declaration_line_does_not_widen_to_its_method(self):
        ledger = '- `src/Signal/Openness.php` NotCovered `public function major(string $v): int` -- why.\n'
        self.assertEqual(1, self.run_gate([log(uncovered=[(7, 'Plus', PLUS)])], ledger)[0])

    def test_an_ignored_mutant_fails_even_with_an_entry(self):
        code, summary = self.run_gate([log(ignored=[(7, 'Plus', PLUS)])], DOCUMENTED_PLUS)
        self.assertEqual(1, code)
        self.assertIn('ignored', summary)

    def test_a_skipped_mutant_fails(self):
        self.assertEqual(1, self.run_gate([log(skipped=1)], '')[0])


class ShardMode(Gate):
    SHARD = ('--shard', 'src/Signal src/Version.php')

    def test_a_not_covered_claim_must_match_its_whole_count(self):
        ledger = '- `src/Signal/Openness.php` NotCovered x2 `' + PLUS + '` -- why.\n'
        code, summary = self.run_gate([log(uncovered=[(7, 'Plus', PLUS)])], ledger, self.SHARD)
        self.assertEqual(1, code)
        self.assertIn('claims 2 uncovered mutant(s), and 1 match it', summary)
        self.assertEqual(0, self.run_gate([log(uncovered=[(7, 'Plus', PLUS)])], ledger)[0])

    def test_a_claim_outside_the_shard_is_not_read(self):
        ledger = '- `src/Signal/Openness.php` NotCovered x2 `' + PLUS + '` -- why.\n'
        self.assertEqual(0, self.run_gate([log()], ledger, ('--shard', 'src/Verdict'))[0])

    def test_an_unused_escape_claim_is_a_warning_in_shard_mode_only(self):
        code, summary = self.run_gate([log()], DOCUMENTED_PLUS, self.SHARD)
        self.assertEqual(0, code)
        self.assertIn('Warning: no escape used the entry', summary)
        self.assertNotIn('Warning', self.run_gate([log()], DOCUMENTED_PLUS)[1])

    def test_report_only_lists_and_passes(self):
        code, summary = self.run_gate([log((7, 'CastInt', PLUS))], '', self.SHARD + ('--report-only',))
        self.assertEqual(0, code)
        self.assertIn('**no**', summary)

    def test_report_only_still_stops_on_an_unreadable_log(self):
        self.assertEqual(2, self.run_gate(['{}'], '', ('--report-only',))[0])

    def test_paths_keep_only_the_mutants_of_their_files(self):
        self.assertEqual(0, self.run_gate([log((7, 'Plus', PLUS))], '', ('--paths', 'src/Signal/Other.php'))[0])
        self.assertEqual(1, self.run_gate([log((7, 'Plus', PLUS))], '', ('--paths', 'src/Signal/Openness.php'))[0])

    def test_paths_leave_the_whole_count_check_to_shard_mode(self):
        ledger = '- `src/Signal/Openness.php` NotCovered x2 `' + PLUS + '` -- why.\n'
        self.assertEqual(0, self.run_gate([log(uncovered=[(7, 'Plus', PLUS)])], ledger, ('--paths', 'src/Signal'))[0])

    def test_an_unknown_option_is_an_error(self):
        self.assertEqual(2, self.run_gate([log()], '', ('--full',))[0])


class StaleEntries(Gate):
    def test_an_entry_whose_line_left_the_source_is_stale(self):
        ledger = ('- `src/Signal/Openness.php` Plus `return (int) $parts[0] + 0;` — equivalent.\n'
                  '- `src/Signal/Openness.php` Plus `return (int) $parts[0] + 1;` — the line was rewritten.\n'
                  '- `src/Signal/Gone.php` Plus `return 1;` — the file was removed.\n')

        found = pr_gate.stale(ledger, pr_gate.Source(self.root))

        self.assertEqual(['- `src/Signal/Openness.php` Plus `return (int) $parts[0] + 1;` — the line was rewritten.',
                          '- `src/Signal/Gone.php` Plus `return 1;` — the file was removed.'], found)

    def test_a_repeated_line_is_stale_without_the_method_its_reason_names(self):
        ledger = ('- `src/Signal/Openness.php` TrueValue `return 1;` — in minor(): equivalent.\n'
                  '- `src/Signal/Openness.php` TrueValue `return 1;` — equivalent, but which line?\n')

        self.assertEqual(['- `src/Signal/Openness.php` TrueValue `return 1;` — equivalent, but which line?'], pr_gate.stale(ledger, pr_gate.Source(self.root)))

    def test_a_not_covered_method_entry_is_stale_when_its_method_left_the_file(self):
        ledger = ('- `src/Signal/Openness.php` NotCoveredMethod x1 `minor()` — e2e only.\n'
                  '- `src/Signal/Openness.php` NotCoveredMethod x1 `gone()` — e2e only.\n')

        self.assertEqual(['- `src/Signal/Openness.php` NotCoveredMethod x1 `gone()` — e2e only.'], pr_gate.stale(ledger, pr_gate.Source(self.root)))


class TheLedger(unittest.TestCase):
    def test_every_entry_names_a_line_of_src(self):
        root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..')
        with open(os.path.join(root, 'tests', 'infection-equivalents.md'), encoding='utf-8') as handle:
            ledger = handle.read()

        self.assertNotEqual([], pr_gate.entries(ledger))
        self.assertEqual([], pr_gate.stale(ledger, pr_gate.Source(root)), 'rewrite or remove each entry whose line left src')


if __name__ == '__main__':
    unittest.main()
