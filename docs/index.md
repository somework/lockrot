---
title: lockrot documentation — unmaintained packages in composer.lock
description: "lockrot finds the unmaintained, archived and branch-pinned packages in composer.lock that composer audit passes. Install it, see a real run, find your page."
---

# lockrot documentation

**lockrot finds the packages in `composer.lock` that nobody maintains, including the ones
`composer audit` passes because no maintainer marked them abandoned.**

| I want to … | Read |
|---|---|
| **Decide whether it is worth trying**: what it finds and what a run looks like | [Install](#install), [Example run](example-run.md), [The verdicts](verdicts.md#the-nine-verdicts), [Install-time summary](install-time.md) |
| **Understand why a package is graded**, then fix, accept or allowlist it | [What it reports](verdicts.md), [Explaining one package](configuration.md#explaining-one-package), [Baseline](baseline.md), [The allowlist](configuration.md#the-allowlist) |
| **Clear a flag on a package I maintain**: which signal fired and what clears it | [Flags](verdicts.md#flags), [The signals](verdicts.md#the-signals) |
| **Check how a score adds up**: each rule, its numbers and an example | [The score](verdicts.md#score), [Score model 1](verdicts/model-1.md) |
| **Fail CI on the right findings**, and read every exit code | [In CI](ci.md), [Exit codes](ci.md#exit-codes), [Baseline](baseline.md), [Configuration](configuration.md), [Run notes](notes.md) |
| **Read the JSON report from my own tooling**: stable fields, schemas, sets that can grow | [JSON schemas](schema.md), [Run notes](notes.md), [Compatibility](compatibility.md), [Changelog](changelog.md) |
| **Upgrade from 0.13**: baselines, `jq` queries, the GitHub Action, the PHAR and the plugin | [Upgrading to 0.14](changes/0.14.md) |
| **Review what it reads, writes and contacts**, and verify the PHAR | [What lockrot does and does not do](https://github.com/somework/lockrot/blob/main/SECURITY.md#what-lockrot-does-and-does-not-do), [PHAR and self-update](phar.md), [Data sources](internals.md) |
| **Build, test or contribute** | [CONTRIBUTING.md](https://github.com/somework/lockrot/blob/main/CONTRIBUTING.md) |

These pages describe the newest release. The [changelog](changelog.md) says what differs in older
ones.

## Install

The Composer plugin needs PHP 7.4+ and Composer 2.2+:

```bash
composer require --dev somework/lockrot
composer config allow-plugins.somework/lockrot true
composer lockrot
```

The standalone PHAR needs only PHP 7.4+ and adds nothing to the project:

```bash
curl -fsSL -o lockrot.phar https://lockrot.dev/lockrot.phar
php lockrot.phar -d /path/to/project
```

To check the archive before you run it, see
[Verifying the download](phar.md#verifying-the-download). Set `GITHUB_TOKEN` for a complete run.
[Repository hosts and credentials](internals.md#repository-hosts-and-credentials) says what an
anonymous run omits. lockrot judges the lock against `config.platform.php`, else the PHP that runs
it. If that is not the PHP the project runs on in production, pass `--target-php=<version>`
([`extra.lockrot` keys](configuration.md#extralockrot-keys)).

## One real run

A real run, recorded as a terminal session:

![lockrot on a real project: findings grouped by grade, the summary block and the footer](assets/lockrot-demo.gif)

The [example run](example-run.md) gives a larger one in full, as text, on wallabag's lock.
[Reading the table](example-run.md#the-table-format) explains each line.
[What it reports](verdicts.md) explains each flag, the score and the verdicts.
