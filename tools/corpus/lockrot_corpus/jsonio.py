"""Every file this tool reads or writes goes through here, for three reasons that each cost a day.

`encoding='utf-8'` on every open: the rendered `--explain` text carries `·` and em dashes, and the
check that reads the composer.lock line is welded to `·` as its field separator. Under a process
whose default encoding is not UTF-8 that check matches nothing and the run reports a clean census
over a corpus it never parsed.

A missing file and an unreadable one stay apart. The scratchpad ancestor of this tool collapsed both
into `None` with a bare `except Exception`, which put a corrupt cached document into the same bucket
as a package nobody cached — the largest and least-read counter in the output.

Writes go through a `.part` file and `os.replace`. A run killed at the 900-second timeout used to
leave a half-written JSON of non-zero size, and every resume guard downstream accepted it forever.
"""

import hashlib
import json
import os


class CorpusDataError(Exception):
    """A file the tool was told to read exists but does not hold what it must.

    Distinct from a missing file, which is an ordinary answer with an ordinary handling. This one
    means the run cannot say whether anything is clean, and the caller turns it into exit 2.
    """

    def __init__(self, path: str, reason: str) -> None:
        super().__init__('%s: %s' % (path, reason))
        self.path = path
        self.reason = reason


def read_json(path: str) -> object:
    """The document at `path`, or None when there is no such file. Raises on one that will not parse."""
    try:
        with open(path, encoding='utf-8') as handle:
            return json.load(handle)
    except FileNotFoundError:
        return None
    except (ValueError, UnicodeDecodeError) as error:
        raise CorpusDataError(path, 'not readable as JSON (%s)' % error)


def read_text(path: str) -> 'str | None':
    """The text at `path`, or None when there is no such file."""
    try:
        with open(path, encoding='utf-8') as handle:
            return handle.read()
    except FileNotFoundError:
        return None
    except UnicodeDecodeError as error:
        raise CorpusDataError(path, 'not readable as UTF-8 (%s)' % error)


def write_json_atomic(path: str, document: object, pretty: bool = True) -> None:
    """Write `document`, visible at `path` only once whole.

    `sort_keys` stays off and `ensure_ascii` off: a tracked file keeps the key order it was written
    with and its accented characters, so a routine refresh diffs as the one line that changed rather
    than as a rewrite. This is the rule `bin/refresh-monorepo-parents` follows for
    resources/monorepo-parents.json, and the reason a reviewer can read that file's diff at all.
    """
    if pretty:
        body = json.dumps(document, indent=2, ensure_ascii=False, sort_keys=False) + '\n'
    else:
        body = json.dumps(document, ensure_ascii=False, sort_keys=False) + '\n'
    write_text_atomic(path, body)


def write_text_atomic(path: str, text: str) -> None:
    directory = os.path.dirname(path)
    if directory:
        os.makedirs(directory, exist_ok=True)
    partial = path + '.part'
    with open(partial, 'w', encoding='utf-8') as handle:
        handle.write(text)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(partial, path)


def sha256_file(path: str) -> 'str | None':
    """The digest of a file on disk, or None when it is not there.

    What makes a resumed target complete is this digest still matching what the run manifest
    recorded — not the file being non-empty, which is what a timeout leaves behind.
    """
    digest = hashlib.sha256()
    try:
        with open(path, 'rb') as handle:
            for block in iter(lambda: handle.read(65536), b''):
                digest.update(block)
    except FileNotFoundError:
        return None
    return digest.hexdigest()


def sha256_bytes(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()
