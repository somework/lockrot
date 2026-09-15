# Security policy

## Supported versions

The latest minor release is supported. Fixes go into a new patch release on that minor; there are
no backports to earlier ones.

## Reporting a security issue

Use GitHub's private vulnerability reporting:
[open a report](https://github.com/somework/lockrot/security/advisories/new). If that form is not
available to you, email i.pinchuk.work@gmail.com with `lockrot` in the subject.

Please do not open a public issue for a suspected security issue. Expect a first reply within seven
days.

## Verifying a downloaded PHAR

Every release publishes `lockrot.phar.sha256` next to `lockrot.phar`. Fetch both from
`github.com` — the checksum is only worth as much as the host it comes from, so keep it on the same
host as the release itself:

    curl -fsSL -O https://github.com/somework/lockrot/releases/latest/download/lockrot.phar
    curl -fsSL -O https://github.com/somework/lockrot/releases/latest/download/lockrot.phar.sha256
    sha256sum -c lockrot.phar.sha256

`php lockrot.phar self-update` performs the same check automatically: it downloads the checksum
alongside the archive, refuses to install anything whose hash does not match, verifies the PHP
runtime can open the download, and leaves the running file in place if any step fails.

GPG signatures are planned for a later release.

## What lockrot does and does not do

lockrot reads `composer.json` and `composer.lock` and never writes to either. The PHAR always runs
the inspected project with `--no-plugins`, so it never executes that project's Composer plugins.
Network access is limited to the configured Composer repositories and the GitHub API (repository
activity checks, and release lookups for `self-update`); `GITHUB_TOKEN` and
`LOCKROT_GITHUB_TOKEN` are read from the environment and are never written anywhere.

lockrot does not check for known CVEs in your dependencies. For that, use `composer audit`.
