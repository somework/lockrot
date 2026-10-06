"""The pull-request mutation gate: which escapes it lets through, and which it stops.

A pull request mutates only the lines it changes. Its gate is not a score: on a few dozen mutants one
documented equivalent moves the MSI by whole points. It reads the escaped mutants instead, and stops
the run on an escape tests/infection-equivalents.md does not account for, while one it does account
for passes and is still listed.
"""

import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))

import pr_gate  # noqa: E402

LOG = """Escaped mutants:
================

1) /home/runner/work/lockrot/lockrot/src/Signal/ConstraintOpenness.php:50    [M] IncrementInteger [ID] 6ac96be7bce021e69c64b1d1b380cac3

@@ @@
-        $x = 0;
+        $x = 1;

2) /home/runner/work/lockrot/lockrot/src/Lock/LockedPackage.php:91    [M] Identical [ID] 1111aaaa2222bbbb3333cccc4444dddd

@@ @@
-        if ($a === $b) {
+        if ($a !== $b) {

Timed Out mutants:
==================

1) /home/runner/work/lockrot/lockrot/src/Data/Repository/RepositoryUrl.php:264    [M] DecrementInteger [ID] 429b75690e4a2a08bc8df415452881f5

Skipped mutants:
================
"""

EQUIVALENTS = """# Mutants no test can observe

## src/Verdict, src/Signal (the original gate)

- `src/Signal/ConstraintOpenness.php:48` CastInt, `:50` CastInt, IncrementInteger, DecrementInteger,
  `:79` ConcatOperandRemoval — numeric strings compare numerically.
- `src/Lock/ConfiguredRepositories.php:285` CastString and
- `src/Lock/ConfiguredRepositories.php:286` CastString — both patterns are literals.
- `src/Filesystem/Path.php:138` LogicalOr and DecrementInteger x2 — the inode test.

src/Composer/SelfUpdateCommand.php:155 FalseValue (`\\Phar::running(false)`) — a paragraph that opens
with its reference is an entry too.

Prose mentioning src/Lock/LockedPackage.php and Identical outside an entry documents nothing.

### Not equivalent: the two mutants this run reports as timed out

- `src/Data/Repository/RepositoryMetadataLoader.php:195` NotIdentical — a genuine infinite loop.

## Later

- `src/Data/Repository/RepositoryUrl.php:254` LessThan — two offsets of one pattern never coincide.
"""


class ReadingTheLog(unittest.TestCase):
    def test_only_the_escaped_section_is_read(self):
        self.assertEqual(
            [('src/Signal/ConstraintOpenness.php', 50, 'IncrementInteger'),
             ('src/Lock/LockedPackage.php', 91, 'Identical')],
            pr_gate.escapes(LOG),
        )

    def test_a_log_without_escapes_has_none(self):
        self.assertEqual([], pr_gate.escapes('Escaped mutants:\n================\n\nTimed Out mutants:\n'))


class ReadingTheDocumentedList(unittest.TestCase):
    def setUp(self):
        self.entries = pr_gate.entries(EQUIVALENTS)

    def test_a_mutator_named_after_a_continued_line_reference_is_documented(self):
        self.assertTrue(pr_gate.documented(('src/Signal/ConstraintOpenness.php', 50, 'IncrementInteger'), self.entries))

    def test_the_line_number_does_not_decide_because_lines_drift(self):
        self.assertTrue(pr_gate.documented(('src/Signal/ConstraintOpenness.php', 61, 'CastInt'), self.entries))

    def test_a_mutator_the_entry_does_not_name_is_not_documented(self):
        self.assertFalse(pr_gate.documented(('src/Signal/ConstraintOpenness.php', 50, 'Plus'), self.entries))

    def test_a_mutator_documented_for_another_file_is_not_documented_here(self):
        self.assertFalse(pr_gate.documented(('src/Lock/LockedPackage.php', 10, 'CastString'), self.entries))

    def test_prose_outside_a_list_entry_documents_nothing(self):
        self.assertFalse(pr_gate.documented(('src/Lock/LockedPackage.php', 91, 'Identical'), self.entries))

    def test_a_paragraph_that_opens_with_its_reference_is_an_entry(self):
        self.assertTrue(pr_gate.documented(('src/Composer/SelfUpdateCommand.php', 155, 'FalseValue'), self.entries))

    def test_a_mutant_listed_as_not_equivalent_is_not_documented(self):
        self.assertFalse(pr_gate.documented(('src/Data/Repository/RepositoryMetadataLoader.php', 195, 'NotIdentical'), self.entries))

    def test_entries_after_the_not_equivalent_section_count_again(self):
        self.assertTrue(pr_gate.documented(('src/Data/Repository/RepositoryUrl.php', 254, 'LessThan'), self.entries))

    def test_each_of_two_adjacent_entries_stands_on_its_own(self):
        self.assertTrue(pr_gate.documented(('src/Lock/ConfiguredRepositories.php', 286, 'CastString'), self.entries))


