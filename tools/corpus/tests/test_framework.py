"""The framework's own invariants, on synthetic documents that mention lockrot nowhere.

Every one of these is a negative case, and that is deliberate. The framework is a machine for making
absence loud, so a suite that only proves the happy path is the joke this whole tool is about.
"""

import unittest

import support  # noqa: F401 - puts the package on the path

from lockrot_corpus.checks import (Check, Decline, FrameworkError, decline, ok, problem,
                                   run, select, unparsable)
from lockrot_corpus.report import CANNOT_TELL, OK, PROBLEMS, exit_code, render_text


class Doc:
    kind = 'claim'

    def __init__(self, name='a document'):
        self.name = name


def _check(**overrides):
    settings = {
        'ident': 'X1', 'title': 'a check', 'selects': lambda doc: select(doc),
        'assert_': lambda doc: ok(), 'min_corpus': 0, 'min_fixtures': 0,
    }
    settings.update(overrides)

    return Check(**settings)


class AVerdictIsCompulsory(unittest.TestCase):
    def test_an_assertion_that_returns_none_is_a_framework_error(self):
        with self.assertRaises(FrameworkError) as raised:
            run([_check(assert_=lambda doc: None)], [Doc()], 'a test')
        self.assertIn('X1', str(raised.exception))

    def test_a_selector_that_returns_something_else_is_a_framework_error(self):
        with self.assertRaises(FrameworkError):
            run([_check(selects=lambda doc: True)], [Doc()], 'a test')


class DeclinesAreDeclaredInAdvance(unittest.TestCase):
    def test_an_undeclared_decline_raises_rather_than_being_counted(self):
        with self.assertRaises(FrameworkError) as raised:
            run([_check(selects=lambda doc: decline('a reason nobody wrote down'))], [Doc()], 'a test')
        self.assertIn('undeclared', str(raised.exception))

    def test_a_declared_decline_over_its_share_fails_the_run(self):
        reason = Decline('a declared reason', 'because', max_share=0.5, measured_on='a date')
        census = run([_check(selects=lambda doc: decline('a declared reason'), declines=[reason])],
                     [Doc(), Doc()], 'a test')
        self.assertTrue(any('over the' in failure for failure in census.failures), census.failures)
        self.assertEqual(CANNOT_TELL, exit_code(census))

    def test_a_declared_decline_within_its_share_is_ordinary(self):
        reason = Decline('a declared reason', 'because', max_share=0.6, measured_on='a date')
        declined = Doc('the declined one')
        census = run([_check(selects=lambda doc: (decline('a declared reason')
                                                  if doc is declined else select(doc)),
                             declines=[reason])],
                     [declined, Doc('the judged one')], 'a test')
        self.assertEqual([], census.failures)


class LivenessFloors(unittest.TestCase):
    def test_a_check_that_declines_everything_it_is_offered_has_not_answered(self):
        """A floor of zero is not permission to judge nothing.

        This is the shape `--partial` used to hide: an entire scope loads nothing, every check
        reports `selected 0 of 0`, and the run exits 0 under a line saying every check ran.
        """
        reason = Decline('a declared reason', 'because', max_share=1.0, measured_on='a date')
        census = run([_check(selects=lambda doc: decline('a declared reason'), declines=[reason])],
                     [Doc(), Doc()], 'a test')
        self.assertIn('judged nothing at all', ' '.join(census.failures))
        self.assertEqual(CANNOT_TELL, exit_code(census))
        self.assertNotIn('below its floor', ' '.join(census.failures),
                         'the wording must survive the --partial filter, which strips that phrase')

    def test_a_check_selecting_below_its_floor_cannot_report_clean(self):
        census = run([_check(min_corpus=5)], [Doc()], 'a test')
        self.assertTrue(any('below its floor' in failure for failure in census.failures))
        self.assertEqual(CANNOT_TELL, exit_code(census))

    def test_a_run_over_nothing_is_not_a_clean_bill_of_health(self):
        census = run([_check()], [], 'an empty tree')
        self.assertEqual(CANNOT_TELL, exit_code(census))
        self.assertIn('nothing to read', ' '.join(census.failures))


