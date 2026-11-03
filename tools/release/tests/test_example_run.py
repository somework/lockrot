import json
import os
import re
import shutil
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))

import example_run  # noqa: E402

ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..'))

PAGE = '''---
title: Example run
---

# Example run

Recorded on 2026-10-26 with lockrot 0.14.0
on [a snapshot](https://github.com/somework/lockrot/blob/main/tests/fixtures/apps/app_one/composer.lock)
of [wallabag](https://github.com/wallabag/wallabag)'s public `composer.lock`.

```bash
COLUMNS=100 php lockrot.phar --target-php=8.3 --output=json:out.json
```

```text
old table
```

## The same lock as JSON

```json
{"old": true}
```

## A clean run

Recorded on [the skeleton](https://github.com/somework/lockrot/blob/main/tests/fixtures/skeletons/clean_one/composer.lock).

```text
old clean
```

## Related
'''


def score_model_list(count):
    return [{'id': index} for index in range(count)]


REPORT = {
    'lockrot': {'version': '0.14.1', 'schema': 2},
    'run': {'score_model': {
        'flag_order': ['abandoned', 'silent', 'pinned'],
        'flags': score_model_list(7),
        'parts': score_model_list(2),
        'bands': score_model_list(4),
    }},
    'exposure': score_model_list(5),
    'rot_depth': {'by_depth': score_model_list(5), 'deepest': score_model_list(4)},
    'findings': [{'package': 'a/a'}, {'package': example_run.FINDING}, {'package': 'b/b'}],
}


class TheAbridgedReport(unittest.TestCase):
    def test_keeps_one_finding_and_marks_the_cut(self):
        self.assertEqual([{'package': example_run.FINDING}, example_run.CUT], example_run.abridge(REPORT)['findings'])

    def test_keeps_the_head_of_each_cut_list(self):
        abridged = example_run.abridge(REPORT)

        self.assertEqual(score_model_list(3) + [example_run.CUT], abridged['exposure'])
        self.assertEqual(score_model_list(3) + [example_run.CUT], abridged['rot_depth']['by_depth'])
        self.assertEqual(score_model_list(2) + [example_run.CUT], abridged['run']['score_model']['flags'])
        self.assertEqual(score_model_list(2) + [example_run.CUT], abridged['run']['score_model']['bands'])

    def test_keeps_a_short_list_and_a_list_of_names_whole(self):
        abridged = example_run.abridge(REPORT)

        self.assertEqual(score_model_list(2), abridged['run']['score_model']['parts'])
        self.assertEqual(['abandoned', 'silent', 'pinned'], abridged['run']['score_model']['flag_order'])
        self.assertEqual(score_model_list(4), abridged['rot_depth']['deepest'])

    def test_leaves_the_report_unchanged(self):
        before = json.dumps(REPORT)

        example_run.abridge(REPORT)

        self.assertEqual(before, json.dumps(REPORT))

    def test_fails_without_the_finding_that_the_page_explains(self):
        with self.assertRaises(example_run.RecordError):
            example_run.abridge({**REPORT, 'findings': [{'package': 'a/a'}]})


class TheRenderedJson(unittest.TestCase):
    def test_prints_the_recorded_block_of_the_page_byte_for_byte(self):
        with open(os.path.join(ROOT, 'docs', 'example-run.md'), encoding='utf-8') as handle:
            block = re.search(r'```json\n(.*?)\n```', handle.read(), re.S).group(1)
        document = json.loads(re.sub(r'^(\s*)…$', r'\1"…"', block, flags=re.M))

        self.assertEqual(block, example_run.render(document))

    def test_prints_a_cut_as_a_bare_ellipsis(self):
        self.assertEqual('{\n    "a": [\n        1,\n        …\n    ]\n}', example_run.render({'a': [1, example_run.CUT]}))


class TheSplicedPage(unittest.TestCase):
    def test_names_the_two_snapshots(self):
        self.assertEqual(('tests/fixtures/apps/app_one', 'tests/fixtures/skeletons/clean_one'), example_run.snapshots(PAGE))

    def test_holds_the_new_recording_and_keeps_the_prose(self):
        page = example_run.splice(PAGE, '0.14.1', '2026-11-20', 'new table\nline two\n', '{\n    "new": true\n}', 'new clean\n')

        self.assertIn('Recorded on 2026-11-20 with lockrot 0.14.1\non [a snapshot]', page)
        self.assertIn('```text\nnew table\nline two\n```\n\n## The same', page)
        self.assertIn('```json\n{\n    "new": true\n}\n```', page)
        self.assertIn('```text\nnew clean\n```\n\n## Related', page)
        self.assertIn('```bash\nCOLUMNS=100 php lockrot.phar --target-php=8.3 --output=json:out.json\n```', page)
        for old in ('old table', 'old clean', '"old"', '2026-10-26', '0.14.0'):
            self.assertNotIn(old, page)

    def test_fails_on_a_page_without_a_block_it_records(self):
        with self.assertRaises(example_run.RecordError):
            example_run.splice(PAGE.replace('```json', '```text'), '0.14.1', '2026-11-20', 't', '{}', 'c')
        with self.assertRaises(example_run.RecordError):
            example_run.splice(PAGE.replace('Recorded on 2026', 'Taken on 2026'), '0.14.1', '2026-11-20', 't', '{}', 'c')


