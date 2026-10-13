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


class FullCheckMatrixTest(unittest.TestCase):
    def test_the_shards_are_those_of_ci(self):
        ci = SHARD.findall(mutation_job(read('ci.yml')))
        full_check = SHARD.findall(mutation_job(read('full-check.yml')))

        self.assertNotEqual([], ci)
        self.assertEqual(ci, full_check)

    def test_the_cap_is_that_of_ci(self):
        self.assertEqual(CAP.findall(mutation_job(read('ci.yml'))), CAP.findall(mutation_job(read('full-check.yml'))))

    def test_ci_ignores_the_branches_that_full_check_runs_on(self):
        self.assertIn("branches: [main, 'release/**', '!release/full-check-**']", read('ci.yml'))
        self.assertIn("branches: ['release/full-check-**']", read('full-check.yml'))

    def test_a_changed_shard_is_seen(self):
        changed = mutation_job(read('full-check.yml')).replace('min_msi: 98}', 'min_msi: 97}', 1)

        self.assertNotEqual(SHARD.findall(mutation_job(read('ci.yml'))), SHARD.findall(changed))


if __name__ == '__main__':
    unittest.main()
