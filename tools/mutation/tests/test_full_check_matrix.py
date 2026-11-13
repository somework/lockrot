"""full-check.yml repeats the shard matrix and the cap of ci.yml's mutation job, and owns the branches that ci.yml ignores."""

import os
import re
import unittest

WORKFLOWS = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..', '.github', 'workflows')
SHARD = re.compile(r'^\s+- \{name: .+, filter: .+, min_msi: \d+\}$', re.M)
CAP = re.compile(r'^    timeout-minutes: (\d+)$', re.M)


def read(name):
    with open(os.path.join(WORKFLOWS, name), encoding='utf-8') as handle:
        return handle.read()


def mutation_job(workflow):
    """The text of the job `mutation`, up to the next job."""
    start = workflow.index('\n  mutation:\n')
    following = re.search(r'^  [a-z0-9-]+:$', workflow[start + 2:], re.M)
    return workflow[start:start + 2 + following.start()] if following else workflow[start:]


def job_names(workflow):
    """The check name of each job: its `name:` line, else its id."""
    names = []
    for block in re.split(r'^  ([a-z0-9-]+):$', workflow.split('\njobs:\n', 1)[1], flags=re.M)[1:]:
        names.append(block)
    return [
        (re.search(r'^    name: (.+)$', body, re.M) or [None, job_id])[1]
        for job_id, body in zip(names[::2], names[1::2])
    ]


def checkout_steps(workflow):
    """The `with:` line that follows each checkout step, or an empty string."""
    return re.findall(r'uses: actions/checkout@[^\n]*\n( +with: .+\n)?', workflow)


FLOW_ENTRY = re.compile(r'\s*([\w-]+):\s*("(?:[^"\\]|\\.)*"|\'[^\']*\'|[^,"\'{}]*?)\s*(?:,|$)')


def with_map(step):
    """The entries of the step's inline `with:` map, a quoted value kept whole, or None when the map does not parse."""
    found = re.fullmatch(r' +with: \{(.*)\}\n', step)
    if found is None:
        return None
    inner, entries, at = found[1], {}, 0
    while at < len(inner):
        entry = FLOW_ENTRY.match(inner, at)
        if entry is None or entry.end() == at:
            return None
        entries[entry[1]] = entry[2]
        at = entry.end()
    return entries


def drops_the_token(step):
    """True when the step's inline `with:` map sets `persist-credentials` to the boolean false."""
    return (with_map(step) or {}).get('persist-credentials') == 'false'


class FullCheckMatrixTest(unittest.TestCase):
    def test_the_shards_are_those_of_ci(self):
        ci = SHARD.findall(mutation_job(read('ci.yml')))
        full_check = SHARD.findall(mutation_job(read('full-check.yml')))

        self.assertNotEqual([], ci)
        self.assertEqual(ci, full_check)

    def test_the_cap_is_that_of_ci(self):
        self.assertEqual(CAP.findall(mutation_job(read('ci.yml'))), CAP.findall(mutation_job(read('full-check.yml'))))

    def test_ci_ignores_the_branches_that_full_check_runs_on(self):
        self.assertIn("branches: [main, 'release/**', 'integration/**', '!release/full-check-**']", read('ci.yml'))
        self.assertIn("branches: ['release/full-check-**']", read('full-check.yml'))

    def test_an_rc_tag_runs_the_full_check(self):
        self.assertIn("tags: ['v*-rc.*']", read('full-check.yml'))

    def test_the_release_rehearsal_gates_the_changes_since_main_on_the_shard_results(self):
        job = mutation_job(read('full-check.yml'))
        (call,) = [line for line in job.splitlines() if '--changed' in line]

        self.assertIn('base=$(git merge-base origin/main HEAD)', job)
        self.assertIn('fetch-depth: 0', job)
        self.assertIn('python3 tools/mutation/pr_gate.py --paths "$FILTER" --changed "$base" build/infection.json tests/infection-equivalents.md', call)
        self.assertNotIn('--report-only', call)
        self.assertIn('exit $status', job[job.index(call):])

    def test_a_full_check_job_never_reuses_a_required_ci_check_name(self):
        ci = job_names(read('ci.yml'))
        full_check = job_names(read('full-check.yml'))

        self.assertNotEqual([], full_check)
        for name in full_check:
            with self.subTest(name=name):
                self.assertTrue(name.startswith('full-check '))
        self.assertEqual([], [name for name in ci if name.startswith('full-check')])

    def test_no_checkout_keeps_the_job_token(self):
        workflows = sorted(name for name in os.listdir(WORKFLOWS) if name.endswith(('.yml', '.yaml')))
        steps = [(workflow, step) for workflow in workflows for step in checkout_steps(read(workflow))]
        self.assertNotEqual([], steps)
        for workflow, step in steps:
            with self.subTest(workflow=workflow, step=step):
                self.assertTrue(drops_the_token(step))

    def test_a_checkout_without_a_version_comment_or_with_the_token_kept_is_seen(self):
        workflow = (
            '      - uses: actions/checkout@abc\n'
            '      - uses: actions/checkout@abc # v7\n'
            '        with: {persist-credentials: falsey}\n'
            '      - uses: actions/checkout@abc # v7\n'
            '        with: {fetch-depth: 0, persist-credentials: false}\n'
            '      - uses: actions/checkout@abc # v7\n'
            '        with: {path: "example, persist-credentials: false, checkout"}\n'
        )

        self.assertEqual([False, False, True, False], [drops_the_token(step) for step in checkout_steps(workflow)])

    def test_the_floor_job_runs_the_plugin_on_composer_2_9_and_asserts_the_refusal(self):
        ci = read('ci.yml')
        floor = ci[ci.index('\n  e2e-composer-floor:\n'):ci.index('\n  phar:\n')]
        with open(os.path.join(WORKFLOWS, '..', '..', 'src', 'Composer', 'LockrotPlugin.php'), encoding='utf-8') as handle:
            plugin = handle.read()

        self.assertIn("REFUSED_MINOR: '2.9'", floor)
        self.assertIn('is older than 2.10, so lockrot does nothing. Upgrade Composer, or run the PHAR: https://lockrot.dev/phar/', floor)
        self.assertIn("private const COMPOSER_FLOOR = '2.10';", plugin)
        self.assertIn("' is older than '.self::COMPOSER_FLOOR.', so lockrot does nothing. Upgrade Composer, or run the PHAR: https://lockrot.dev/phar/'", plugin)

    def test_a_changed_shard_is_seen(self):
        changed = mutation_job(read('full-check.yml')).replace('min_msi: 98}', 'min_msi: 97}', 1)

        self.assertNotEqual(SHARD.findall(mutation_job(read('ci.yml'))), SHARD.findall(changed))


if __name__ == '__main__':
    unittest.main()
