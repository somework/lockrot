"""Every file that this tool reads or writes goes through here.

Every open names `encoding='utf-8'`. The check that reads the composer.lock line depends on `·` as
its separator and matches nothing under another default encoding. A missing file and an unreadable
one stay apart: `None` and `CorpusDataError`. A write goes through a `.part` file and `os.replace`,
so a killed run never leaves a half-written file that a resume guard accepts.
"""

import hashlib
import json
import os


class CorpusDataError(Exception):
    """A file that the tool must read exists but does not hold what it must.

    It differs from a missing file: the run cannot say whether anything is clean, and the caller
    turns it into exit 2.
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
    """Writes `document` so that it becomes visible at `path` only once whole.

    `sort_keys` and `ensure_ascii` stay off, so a tracked file keeps its key order and its accented
    characters and a routine refresh diffs as the one changed line.
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
    """The digest of a file on disk, or None when it is not there."""
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