class NothingIsSwallowed(unittest.TestCase):
    def test_one_raising_document_does_not_lose_the_rest_of_the_run(self):
        def assert_(document):
            if document.name == 'the bad one':
                raise KeyError('a field lockrot renamed')

            return ok()

        census = run([_check(assert_=assert_)], [Doc('the bad one'), Doc('a good one')], 'a test')
        self.assertEqual(1, census.checks['X1'].ok, 'the good document was still judged')
        self.assertIn('KeyError', ' '.join(census.failures))
        self.assertEqual(CANNOT_TELL, exit_code(census))

    def test_a_check_is_only_offered_documents_of_its_own_kind(self):
        class Other:
            kind = 'pair'
            name = 'another kind'

        census = run([_check()], [Other()], 'a test')
        self.assertEqual(0, census.checks['X1'].offered)


class TheOutputCarriesItsPopulation(unittest.TestCase):
    def test_a_clean_run_prints_what_it_looked_at_and_never_the_bare_word_none(self):
        census = run([_check()], [Doc()], 'a test')
        text = render_text(census)
        self.assertEqual(OK, exit_code(census))
        self.assertIn('selected', text)
        self.assertIn('1 documents', text)
        self.assertNotIn('\nnone\n', text)

    def test_an_unparsable_sentence_is_a_problem_and_not_a_smaller_population(self):
        census = run([_check(assert_=lambda doc: unparsable('the sentence is gone', doc.name))],
                     [Doc()], 'a test')
        self.assertEqual(1, census.checks['X1'].selected)
        self.assertEqual(PROBLEMS, exit_code(census))

    def test_a_problem_is_a_non_zero_exit(self):
        census = run([_check(assert_=lambda doc: problem('a key', 'a row'))], [Doc()], 'a test')
        self.assertEqual(PROBLEMS, exit_code(census))


if __name__ == '__main__':
    unittest.main()


class TheLibyearsPopulation(unittest.TestCase):
    """The aggregate that exists because no single row reports it — and had no test of its own."""

    def _errors(self, errors):
        from lockrot_corpus.claims import _A3

        aggregate = _A3()
        # Each error paired with a value far enough from zero that rounding could have gone either
        # way, unless the test says otherwise.
        aggregate.errors = [pair if isinstance(pair, tuple) else (pair, 1.0) for pair in errors]

        return aggregate.aggregate_problems()

    def test_rounding_noise_alone_is_not_a_finding(self):
        import random

        generator = random.Random(20260923)
        noise = [generator.uniform(-0.005, 0.005) for _ in range(4000)]
        self.assertEqual([], self._errors(noise))

    def test_a_bias_smaller_than_the_per_finding_tolerance_is_still_caught(self):
        import random

        generator = random.Random(20260923)
        biased = [generator.uniform(-0.005, 0.005) + 0.001 for _ in range(4000)]
        keys = [key for key, _ in self._errors(biased)]
        self.assertIn('libyears drifts systematically across the whole population', keys)

    def test_a_bias_in_a_subset_that_a_mean_would_dilute_is_caught_by_the_signs(self):
        import random

        generator = random.Random(20260923)
        errors = [generator.uniform(0.0, 0.004) for _ in range(3000)]
        errors += [generator.uniform(-0.005, -0.004) for _ in range(1000)]
        keys = [key for key, _ in self._errors(errors)]
        self.assertIn('libyears errs in one direction far more often than rounding would', keys)

    def test_a_population_too_small_to_say_anything_says_nothing(self):
        self.assertEqual([], self._errors([0.004] * 20))

    def test_values_that_round_down_to_zero_are_not_counted_as_a_direction(self):
        """libyears cannot be negative, so every value under half a printed digit rounds to 0.00
        and its error is negative by construction. On the 2026-09-23 corpus that is 2,156 of 3,958
        findings — enough to make a correct run look like a five-sigma bias."""
        floored = [(-value, value) for value in [0.004, 0.003, 0.002, 0.001] * 200]
        keys = [key for key, _ in self._errors(floored)]
        self.assertNotIn('libyears errs in one direction far more often than rounding would', keys)
