"""The phrase census, asserted in both directions, and the rule that keeps the census complete.

A phrase that stops matching has gone dead. A phrase marked as unexercised that starts matching has
come back to life, and the marker on it is now a lie. Both are failures here, which is what makes
the dated `unexercised` marker cost something: it is not a way of silencing a phrase, it is a claim
about the corpus that gets checked.

The two currently dead paths — `migrate to` and `repository activity not checked (`, each matching
none of the 283 explain pairs of 2026-09-23 — are dated markers rather than code nobody knows is
dead.
"""

import os
import re
import unittest

import support

from lockrot_corpus import catalog


class TheCensusFailsBothWays(unittest.TestCase):
    def setUp(self):
        self.counts = catalog.census([pair.text for pair in support.fixture_pairs()])

    def test_every_phrase_with_a_minimum_still_finds_its_sentence(self):
        for ident, phrase in sorted(catalog.PHRASES.items()):
            if phrase.min_fixtures is None:
                continue
            with self.subTest(phrase=ident):
                self.assertGreaterEqual(
                    self.counts[ident], phrase.min_fixtures,
                    '%s matched %d recorded fixtures and declares a minimum of %d: it reads "%s"'
                    % (ident, self.counts[ident], phrase.min_fixtures, phrase.reads))

    def test_every_phrase_marked_unexercised_really_matches_nothing(self):
        for ident, phrase in sorted(catalog.PHRASES.items()):
            if phrase.unexercised is None:
                continue
            reason, date = phrase.unexercised
            with self.subTest(phrase=ident):
                self.assertEqual(
                    0, self.counts[ident],
                    '%s is marked unexercised on %s (%s) and now matches %d fixtures. Remove the '
                    'marker and give it a minimum.' % (ident, date, reason, self.counts[ident]))

    def test_a_phrase_declares_exactly_one_of_a_minimum_and_a_marker(self):
        for ident, phrase in sorted(catalog.PHRASES.items()):
            with self.subTest(phrase=ident):
                self.assertNotEqual(phrase.min_fixtures is None, phrase.unexercised is None)
                if phrase.min_fixtures is not None:
                    self.assertGreater(phrase.min_fixtures, 0,
                                       'a minimum of zero is the silence this design exists to '
                                       'prevent; the dated marker is the only legal way to say a '
                                       'phrase cannot be exercised')


class EveryPatternIsInTheCatalog(unittest.TestCase):
    """A census that covers most of the surface reads exactly like one that covers all of it."""

    ALLOWED = {'catalog.py', 'semver.py', 'p2.py', 'manifest.py'}

    def test_no_check_compiles_a_pattern_of_its_own(self):
        package = os.path.join(support.TOOL, 'lockrot_corpus')
        offenders = []
        for name in sorted(os.listdir(package)):
            if not name.endswith('.py') or name in self.ALLOWED:
                continue
            with open(os.path.join(package, name), encoding='utf-8') as handle:
                body = handle.read()
            for call in ('re.compile(', 're.search(', 're.match(', 're.findall('):
                if call in body:
                    offenders.append('%s uses %s' % (name, call))
        self.assertEqual([], offenders,
                         'a pattern outside catalog.py is a pattern outside the census. '
                         'semver.py, p2.py and manifest.py are allowed because they parse version '
                         'strings, cache file names and URLs, never lockrot\'s rendered output.')


if __name__ == '__main__':
    unittest.main()