FAKE_PHAR = r'''<?php
if (in_array('--version', $argv, true)) { echo "lockrot 0.14.1\n"; exit(0); }
$log = getenv('FAKE_LOG');
file_put_contents($log, json_encode([getcwd(), array_slice($argv, 1), getenv('COLUMNS'), getenv('LOCKROT_TODAY'), getenv('COMPOSER_CACHE_DIR') !== false, is_file('composer.lock')])."\n", FILE_APPEND);
foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, '--output=json:') === 0) {
        file_put_contents(substr($argument, 14), json_encode(['lockrot' => ['version' => '0.14.1'], 'notes' => getenv('FAKE_NOTES') ? [['code' => 'anonymous_cap']] : [], 'run' => ['score_model' => ['flags' => [['id' => 1]]]], 'exposure' => [], 'rot_depth' => ['by_depth' => []], 'findings' => [['package' => 'spomky-labs/otphp']]]));
    }
}
echo basename(getcwd()) === 'clean_one' ? (getenv('FAKE_FLAGGED') ? "20 packages checked · 1 flagged\n" : "20 packages checked · nothing flagged\n") : "table output\n";
'''


class TheRecorder(unittest.TestCase):
    def setUp(self):
        self.root = tempfile.mkdtemp(prefix='lockrot-example-run-')
        for snapshot in ('tests/fixtures/apps/app_one', 'tests/fixtures/skeletons/clean_one'):
            os.makedirs(os.path.join(self.root, snapshot))
            for name in ('composer.json', 'composer.lock'):
                with open(os.path.join(self.root, snapshot, name), 'w', encoding='utf-8') as handle:
                    handle.write('{}')
        os.makedirs(os.path.join(self.root, 'docs'))
        self.write('docs/example-run.md', PAGE)
        self.write('fake.phar', FAKE_PHAR)
        self.log = os.path.join(self.root, 'calls.log')
        os.environ['FAKE_LOG'] = self.log

    def tearDown(self):
        del os.environ['FAKE_LOG']
        shutil.rmtree(self.root)

    def write(self, path, text):
        with open(os.path.join(self.root, path), 'w', encoding='utf-8') as handle:
            handle.write(text)

    def test_records_both_snapshots_into_the_page(self):
        example_run.record(os.path.join(self.root, 'fake.phar'), '2026-11-20', self.root)

        with open(os.path.join(self.root, 'docs', 'example-run.md'), encoding='utf-8') as handle:
            page = handle.read()
        self.assertIn('Recorded on 2026-11-20 with lockrot 0.14.1', page)
        self.assertIn('```text\ntable output\n```', page)
        self.assertIn('```text\n20 packages checked · nothing flagged\n```', page)
        self.assertIn('"package": "spomky-labs/otphp"', page)
        with open(self.log, encoding='utf-8') as handle:
            calls = [json.loads(line) for line in handle]
        self.assertEqual(['--target-php=8.3', '--output=json:out.json'], calls[0][1])
        self.assertEqual(['--target-php=8.3'], calls[1][1])
        for call in calls:
            self.assertEqual(['100', '2026-11-20', True, True], call[2:])
            self.assertFalse(os.path.realpath(call[0]).startswith(os.path.realpath(self.root)), 'the runs read copies of the snapshots')

    def test_refuses_a_run_with_a_run_note_or_a_flagged_clean_run(self):
        for variable in ('FAKE_NOTES', 'FAKE_FLAGGED'):
            with self.subTest(variable=variable):
                os.environ[variable] = '1'
                try:
                    with self.assertRaises(example_run.RecordError):
                        example_run.record(os.path.join(self.root, 'fake.phar'), '2026-11-20', self.root)
                finally:
                    del os.environ[variable]
                with open(os.path.join(self.root, 'docs', 'example-run.md'), encoding='utf-8') as handle:
                    self.assertEqual(PAGE, handle.read())

    def test_refuses_a_phar_that_does_not_run(self):
        self.write('fake.phar', '<?php exit(3);')

        with self.assertRaises(example_run.RecordError):
            example_run.record(os.path.join(self.root, 'fake.phar'), '2026-11-20', self.root)

    def test_refuses_a_day_that_is_no_date(self):
        with self.assertRaises(example_run.RecordError):
            example_run.record(os.path.join(self.root, 'fake.phar'), '2026-11-20T00:00:00+00:00', self.root)


class TheRepositoryPage(unittest.TestCase):
    def test_names_snapshots_that_this_repository_holds(self):
        with open(os.path.join(ROOT, 'docs', 'example-run.md'), encoding='utf-8') as handle:
            for snapshot in example_run.snapshots(handle.read()):
                self.assertTrue(os.path.isfile(os.path.join(ROOT, snapshot, 'composer.lock')), snapshot)


if __name__ == '__main__':
    unittest.main()
