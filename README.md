# lockrot

[![CI](https://github.com/somework/lockrot/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/somework/lockrot/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/somework/lockrot.svg?style=flat-square)](https://packagist.org/packages/somework/lockrot)
[![PHP version](https://img.shields.io/packagist/php-v/somework/lockrot.svg?style=flat-square)](https://packagist.org/packages/somework/lockrot)
[![PHAR](https://img.shields.io/github/v/release/somework/lockrot?style=flat-square&label=phar)](https://github.com/somework/lockrot/releases/latest)
[![License](https://img.shields.io/packagist/l/somework/lockrot.svg?style=flat-square)](LICENSE)

**Finds the packages in `composer.lock` that quietly stopped being maintained.**

Composer warns you about one kind of neglected dependency: the `abandoned` flag a maintainer sets by
hand. It says nothing about a package whose last release was in 2015, one pinned to a branch
snapshot, or one accepted on PHP 8.4 only because its constraint reads `>=5.3.0`.

lockrot reports all of them, with the evidence behind each call, the date the data was read, and the
dependency chain that pulled it in. It reads `composer.lock` and `composer.json`, and writes neither.

Requires PHP 7.4+ and Composer 2.2+.

## Contents

[Install](#install) · [First run](#first-run) · [What it reports](#what-it-reports) ·
[In CI](#in-ci) · [Not every finding is a problem](#not-every-finding-is-a-problem) ·
[Configuration](#configuration) · [Documentation](#documentation) · [Limitations](#limitations) ·
[Roadmap](#roadmap)

## Install

```bash
composer require --dev somework/lockrot
composer config allow-plugins.somework/lockrot true
```

Composer 2.2+ asks for plugin permission on the first `require`/`install`; the second command grants
it. This installs `composer lockrot`, alias `composer rot`.

Or run the PHAR, with no dependency added to your project:

```bash
curl -fsSL -o lockrot.phar https://lockrot.dev/lockrot.phar
php lockrot.phar -d /path/to/project --target-php=8.4
```

To verify a download, take both files from the release:

```bash
curl -fsSL -O https://github.com/somework/lockrot/releases/latest/download/lockrot.phar
curl -fsSL -O https://github.com/somework/lockrot/releases/latest/download/lockrot.phar.sha256
sha256sum -c lockrot.phar.sha256   # shasum -a 256 -c on macOS
```

[The PHAR, `self-update` and the global plugin install →](https://lockrot.dev/phar/)

## First run

```bash
composer lockrot --target-php=8.4
```

```text
critical (3)
  abandoned    sensio/framework-extra-bundle v6.2.10  direct
               marked abandoned by its repository, replacement: Symfony; last release 2023-02-24
               (3.6 years ago); repository archived on GitHub; …
  silent       javibravo/simpleue 2.1.0  direct
               last release 2017-11-15 (8.8 years ago); last push 2017-11-18 (8.8 years ago); …
high (58)
  abandoned    doctrine/cache 2.2.0  via doctrine/doctrine-bundle
               marked abandoned by its repository; last release 2022-05-20 (4.3 years ago)
  …
200 packages checked · abandoned 19 · silent 8 · pinned 4 · old-promise 41 · stale 2 · …
priority: critical 3 · high 58 · medium 11 · low 2
```

Abridged — `…` marks where lines were cut. [The full run →](https://lockrot.dev/example-run/)

![lockrot output](docs/assets/first-run.png)

> **Set `GITHUB_TOKEN` for a complete run.** Without one, repository-activity checks are capped at 50
> packages, and lockrot reports how many were affected. Development dependencies are not checked
> unless you pass `--dev`.

## What it reports

| Verdict | Meaning |
|---|---|
| `abandoned` | The package's Composer repository marks it abandoned (Packagist by default), or its GitHub repository is archived |
| `silent` | No stable release for at least 5 years **and** no repository push for at least 5 years; an archived repository is reported as `abandoned` instead |
| `pinned` | Installed version is a branch snapshot (`dev-*` or `#hash`), or the package has no stable release at all |
| `old-promise` | The installed version was released before the target PHP's GA date, and its `require.php` constraint is open-ended (`>=N`, `*`) for that target |
| `stale` | Old release or old push, but not old enough (or not on both fronts) for `silent` |
| `unknown` | No data could be obtained |
| `finished` | Matched the built-in or project allowlist — the package is complete by design, not neglected |
| `ok` | None of the above |

Each finding also carries a priority — `critical`, `high`, `medium`, `low`, or `none` for a package
the report does not flag. The verdict sets a base level, which drops one step for a transitive
package and one more for a development-only one, never below `low`. The priority orders the report
and is carried in every format. **The exit code and `--fail-on` stay on the verdict**: it tells you
what to read first, not whether the build fails.

[Every verdict, signal and priority rule →](https://lockrot.dev/verdicts/)

## In CI

| Code | Meaning |
|---|---|
| `0` | No finding reached the `fail-on` threshold (or `fail-on=none`) |
| `1` | A finding reached or exceeded the `fail-on` threshold |
| `2` | Tool or configuration error |

A network failure is reported as a note and never fails the run on its own, unless you pass
`--strict-network`. `--format=github` turns findings into pull-request annotations, `--format=sarif`
uploads them to the Security tab, `--format=gitlab` into a Code Quality report and
`--format=markdown` into a PR comment.

```yaml
- name: lockrot
  run: |
    curl -fsSL -o lockrot.phar https://lockrot.dev/lockrot.phar
    php lockrot.phar --fail-on=silent --target-php=8.4
  env:
    GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

[Exit codes and every output format →](https://lockrot.dev/ci/)

## Not every finding is a problem

A large project rarely starts clean. The **baseline** records the findings you have already seen and
decided to live with, so CI fails only on what is new or has got worse, without turning `--fail-on`
off and losing the check entirely. Commit the file: it is a statement about the project, worth
reviewing like any other change, and the only file lockrot ever writes — on this flag alone.

```bash
composer rot --target-php=8.4 --generate-baseline
```

An **allowlist** covers packages that are finished by design rather than neglected. lockrot ships a
built-in one — `psr/*`, `fig/*`, `symfony/polyfill-*` and more — and those report as `finished`. To
silence a dependency of your own, add it to `extra.lockrot.ignore`; `package` and `reason` are
mandatory, `version` and `expires` optional:

```json
{ "extra": { "lockrot": { "ignore": [
    { "package": "acme/legacy-bridge", "reason": "internal fork, tracked in ACME-123", "expires": "2027-01-01" }
] } } }
```

[Baseline →](https://lockrot.dev/baseline/) · [Allowlist and `ignore` →](https://lockrot.dev/configuration/)

## Configuration

Settings live under `extra.lockrot` in `composer.json`. CLI options win over environment variables,
which win over `composer.json`.

| `extra.lockrot` key | CLI option | Default | Meaning |
|---|---|---|---|
| `fail-on` | `--fail-on=<verdict>` | `none` | Exit 1 threshold: `none`, `stale`, `old-promise`, `pinned`, `silent`, `abandoned` |
| `target-php` | `--target-php=8.4` | `config.platform.php`, else the running PHP | PHP version used for the `old-promise` check |
| `format` | `--format=<name>` | `table` | `table`, `json`, `github`, `sarif`, `gitlab` or `markdown` |
| `include-dev` | `--dev` | `false` | Also check `packages-dev`, one priority step lower |
| `install-time` | — | `on` | Print a compact block during `composer require`/`update`/`install` |
| `ignore` | — | `[]` | Project allowlist |
| — | `--all` | | Show every checked package, not only flagged ones |
| — | `--generate-baseline` | | Write this run's findings to the baseline file and exit 0 |

`extra.lockrot` is validated against
[`resources/lockrot-config.schema.json`](resources/lockrot-config.schema.json). Package metadata comes
from the repositories configured in your `composer.json`, through Composer's own repository layer —
Private Packagist, Satis and mirrors included, with its authentication, proxy settings and metadata
cache. Repository activity comes from GitHub and is cached for 24 hours.

[Full configuration reference →](https://lockrot.dev/configuration/) ·
[How lockrot fetches metadata →](https://lockrot.dev/internals/)

## Documentation

Everything is at [lockrot.dev](https://lockrot.dev).

- [Verdicts](https://lockrot.dev/verdicts/) — the eight verdicts, the six signals, and how priority is derived
- [Configuration](https://lockrot.dev/configuration/) — every `extra.lockrot` key, environment variable and CLI option
- [CI](https://lockrot.dev/ci/) — exit codes and all six output formats, with GitHub and GitLab snippets
- [Baseline](https://lockrot.dev/baseline/) — generating one, the four buckets, and how matching works
- [Install-time summary](https://lockrot.dev/install-time/) — the block Composer prints, its budgets, and the strict gate
- [PHAR](https://lockrot.dev/phar/) — verified downloads, `self-update`, and the global plugin install
- [Internals](https://lockrot.dev/internals/) — the repository layer, two-pass fetching, caching and `--offline`
- [Example run](https://lockrot.dev/example-run/) — one full run in three formats, plus a clean one
- [Changelog](https://lockrot.dev/changelog/) — what changed in each release

## Limitations

- Repository activity is checked on GitHub only. GitLab and Bitbucket are not queried.
- Without a GitHub token, only packages that already look stale on release age (no stable release
  within `release-warn-years`, default 3y, and not already `abandoned`) are checked against GitHub,
  capped at 50 per run; set `GITHUB_TOKEN` to lift the cap. lockrot reports how many this affected.
- No transitive-exposure signal: a package is not flagged because something *it* depends on is
  abandoned, archived or silent. The `Via`/`chain` column shows how a package was pulled in, not the
  other direction.
- The baseline matches by package name only, and never rewrites itself — entries for packages that
  have left the lock are reported as stale, not removed.
- The install-time block reads a package's development flag from the lock the transaction is about to
  leave behind. Under `composer require --dev … --dry-run` no such lock is written, so a package not
  yet in the lock is treated as production and its priority can read one step high. `composer
  lockrot` on the real lock always has the flag.

## Roadmap

Next up: a GitHub Action, transitive exposure on parent packages, and GitLab and Bitbucket
repository activity. Tracked in [issues](https://github.com/somework/lockrot/issues).

## Contributing

Bug reports, fixes and additions to the built-in allowlist are welcome — see
[`CONTRIBUTING.md`](CONTRIBUTING.md). The CLI, configuration keys, output formats, baseline file and
exit codes are the public interface; the PHP classes are not.

## Security

To report a security issue, follow [`SECURITY.md`](SECURITY.md) rather than opening a public issue.

## License

MIT — see [`LICENSE`](LICENSE). Written by Igor Pinchuk.
