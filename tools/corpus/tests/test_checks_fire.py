"""What stops a check from going quietly dead.

The recorded fixtures, unmutated, must produce no problem. Each declared poison mutates the JSON of
one recorded document, and the targeted check must produce that exact problem key, once.

The exact key matters: asserting only that something was reported passes when a reworded sentence
turns every document into `unparsable`. The suite does not watch lockrot. The limit is in
tools/corpus/README.md, "The rule that makes the rest worth running".
"""

import unittest

import support

from lockrot_corpus import checks as framework
from lockrot_corpus.claims import Claim, ClaimChecks
from lockrot_corpus.explain import Pair
from lockrot_corpus.explain import checks as explain_checks


def _run(documents, checks):
    return framework.run(checks, documents, 'the recorded fixtures', fixtures=True)


class CleanFixtures(unittest.TestCase):
    def test_the_recorded_fixtures_hold_no_problem_and_every_check_selects_something(self):
        census = _run(support.fixture_claims() + support.fixture_pairs(),
                      ClaimChecks().checks() + explain_checks())
        self.assertEqual([], census.failures,
                         'a check was below its fixture floor or declined more than it declared')
        for ident, entry in sorted(census.checks.items()):
            self.assertEqual({}, entry.problems, '%s reported a problem on clean fixtures' % ident)
            self.assertEqual({}, entry.unparsable, '%s could not read a clean fixture' % ident)


class Poisons(unittest.TestCase):
    def test_every_check_has_a_poison(self):
        declared = {poison['check'] for poison in support.poisons()}
        for check in ClaimChecks().checks() + explain_checks():
            self.assertIn(check.ident, declared,
                          '%s has no poison, so nothing proves it can still fire' % check.ident)

    def test_every_problem_key_a_check_can_emit_has_a_poison_or_a_dated_exemption(self):
        """The keys come from the source, not from a list kept beside it.

        A list that people keep by hand drifts from its branches, and someone can delete an
        unpoisoned branch with the suite still green.
        """
        poisoned = {poison['expect'] for poison in support.poisons()}
        exempt = {row['key'] for row in support.unreachable_keys()['keys']}
        for key, module in sorted(support.emitted_keys().items()):
            with self.subTest(key=key):
                self.assertTrue(
                    key in poisoned or key in exempt,
                    '%s.py can report %r and nothing proves it still can. Add a poison, or — if no '
                    'mutation of a document can reach it because it is the check noticing the page '
                    'changed — add it to unreachable_by_mutation with a reason and a date.'
                    % (module, key))

    def test_no_key_is_both_poisoned_and_declared_unreachable(self):
        poisoned = {poison['expect'] for poison in support.poisons()}
        exempt = {row['key'] for row in support.unreachable_keys()['keys']}
        self.assertEqual(set(), poisoned & exempt,
                         'a key with a working poison is not unreachable; remove the exemption')

    def test_every_declared_key_is_one_a_check_can_actually_emit(self):
        """A key renamed in the code leaves a poison for a branch that does not exist.

        That poison passes forever and proves nothing.
        """
        emitted = set(support.emitted_keys())
        for key in {poison['expect'] for poison in support.poisons()}:
            self.assertIn(key, emitted, 'no check emits %r any more' % key)
        for row in support.unreachable_keys()['keys']:
            self.assertIn(row['key'], emitted, 'no check emits %r any more' % row['key'])

    def test_each_poison_produces_exactly_the_key_it_declares(self):
        for poison in support.poisons():
            with self.subTest(check=poison['check'], fixture=poison['from']):
                census = _run(*self._poisoned(poison))
                entry = census.checks[poison['check']]
                found = entry.problems if poison['outcome'] == 'problem' else entry.unparsable
                self.assertIn(poison['expect'], found,
                              '%s did not report %r; it reported problems=%s unparsable=%s'
                              % (poison['check'], poison['expect'],
                                 sorted(entry.problems), sorted(entry.unparsable)))
                self.assertEqual(1, len(found[poison['expect']]))

    def _poisoned(self, poison):
        if poison['kind'] == 'claim':
            claims = []
            for claim in support.fixture_claims():
                if claim.finding.get('package') == poison['from']:
                    claim = Claim(claim.project,
                                  support.apply_mutation(claim.finding, poison['mutate']),
                                  claim.entry, claim.metadata, claim.parent_date,
                                  parent=claim.parent, ambiguous=claim.ambiguous)
                claims.append(claim)

            return claims, ClaimChecks().checks()

        pairs = []
        for pair in support.fixture_pairs():
            if pair.name == poison['from']:
                pair = Pair(pair.name, pair.text,
                            support.apply_mutation(pair.document, poison['mutate']))
            pairs.append(pair)

        return pairs, explain_checks()


if __name__ == '__main__':
    unittest.main()
