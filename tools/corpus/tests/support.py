"""Loading the recorded fixtures, and applying a declared mutation to one of them.

Used by every test here. Deliberately thin: the fixtures are read through the same loaders the real
run uses, so a change that breaks loading breaks the offline suite too, in seconds, with no corpus.
"""

import copy
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL = os.path.dirname(HERE)
REPO_ROOT = os.path.dirname(os.path.dirname(TOOL))
FIXTURES = os.path.join(TOOL, 'fixtures')

if TOOL not in sys.path:
    sys.path.insert(0, TOOL)

from lockrot_corpus import load  # noqa: E402
from lockrot_corpus.explain import Pair  # noqa: E402
from lockrot_corpus.jsonio import read_json, read_text  # noqa: E402


def fixture_claims() -> 'list':
    claims, empty = load.load_claims(
        os.path.join(FIXTURES, 'claims', 'reports'),
        os.path.join(FIXTURES, 'claims', 'projects'),
        os.path.join(FIXTURES, 'claims', 'cache'),
        REPO_ROOT)
    assert not empty, 'a recorded report holds no findings: %s' % empty

    return claims


def fixture_pairs() -> 'list[Pair]':
    """The recorded pairs, taken from the index rather than from a directory listing.

    The index is what says which pairs were recorded and what each one covers; globbing would pick
    the index itself up as a target and would judge anything else that happened to be left in the
    directory.
    """
    index = read_json(os.path.join(FIXTURES, 'explain', 'index.json'))
    pairs = []
    for row in index['pairs']:
        slug = row['slug']
        document = read_json(os.path.join(FIXTURES, 'explain', slug + '.json'))
        text = read_text(os.path.join(FIXTURES, 'explain', slug + '.txt'))
        pairs.append(Pair(slug, text, document))

    return pairs


def poisons() -> 'list[dict]':
    return read_json(os.path.join(FIXTURES, 'poison.json'))['poisons']


def unreachable_keys() -> dict:
    return read_json(os.path.join(FIXTURES, 'poison.json'))['unreachable_by_mutation']


_KEY = re.compile(r"""(?:problem|unparsable)\(\s*(['"])((?:(?!\1)[^\\]|\\.)*)\1""")


def emitted_keys() -> 'dict[str, str]':
    """Every problem key the check modules can report, read out of their source.

    The keys are the first argument of a `problem(...)` or `unparsable(...)` call and are always
    literals, deliberately: a key built from a variable could not be enumerated here, and a key
    nobody can enumerate is a branch nobody can prove still fires.
    """
    found = {}
    for module in ('claims', 'explain'):
        path = os.path.join(TOOL, 'lockrot_corpus', module + '.py')
        with open(path, encoding='utf-8') as handle:
            for match in _KEY.finditer(handle.read()):
                found.setdefault(match.group(2), module)

    return found


def apply_mutation(document: dict, mutations: 'list[dict]') -> dict:
    """A deep copy of `document` with each declared mutation applied, by dotted path."""
    poisoned = copy.deepcopy(document)
    for mutation in mutations:
        _set_path(poisoned, mutation['path'].split('.'), mutation.get('set'))

    return poisoned


def _set_path(node: object, path: 'list[str]', value: object) -> None:
    for step in path[:-1]:
        node = node[int(step)] if isinstance(node, list) else node[step]
    last = path[-1]
    if isinstance(node, list):
        node[int(last)] = value
    else:
        node[last] = value
