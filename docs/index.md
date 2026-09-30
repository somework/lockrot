---
title: lockrot documentation — unmaintained packages in composer.lock
description: "lockrot finds the unmaintained, abandoned and branch-pinned packages in composer.lock that composer audit passes. Install it, see a real run, find your page."
---

# lockrot documentation

**lockrot finds the packages in `composer.lock` that nobody maintains any more, including the ones
`composer audit` passes because no maintainer marked them abandoned.**

| I want to … | Read |
|---|---|
| **Decide whether it is worth trying**: what it finds and what a run looks like | [Install](#install), [Example run](example-run.md), [The verdicts](verdicts.md#the-nine-verdicts), [Install-time summary](install-time.md) |
| **Understand why a package is flagged**, then fix, accept or allowlist it | [What it reports](verdicts.md), [Explaining one package](configuration.md#explaining-one-package), [Baseline](baseline.md), [The allowlist](configuration.md#the-allowlist) |
| **Clear a flag on a package I maintain**: which rule fired and what clears it | [The verdicts](verdicts.md#the-nine-verdicts), [The signals](verdicts.md#the-signals) |
| **Fail CI on the right findings**, and read every exit code | [In CI](ci.md), [Exit codes](ci.md#exit-codes), [Baseline](baseline.md), [Configuration](configuration.md), [Run notes](notes.md) |
| **Read the JSON report from my own tooling**: stable fields, schemas, sets that may grow | [JSON schemas](schema.md), [Run notes](notes.md), [Compatibility](compatibility.md), [Changelog](changelog.md) |
| **Review what it reads, writes and contacts**, and verify the PHAR | [What lockrot reads, writes and contacts](https://github.com/somework/lockrot/blob/main/SECURITY.md#what-lockrot-does-and-does-not-do), [PHAR and self-update](phar.md), [How it fetches metadata](internals.md) |
| **Build, test or contribute** | [CONTRIBUTING.md](https://github.com/somework/lockrot/blob/main/CONTRIBUTING.md) |

These pages describe the newest release; the [changelog](changelog.md) says what differs in older
ones. The lockrot.dev landing page and blog live in
[somework/lockrot.dev](https://github.com/somework/lockrot.dev).

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

To check the archive before running it, see [Verifying the download](phar.md#verifying-the-download).
Set `GITHUB_TOKEN` for a complete run;
[Repository hosts and credentials](internals.md#repository-hosts-and-credentials) says what an
anonymous run leaves out. lockrot checks against `config.platform.php`, else the PHP running it;
pass `--target-php=<version>` when that is not the PHP the project runs on in production.

## One real run

A real run, recorded as a terminal session:

![lockrot on a real project: findings grouped by priority, the summary block and the footer](assets/lockrot-demo.gif)

The [example run](example-run.md) gives a larger one in full, as text, on wallabag's lock.
[Reading the table](example-run.md#the-table-format) explains each line;
[What it reports](verdicts.md) explains each verdict.
