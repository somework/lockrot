#!/usr/bin/env python3
"""Print infection.json5 with one shard's directories as its source, for a pull request's diff run.

    python3 tools/mutation/shard_config.py infection.json5 src/Verdict src/Output ... > infection.shard.json5

Infection refuses positional paths together with --git-diff-lines, and in that mode it reads the
changed lines only under the configured source directories, ignoring `excludes`. So a shard is
its directories, written into a copy of the configuration. Everything else in the file is kept.
A file at the root of src cannot be a source directory: ci.yml mutates such a file whole, by
path, when the pull request changes it. Standard library only, as tools/corpus.
"""

import json
import posixpath
import sys
from typing import List

SOURCE = '"directories": ["src"]'


def config(text: str, directories: List[str]) -> str:
    if SOURCE not in text:
        raise ValueError('infection.json5 no longer reads {}'.format(SOURCE))
    if not directories:
        raise ValueError('a shard needs at least one directory')
    normalised = [posixpath.normpath(directory) for directory in directories]
    for directory in normalised:
        if not directory.startswith('src/') or directory.endswith('.php'):
            raise ValueError('not a directory under src: ' + directory)
    return text.replace(SOURCE, '"directories": ' + json.dumps(normalised), 1)


if __name__ == '__main__':
    with open(sys.argv[1], encoding='utf-8') as handle:
        sys.stdout.write(config(handle.read(), sys.argv[2:]))
