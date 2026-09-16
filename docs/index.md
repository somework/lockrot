---
title: lockrot — find abandoned and unmaintained packages in composer.lock
og_description: Composer warns about packages whose maintainers said they stopped. lockrot finds the ones that just stopped.
hide:
  - toc
---

# lockrot

**Finds abandoned, unmaintained and branch-pinned packages in `composer.lock` — and releases that
promise a PHP version they were never tested against.**

## Install

=== "Composer plugin"

    ```bash
    composer require --dev somework/lockrot
    composer config allow-plugins.somework/lockrot true
    composer lockrot --target-php=8.4
    ```

    Adds one dev dependency and one command. It also prints a short summary during
    `composer require`, `update` and `install`, above the operations list — see
    [Install-time summary](install-time.md).

=== "PHAR"

    ```bash
    curl -fsSL -o lockrot.phar https://lockrot.dev/lockrot.phar
    php lockrot.phar --target-php=8.4
    ```

    Nothing is added to your project. For the checksum-verified download and how
    `self-update` works, see [PHAR and self-update](phar.md).

=== "Global plugin"

    ```bash
    composer global require somework/lockrot
    composer global config allow-plugins.somework/lockrot true
    ```

    `composer global update` keeps it current. Because it is a plugin rather than a
    PHAR, the install-time summary then runs in every project you touch;
    [Install-time summary](install-time.md) explains how to turn that off.

Requires PHP 7.4 or newer and Composer 2.2 or newer.

## What a run looks like

<pre class="lockrot-term"><span class="b">critical (3)</span>
  <span class="r">abandoned</span>    sensio/framework-extra-bundle v6.2.10  direct
               marked abandoned by its repository, replacement: Symfony; last release 2023-02-24
               (3.6 years ago); repository archived on GitHub; last push 2023-02-24 (3.6 years ago);
               released 2023-02-24, before PHP 8.4 GA (2024-11-21); php constraint &quot;&gt;=7.2.5&quot; has no
               upper bound; pulls in 1 flagged package: doctrine/annotations (abandoned)
  <span class="r">silent</span>       javibravo/simpleue 2.1.0  direct
               last release 2017-11-15 (8.8 years ago); last push 2017-11-18 (8.8 years ago);
               released 2017-11-15, before PHP 8.4 GA (2024-11-21); php constraint &quot;&gt;=5.5&quot; has no
               upper bound
  <span class="r">silent</span>       mnapoli/piwik-twig-extension 3.0.0  direct
               last release 2020-04-24 (6.4 years ago); last push 2020-04-28 (6.4 years ago);
               released 2020-04-24, before PHP 8.4 GA (2024-11-21); php constraint &quot;&gt;=7.0&quot; has no
               upper bound

<span class="b">high (58)</span>
  <span class="r">abandoned</span>    behat/transliterator v1.5.0  via stof/doctrine-extensions-bundle ›
               gedmo/doctrine-extensions
               marked abandoned by its repository; last release 2022-03-30 (4.5 years ago);
               repository archived on GitHub; released 2022-03-30, before PHP 8.4 GA (2024-11-21);
               php constraint &quot;&gt;=7.2&quot; has no upper bound</pre>

The first 21 lines of a real run against wallabag's 200-package lock file, at 100 columns. Four of
its 75 findings — [read the whole report](example-run.md).
{ .lockrot-caption }

## What it looks for

- **`abandoned`** — the package's own repository says so: Packagist carries the `abandoned` marker a
  maintainer set by hand, or the repository is archived on GitHub or GitLab.
- **`silent`** — five years with no stable release *and* five years with no push to the repository,
  on the default thresholds. Nobody announced anything; the package simply stopped.
- **`pinned`** — your lock file holds a branch snapshot or a commit hash instead of a released
  version, so the thing you installed has no version number anyone else can ask for.
- **`old-promise`** — the release predates the PHP version you target, and its `require.php`
  constraint is open-ended, so Composer accepted it on a PHP nobody released it against.

A fifth verdict, `stale`, catches a package that is old on one of those fronts but not both — worth
knowing, rarely worth acting on. Every finding carries the evidence behind it, the date the data was
read, and the chain of requirements that pulled the package in — every direct requirement it is
reachable from, not only the shortest one, and each direct requirement says what it pulls in.
[What it reports](verdicts.md) has all eight verdicts and the priority rules.

## "Composer already warns me about abandoned packages"

It does, and lockrot reports the same thing. `composer audit --abandoned` reads one field: the
`abandoned` marker a maintainer sets by hand on Packagist. Most packages that stop being maintained
never get it, because setting it is the last act of someone who has already walked away — so the
field is accurate when it is there, and silent the rest of the time.

lockrot reads that field too, and then keeps going. It asks when the last stable release actually
landed, when the repository was last pushed to, whether your lock file is holding a branch snapshot
rather than a version, and whether a release made an open-ended PHP promise it was never tested
against. It reads `composer.lock` and `composer.json`, and it writes to neither.

## In CI

Nothing fails a build until you ask it to: `fail-on` is `none` by default, exit `1` means a finding
reached the threshold you chose, and exit `2` is reserved for lockrot's own errors. On GitHub
Actions, `uses: somework/lockrot-action@v1` is the whole step. Recipes for that, GitLab CI and PR
comments are in [In CI](ci.md); a [baseline](baseline.md) lets you accept what you have today and
fail only on what arrives tomorrow.

## Read on

| Page | What is on it |
|---|---|
| [What it reports](verdicts.md) | The eight verdicts, the signals behind them, and how priority is assigned |
| [Configuration](configuration.md) | `extra.lockrot`, every option, and the command-line flags |
| [In CI](ci.md) | GitHub Actions, GitLab CI, SARIF, PR comments |
| [Baseline](baseline.md) | Accept today's findings, fail on new and worsened ones |
| [Install-time summary](install-time.md) | What the plugin prints during `install`/`update`, and how to silence it |
| [PHAR and self-update](phar.md) | Verified download, `self-update`, the global-plugin alternative |
| [Example run](example-run.md) | The full 200-package report the sample above is cut from |
| [How it fetches metadata](internals.md) | Composer repositories, the GitHub, GitLab and Bitbucket APIs, caching, `--offline` |
| [Changelog](changelog.md) | What changed, release by release |

Source and issues live on [GitHub](https://github.com/somework/lockrot); the package is
[`somework/lockrot` on Packagist](https://packagist.org/packages/somework/lockrot). Released under
the MIT licence.
