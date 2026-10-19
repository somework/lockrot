#!/usr/bin/env python3
"""Print, as a JSON list, the mutation shards that a full check runs.

    python3 tools/mutation/full_check_subset.py release/full-check-<sha>--solver,verdicts .github/workflows/full-check.yml

The branch name after `--` names the shards, separated by commas. A name is the shard's name in the
matrix with each space written as `-`, because a branch name has no spaces. A branch without `--`
runs every shard. A name that is no shard fails the run, so a typo cannot pass as a green subset.
Standard library only, as tools/corpus.
"""

import json
import re
import sys
from typing import List

SEPARATOR = '--'
SHARD = re.compile(r'^\s+- \{name: (.+?), filter: ', re.M)


def shard_names(workflow: str) -> List[str]:
    return SHARD.findall(workflow)


def selected(ref: str, shards: List[str]) -> List[str]:
    if SEPARATOR not in ref:
        return list(shards)
    known = {shard.replace(' ', '-'): shard for shard in shards}
    tokens = ref.split(SEPARATOR, 1)[1].split(',')
    if '' in tokens:
        raise ValueError('the branch names an empty shard after "{}": {}'.format(SEPARATOR, ref))
    unknown = [token for token in tokens if token not in known]
    if unknown:
        raise ValueError('no shard is named {}. The shards: {}'.format(', '.join(unknown), ', '.join(known)))
    return [shard for shard in shards if shard.replace(' ', '-') in tokens]


if __name__ == '__main__':
    with open(sys.argv[2], encoding='utf-8') as handle:
        names = shard_names(handle.read())
    try:
        print(json.dumps(selected(sys.argv[1], names)))
    except ValueError as error:
        sys.exit(str(error))
