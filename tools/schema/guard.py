"""The integrity checks of the schema tools. An `assert` statement is gone under `python -O` and PYTHONOPTIMIZE."""
import sys


def require(ok, *why):
    """Exits 1 with the reasons on stderr when `ok` is false."""
    if not ok:
        sys.exit('refused: ' + ', '.join(str(part) for part in why))
