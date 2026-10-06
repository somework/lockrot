# Comments, docblocks and config comments

These rules cover every comment in code and config. Prose files follow `writing.md` only.

## The default is no comment

Code gets no comment on a class, a method or a line. The exceptions are the four kinds in this
list and the tags that "Tags that stay" and "Docblock types" keep. A comment whose words name only
identifiers, literals or types of the code it sits on restates that code: delete it, do not reword
it. If a better name or a small extraction makes a comment unnecessary, make that change instead.

- **A reason or a constraint** that forbids the simpler code: the floor (CONTRIBUTING, "What the
  code has to run on"), an external behaviour, a refused input, a published-data requirement. Or
  the case that a test's data must reach.
- **A contract** that a caller must know: an input that the code refuses, a limit, an order.
- **A warning** about a trap: what not to change, then what breaks.
- **A link** to the external specification that the code follows, or to the repository heading or
  PR that holds a longer reason. A bare issue number or a private document is not a link.

Put the comment at the line that it explains. When the code changes, change or delete its comment
in the same pull request.

## What a comment never holds

`writing.md` ("Text that stays true") forbids time words, counts of open sets and positions. Also:

- **History:** dates, the lockrot version where behaviour changed, "was", "dropped", "before",
  "after" or "since" about earlier code, output or tool versions, the story of a bug hunt. History
  goes in the commit message or the PR. A comment can link the PR or the commit.
- **Measurements:** run ids, coverage or MSI percentages, mutant, file or package counts. A
  timeout, a retry count or a gate floor in config gets the rule behind it. It also gets a link to
  the PR or the commit with the measurement.
  Bad: `# 50, not 30: the shard measured 20m02s on 2026-09-19 and was cancelled at 30.`
  Good: `# Keep the cap far above a shard's run time: a slow runner cancels a green shard. <PR>`

## Boilerplate to delete

- A docblock or tag that repeats the signature: `@param string $name The name`, `@return void`,
  `/** Constructor. */`, a class comment that retells the class name.
- Section banners (`// ----`), commented-out code, empty comments, a `TODO` without an issue URL.

## Tags that stay

Keep each tag that a tool reads: `@internal` on every class, interface and trait in `src/`
(`PublicApiTest`), and each PHPUnit 9 annotation beside its attribute (`@dataProvider`, `@group`,
`@runInSeparateProcess`, `@preserveGlobalState`, `@coversNothing`). Keep `@phpstan-*` tags too.

## Docblock types

Add a PHPDoc type only when it says more than the native type: an array shape, `list<T>`,
`array<K, V>`, `@template`, `class-string<T>`, `Foo::BAR_*`, an int range, `non-empty-string`,
`mixed` (no native type on PHP 7.4), a `@var` that PHPStan cannot infer. Add `@throws` for an
exception that a caller must know: a refused input, or a failure that the method passes up. Text
after a tag gives only what the type cannot: a unit, a key, an order, the allowed values.

## Size

A comment or docblock has at most six lines of text, tag lines not counted. A file header that
`CONTRIBUTING.md` points to can be longer. A longer reason goes in a repository Markdown file or
the PR, and the comment links it. A ledger (`tests/infection-equivalents.md`) is the one list of
its sites. Other files link it. Each entry names the symbol and the mutator, with no line, date,
version or measurement.
