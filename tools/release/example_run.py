#!/usr/bin/env python3
"""Re-record docs/example-run.md with a lockrot PHAR.

    GITHUB_TOKEN=<token> python3 tools/release/example_run.py <lockrot.phar> <YYYY-MM-DD>

Runs the PHAR with LOCKROT_TODAY=<day> on a copy of each snapshot that the page names: the lock
of the recorded run and the lock of the clean run. Each run gets an empty Composer home and cache,
so no answer comes from a cache. Writes the table, the abridged JSON report and the clean run into
their blocks, and the day and version into "Recorded on <day> with lockrot <version>"
(CONTRIBUTING, "Cutting a release"). Standard library only, as tools/corpus.
"""

import copy
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from typing import Any, List, Tuple

PAGE = os.path.join('docs', 'example-run.md')
# The page's JSON section explains this finding.
FINDING = 'spomky-labs/otphp'
CUT = '…'
HEAD = {'exposure': 3, 'by_depth': 3}
SCORE_MODEL_HEAD = 2
RECORDED = re.compile(r'(Recorded\s+on\s+)(\S+)(\s+with\s+lockrot\s+)(\S+)')
SNAPSHOT = re.compile(r'`(tests/fixtures/[^`]+)/composer\.lock`')
DAY = re.compile(r'^\d{4}-\d{2}-\d{2}$')
CLEAN = '\n## A clean run\n'


class RecordError(Exception):
    pass


def cut(items: List[Any], keep: int) -> List[Any]:
    return items if len(items) <= keep else items[:keep] + [CUT]


def abridge(report: Any) -> Any:
    abridged = copy.deepcopy(report)
    findings = [finding for finding in abridged['findings'] if finding.get('package') == FINDING]
    if not findings:
        raise RecordError('the report has no finding for %s, which the page explains' % FINDING)
    abridged['findings'] = findings + [CUT]
    abridged['exposure'] = cut(abridged['exposure'], HEAD['exposure'])
    abridged['rot_depth']['by_depth'] = cut(abridged['rot_depth']['by_depth'], HEAD['by_depth'])
    model = abridged['run']['score_model']
    for key, value in model.items():
        if isinstance(value, list) and value and isinstance(value[0], dict):
            model[key] = cut(value, SCORE_MODEL_HEAD)
    return abridged


def render(document: Any) -> str:
    text = json.dumps(document, indent=4, ensure_ascii=False)
    return re.sub(r'^(\s*)"%s"$' % CUT, r'\1' + CUT, text, flags=re.M)


def snapshots(page: str) -> Tuple[str, str]:
    head, _, clean = page.partition(CLEAN)
    found = [SNAPSHOT.search(part) for part in (head.split('\n## ', 1)[0], clean)]
    if None in found:
        raise RecordError('%s names no snapshot `tests/fixtures/<dir>/composer.lock` in its opening or its clean run' % PAGE)
    return found[0].group(1), found[1].group(1)


def replace_block(text: str, fence: str, body: str) -> str:
    block = re.compile(r'(```%s\n).*?(\n```)' % fence, re.S)
    if block.search(text) is None:
        raise RecordError('%s has no ```%s block where the recording goes' % (PAGE, fence))
    return block.sub(lambda match: match.group(1) + body.rstrip('\n') + match.group(2), text, count=1)


def splice(page: str, version: str, day: str, table: str, report: str, clean: str) -> str:
    head, separator, tail = page.partition(CLEAN)
    if not separator:
        raise RecordError('%s has no "## A clean run" section' % PAGE)
    if RECORDED.search(head) is None:
        raise RecordError('%s has no "Recorded on <day> with lockrot <version>" sentence' % PAGE)
    head = RECORDED.sub(lambda match: match.group(1) + day + match.group(3) + version, head, count=1)
    command, _, rest = head.partition('```bash\n')
    if not _:
        raise RecordError('%s has no ```bash block with the command' % PAGE)
    rest = replace_block(replace_block(rest, 'text', table), 'json', report)
    return command + '```bash\n' + rest + separator + replace_block(tail, 'text', clean)


def run(phar: str, day: str, snapshot: str, root: str, work: str, arguments: List[str]) -> str:
    project = os.path.join(work, os.path.basename(snapshot))
    os.makedirs(project)
    for name in ('composer.json', 'composer.lock'):
        shutil.copyfile(os.path.join(root, snapshot, name), os.path.join(project, name))
    composer = tempfile.mkdtemp(dir=work)
    env = dict(os.environ, COLUMNS='120', LOCKROT_TODAY=day, COMPOSER_HOME=composer, COMPOSER_CACHE_DIR=os.path.join(composer, 'cache'))
    done = subprocess.run(['php', phar] + arguments, cwd=project, env=env, capture_output=True, text=True)
    if done.returncode != 0:
        raise RecordError('lockrot exited %d on %s: %s' % (done.returncode, snapshot, done.stderr.strip()))
    return done.stdout


def record(phar: str, day: str, root: str) -> None:
    if DAY.match(day) is None:
        raise RecordError('the day is %s, not YYYY-MM-DD' % day)
    phar = os.path.abspath(phar)
    version = subprocess.run(['php', phar, '--version'], capture_output=True, text=True, check=True).stdout.strip()
    if not version.startswith('lockrot '):
        raise RecordError('%s prints "%s", not "lockrot <version>"' % (phar, version))
    with open(os.path.join(root, PAGE), encoding='utf-8') as handle:
        page = handle.read()
    recorded, clean = snapshots(page)
    work = tempfile.mkdtemp(prefix='lockrot-example-run-')
    try:
        table = run(phar, day, recorded, root, work, ['--target-php=8.4', '--output=json:lockrot.json'])
        with open(os.path.join(work, os.path.basename(recorded), 'lockrot.json'), encoding='utf-8') as handle:
            report = render(abridge(json.load(handle)))
        clean_run = run(phar, day, clean, root, work, ['--target-php=8.4'])
    finally:
        shutil.rmtree(work)
    with open(os.path.join(root, PAGE), 'w', encoding='utf-8') as handle:
        handle.write(splice(page, version[len('lockrot '):], day, table, report, clean_run))


def main(argv: List[str]) -> int:
    if len(argv) != 3:
        print(__doc__.strip().splitlines()[2].strip(), file=sys.stderr)
        return 2
    try:
        record(argv[1], argv[2], os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
    except RecordError as error:
        print('example_run: %s' % error, file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
