"""The corpus checkers: lockrot's claims read against the data lockrot read, not against lockrot.

Nothing is imported here on purpose. Every module under this package is meant to be importable on
its own, so `python -m compileall tools/corpus` says something, and so a cycle between two modules
is a syntax-level mistake rather than an import-order accident.
"""

# Stamped into every run manifest and every JSON census, so an output recorded months ago can be
# told from a current one without guessing. Bumped when a check changes what it asserts, not when
# a comment is reworded.
VERSION = '1'
