"""The ci.yml step that picks what a mutation shard mutates on a pull request, run in a scratch repository."""

import os
import shutil
import subprocess
import tempfile
import unittest

WORKFLOW = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..', '.github', 'workflows', 'ci.yml')
STEP = '      - name: What this shard mutates\n'


def scope_script():
    with open(WORKFLOW, encoding='utf-8') as handle:
        text = handle.read()
    lines = text[text.index(STEP):].splitlines()
    start = next(i for i, line in enumerate(lines) if line.strip() == 'run: |') + 1
    body = []
    for line in lines[start:]:
        if line.strip() and not line.startswith(' ' * 10):
            break
        body.append(line[10:])
    return '\n'.join(body) + '\n'


class TheShardScope(unittest.TestCase):
    def setUp(self):
        self.repo = tempfile.mkdtemp()
        self.addCleanup(shutil.rmtree, self.repo)
        self.git('init', '-q', '-b', 'main')
        self.write('src/Verdict/Mine.php', '<?php\n')
        self.write('src/Verdict/Theirs.php', '<?php\n')
        self.write('src/Root.php', '<?php\n')
        self.commit('base')
        self.git('update-ref', 'refs/remotes/origin/main', 'HEAD')

    def git(self, *args):
        subprocess.run(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', *args], cwd=self.repo, check=True, capture_output=True)

    def write(self, path, text):
        os.makedirs(os.path.dirname(os.path.join(self.repo, path)), exist_ok=True)
        with open(os.path.join(self.repo, path), 'w', encoding='utf-8') as handle:
            handle.write(text)

    def commit(self, message):
        self.git('add', '-A')
        self.git('commit', '-q', '-m', message)

    def scope(self, shard_filter):
        output = os.path.join(self.repo, '.output')
        env = dict(os.environ, EVENT='pull_request', BASE_REF='main', FILTER=shard_filter, GITHUB_OUTPUT=output,
                   GITHUB_STEP_SUMMARY=os.path.join(self.repo, '.summary'))
        subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', scope_script()], cwd=self.repo, env=env, check=True, capture_output=True)
        with open(output, encoding='utf-8') as handle:
            return dict(line.split('=', 1) for line in handle.read().splitlines())

    def test_a_changed_member_file_mutates_the_lines_of_its_directory(self):
        self.write('src/Verdict/Mine.php', '<?php\n$a = 1;\n')
        self.commit('change')

        self.assertEqual({'mode': 'diff', 'dirs': 'src/Verdict', 'diff': 'true', 'files': ''},
                         {key: value for key, value in self.scope('src/Verdict/Mine.php').items() if key != 'base'})

    def test_a_member_file_renamed_into_the_shard_is_mutated_whole(self):
        self.git('mv', 'src/Verdict/Mine.php', 'src/Verdict/Renamed.php')
        self.commit('rename')

        scope = self.scope('src/Verdict/Renamed.php')

        self.assertEqual(['diff', 'src/Verdict/Renamed.php'], [scope['mode'], scope['files']])

    def test_a_change_to_another_shards_file_mutates_nothing(self):
        self.write('src/Verdict/Theirs.php', '<?php\n$a = 1;\n')
        self.git('mv', 'src/Root.php', 'src/Verdict/Moved.php')
        self.commit('change')

        self.assertEqual('none', self.scope('src/Verdict/Mine.php')['mode'])

    def test_a_changed_root_file_is_mutated_whole(self):
        self.write('src/Root.php', '<?php\n$a = 1;\n')
        self.commit('change')

        scope = self.scope('src/Root.php src/Verdict/Mine.php')

        self.assertEqual(['diff', 'false', 'src/Root.php'], [scope['mode'], scope['diff'], scope['files']])


if __name__ == '__main__':
    unittest.main()
