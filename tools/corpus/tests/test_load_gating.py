"""What the loaders refuse to treat as evidence about a run.

Every case here is one the tool used to audit and call clean. The run records what became of each
target and what it set out to cover; reading a file the run already disowned, or one something else
rewrote afterwards, is reading something other than that run's answer. And a corpus the run never
reached the end of is not a smaller corpus — it is an unfinished sentence.
"""

import os
import shutil
import tempfile
import unittest

import support  # noqa: F401 - puts the package on the path

from lockrot_corpus import load
from lockrot_corpus.jsonio import sha256_file, write_json_atomic, write_text_atomic

REPORT = {'lockrot': {'version': '0.0.0-test'}, 'findings': []}


class Reports(unittest.TestCase):
    def setUp(self):
        self.root = tempfile.mkdtemp(prefix='corpus-gate-')
        self.reports = os.path.join(self.root, 'reports')
        self.projects = os.path.join(self.root, 'projects')
        self.cache = os.path.join(self.root, 'cache')
        os.makedirs(os.path.join(self.cache, 'repo'))
        os.makedirs(self.reports)

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)

    def _project(self, name, body=REPORT):
        os.makedirs(os.path.join(self.projects, name), exist_ok=True)
        write_json_atomic(os.path.join(self.projects, name, 'composer.lock'), {'packages': []})
        path = os.path.join(self.reports, name + '.json')
        if isinstance(body, str):
            write_text_atomic(path, body)
        else:
            write_json_atomic(path, body)

        return path

    def _load(self, manifest=None):
        return load.load_claims(self.reports, self.projects, self.cache, support.REPO_ROOT, manifest)

    def _manifest(self, targets, intended=None, finished='2026-09-24T09:00:00+00:00'):
        return {'finished': finished, 'intended': intended or sorted(targets), 'targets': targets}

    def _ok(self, name):
        return {'status': 'ok', 'sha256': sha256_file(os.path.join(self.reports, name + '.json'))}

    def test_a_report_the_run_disowned_is_named_and_never_read(self):
        """`unreadable output` leaves its bytes on disk beside its stderr, on purpose.

        The run keeps them so a human can see what lockrot printed. Reading them back as a report
        aborted the whole audit on the one file the run had already refused to call a report.
        """
        self._project('good')
        self._project('bad', '{"findings": [ truncated')
        claims, missing = self._load(self._manifest({
            'good': self._ok('good'),
            'bad': {'status': 'unreadable output', 'sha256': None},
        }))
        self.assertEqual([], claims)
        self.assertEqual(['bad (unreadable output)'], missing)

    def test_one_report_that_will_not_parse_does_not_take_the_others_with_it(self):
        """Without a manifest there is no status to go on, so the parse itself has to be survivable."""
        self._project('good', {'findings': [{'package': 'x/y'}]})
        self._project('bad', 'not json at all')
        claims, missing = self._load()
        self.assertEqual(1, len(claims), 'the readable project was dropped along with the bad one')
        self.assertEqual(['bad (the report does not parse as one)'], missing)

    def test_a_report_replaced_after_the_run_wrote_it_is_not_evidence_about_that_run(self):
        self._project('good', {'findings': [{'package': 'x/y'}]})
        recorded = self._ok('good')
        write_json_atomic(os.path.join(self.reports, 'good.json'),
                          {'findings': [{'package': 'x/y'}, {'package': 'a/b'}]})
        claims, missing = self._load(self._manifest({'good': recorded}))
        self.assertEqual([], claims)
        self.assertEqual(['good (replaced since the run wrote it)'], missing)

    def test_a_project_the_run_never_reached_is_named_by_the_intended_list(self):
        """A target is written when the run reaches it, so an interruption records nothing at all."""
        self._project('reached')
        manifest = self._manifest({'reached': self._ok('reached')},
                                  intended=['reached', 'never-started'], finished=None)
        _, missing = self._load(manifest)
        self.assertEqual(['never-started (the run never reached it)'], missing)

    def test_an_intended_project_that_was_reached_is_not_reported_twice(self):
        self._project('reached')
        _, missing = self._load(self._manifest({'reached': self._ok('reached')},
                                               intended=['reached']))
        self.assertEqual([], missing)


class Pairs(unittest.TestCase):
    """The same rule on the explain half, where a document is only half of a target."""

    def setUp(self):
        self.explains = tempfile.mkdtemp(prefix='corpus-pairs-')

    def tearDown(self):
        shutil.rmtree(self.explains, ignore_errors=True)

    def _pair(self, slug, document, text='a page'):
        path = os.path.join(self.explains, slug + '.json')
        if isinstance(document, str):
            write_text_atomic(path, document)
        else:
            write_json_atomic(path, document)
        write_text_atomic(os.path.join(self.explains, slug + '.txt'), text)

    def test_one_document_that_will_not_parse_does_not_take_the_other_pairs_with_it(self):
        self._pair('good', {'finding': {'package': 'x/y'}})
        self._pair('bad', '{"finding": ')
        pairs, incomplete = load.load_pairs(self.explains)
        self.assertEqual(['good'], [pair.name for pair in pairs])
        self.assertEqual(['bad (the document does not parse as one)'], incomplete)


class MultiHostPackages(unittest.TestCase):
    """A package cached under two hosts, and the lock entry that says which one served it."""

    URL = 'https://packagist.org/downloads/'
    VERSIONS = [{'version': '1.0.0', 'version_normalized': '1.0.0.0',
                 'time': '2026-01-01T00:00:00+00:00', 'source': {'reference': 'c' * 40}}]

    def setUp(self):
        self.root = tempfile.mkdtemp(prefix='corpus-hosts-')
        for host in ('https---repo.packagist.org', 'https---packages.example.org'):
            directory = os.path.join(self.root, 'repo', host)
            os.makedirs(directory)
            write_json_atomic(os.path.join(directory, 'provider-x~y.json'),
                              {'packages': {'x/y': self.VERSIONS}})

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)

    def test_asking_without_a_url_first_does_not_settle_the_answer_for_the_url(self):
        """The parent scan asks about every package in the lock before any finding does.

        Memoised on the name alone, that first answer was `None` plus a permanent AMBIGUOUS mark,
        and the `notification-url` sitting in the lock entry could never be heard.
        """
        corpus = load.Corpus(self.root, support.REPO_ROOT)
        self.assertIsNone(corpus.metadata('x/y'))
        self.assertTrue(corpus.is_ambiguous('x/y'))
        self.assertIsNotNone(corpus.metadata('x/y', self.URL),
                             'the lock named the repository and the memo answered for another one')
        self.assertFalse(corpus.is_ambiguous('x/y', self.URL))

    def test_one_project_cannot_settle_a_package_for_the_next(self):
        corpus = load.Corpus(self.root, support.REPO_ROOT)
        corpus.metadata('x/y', 'https://packages.example.org/downloads')
        self.assertIsNotNone(corpus.metadata('x/y', self.URL))


if __name__ == '__main__':
    unittest.main()