class CountingTheDocumentedOnes(unittest.TestCase):
    """Lines drift, so the gate cannot match on them; it matches on how many there are instead."""

    def setUp(self):
        self.entries = pr_gate.entries(EQUIVALENTS)

    def test_one_documented_mutant_covers_one_escape_not_two(self):
        found = [('src/Signal/ConstraintOpenness.php', 50, 'IncrementInteger'),
                 ('src/Signal/ConstraintOpenness.php', 90, 'IncrementInteger')]
        self.assertEqual([True, False], pr_gate.accounted(found, self.entries))

    def test_a_mutator_named_twice_covers_two(self):
        found = [('src/Signal/ConstraintOpenness.php', 48, 'CastInt'), ('src/Signal/ConstraintOpenness.php', 50, 'CastInt')]
        self.assertEqual([True, True], pr_gate.accounted(found, self.entries))

    def test_x2_counts_twice(self):
        found = [('src/Filesystem/Path.php', 138, 'DecrementInteger'), ('src/Filesystem/Path.php', 140, 'DecrementInteger'),
                 ('src/Filesystem/Path.php', 141, 'DecrementInteger')]
        self.assertEqual([True, True, False], pr_gate.accounted(found, self.entries))


class TheVerdict(unittest.TestCase):
    def run_gate(self, log):
        with tempfile.TemporaryDirectory() as directory:
            log_path = os.path.join(directory, 'infection.log')
            list_path = os.path.join(directory, 'equivalents.md')
            with open(log_path, 'w', encoding='utf-8') as handle:
                handle.write(log)
            with open(list_path, 'w', encoding='utf-8') as handle:
                handle.write(EQUIVALENTS)
            return pr_gate.main([log_path, list_path])

    def test_an_undocumented_escape_fails_and_is_named(self):
        code, summary = self.run_gate(LOG)
        self.assertEqual(1, code)
        self.assertIn('src/Lock/LockedPackage.php:91', summary)
        self.assertIn('Identical', summary)

    def test_a_documented_escape_passes_and_is_still_listed(self):
        documented_only = LOG.split('2) ')[0] + 'Timed Out mutants:\n'
        code, summary = self.run_gate(documented_only)
        self.assertEqual(0, code)
        self.assertIn('src/Signal/ConstraintOpenness.php:50', summary)

    def test_no_escape_passes(self):
        code, summary = self.run_gate('Escaped mutants:\n================\n\nTimed Out mutants:\n')
        self.assertEqual(0, code)
        self.assertIn('No mutant escaped', summary)

    def test_the_escapes_of_every_log_are_counted_together(self):
        with tempfile.TemporaryDirectory() as directory:
            paths = []
            for name in ('diff.log', 'files.log'):
                paths.append(os.path.join(directory, name))
                with open(paths[-1], 'w', encoding='utf-8') as handle:
                    handle.write(LOG.split('2) ')[0] + 'Timed Out mutants:\n')
            list_path = os.path.join(directory, 'equivalents.md')
            with open(list_path, 'w', encoding='utf-8') as handle:
                handle.write(EQUIVALENTS)
            code, summary = pr_gate.main(paths + [list_path])
        self.assertEqual(1, code, 'IncrementInteger is documented once in that file, and escaped twice')

    def test_a_missing_log_is_an_error_not_a_pass(self):
        with tempfile.TemporaryDirectory() as directory:
            code, summary = pr_gate.main([os.path.join(directory, 'absent.log'), os.path.join(directory, 'absent.md')])
        self.assertEqual(2, code)


if __name__ == '__main__':
    unittest.main()
