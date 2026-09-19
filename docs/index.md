---
title: lockrot documentation
description: Reference documentation for lockrot, the Composer plugin and PHAR that finds abandoned, unmaintained and branch-pinned packages in composer.lock.
---

# lockrot documentation

**lockrot finds abandoned, unmaintained and branch-pinned packages in `composer.lock` — and releases
that promise a PHP version they were never tested against.** It reads `composer.lock` and
`composer.json`, and writes to neither.

These pages are the reference. They are rendered at [lockrot.dev](https://lockrot.dev) from the
newest release tag, next to the landing page and the blog, which live in
[somework/lockrot.dev](https://github.com/somework/lockrot.dev). The
[README](https://github.com/somework/lockrot#readme) is the short tour.

```bash
composer require --dev somework/lockrot
composer config allow-plugins.somework/lockrot true
composer lockrot --target-php=8.4
```

Or the PHAR, with nothing added to the project: `curl -fsSL -o lockrot.phar https://lockrot.dev/lockrot.phar`
— see [PHAR and self-update](phar.md) for the verified download. Requires PHP 7.4+ and Composer 2.2+.

| Page | What is on it |
|---|---|
| [What it reports](verdicts.md) | The nine verdicts, the signals behind them, and how priority is assigned |
| [Configuration](configuration.md) | `extra.lockrot`, every option, and the command-line flags |
| [In CI](ci.md) | GitHub Actions, GitLab CI, SARIF, PR comments |
| [Baseline](baseline.md) | Accept today's findings, fail on new and worsened ones |
| [JSON schemas](schema.md) | The published schemas for the report, the explanation, the baseline and `extra.lockrot` |
| [Install-time summary](install-time.md) | What the plugin prints during `install`/`update`, and how to silence it |
| [PHAR and self-update](phar.md) | Verified and signed download, PHIVE, `self-update`, the global-plugin alternative |
| [Example run](example-run.md) | A full 200-package report, in three formats, plus a clean one |
| [How it fetches metadata](internals.md) | Composer repositories, the GitHub, GitLab and Bitbucket APIs, caching, `--offline` |
| [Changelog](changelog.md) | What changed, release by release |
