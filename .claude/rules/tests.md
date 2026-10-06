---
paths:
  - "tests/**/*.php"
---
# Tests that fail only on a defect

## Assert the contract

- Assert a verdict, a signal level, an exit code, a JSON field, a note code or a return value.
- Assert exact text only when the text is the contract. That is an output format, a line that
  another tool parses, or a docs sentence that states a value the code holds. Otherwise assert the
  code, the field or the key.
- Test through public methods. Do not read private state with reflection. Do not assert the calls
  between internal classes. Mock only a boundary: HTTP, the clock, the file system, the Composer IO.

## Numbers over recorded fixtures

A re-recorded fixture (`tests/fixtures/apps/`, the recorded HTTP answers) moves counts without a
defect, and a total hides which package moved.

- Pin an exact number when the test's own arrange step fixes it. Examples are facts from
  `FactsBuilder`, a finding from `FindingBuilder`, a lock that the test writes.
- Over recorded fixtures, assert invariants and per-package results. A per-package result is a
  named package's verdict, or a committed golden file with a rewrite command that the failure
  message names. Keep a total until its per-package replacement exists.
  Bad: an exact total of fired signals over every fixture app.
  Good: each package's fired signal ids match a committed golden file. Guard a fixture loop with
  `assertNotSame([], $apps)`.

## Hermetic by default

- **Clock:** use `Clock::fixed()` or the `LOCKROT_TODAY` that `phpunit.xml.dist` sets. Do not use
  `time()`, `date()` or a `DateTime` without a fixed instant. A test of the real clock or of a
  file modification time can.
- **Network:** serve recorded answers with `FixtureRepositoryServer` or `FakeHttpClient`. A test
  that needs the real network is in the `network` or `e2e` group.
- **File system:** write only into a new temporary directory. Delete it in `tearDown()` or in a
  `finally` block.
- **Order:** sort what `readdir()`, a `DirectoryIterator` or a `Finder` without `sortByName()`
  gives before you compare it. A test does not depend on another test that runs first.

## Data and names

- Build test data with the builders, and set only the fields that the test reads. Record fixtures
  with the recorders, never by hand (CONTRIBUTING, "Fixtures are recorded, not written").
- A test name has the subject, a verb and the outcome: `testBaselineRejectsAnUnknownKey`, not
  `testBaseline`.
