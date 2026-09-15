# The standalone PHAR

No install, no dependency added to your project. Requires PHP 7.4+ to run the PHAR itself — the same
floor as the plugin.

```bash
curl -fsSL -o lockrot.phar https://lockrot.dev/lockrot.phar
php lockrot.phar -d /path/to/project --target-php=8.4
php lockrot.phar --version
```

## Verifying the download

Every release ships `lockrot.phar.sha256` next to the PHAR. Fetch **both files from GitHub** and
check them:

```bash
curl -fsSL -O https://github.com/somework/lockrot/releases/latest/download/lockrot.phar
curl -fsSL -O https://github.com/somework/lockrot/releases/latest/download/lockrot.phar.sha256
sha256sum -c lockrot.phar.sha256
```

`sha256sum` is GNU; on macOS use `shasum -a 256 -c lockrot.phar.sha256`. The checksum file is
produced by the release workflow, so it ships with every published release.

`https://lockrot.dev/lockrot.phar` is a 302 redirect to that same GitHub release asset, kept as the
short form for install lines pasted into CI files that should outlive a repository rename. It is a
convenience, not a second source of trust: routing the checksum through the same domain would mean
trusting whoever controls the domain's DNS in addition to GitHub, so the verified snippet above
stays on `github.com`.

> Fetching the archive and the checksum as two requests means two independent resolutions of
> "latest". If a release lands between them, one resolves to release N and the other to N+1 and
> `sha256sum -c` refuses a perfectly honest download. The window is small and the failure is loud
> rather than silent. To close it, resolve the tag once through
> `GET /repos/somework/lockrot/releases/latest` and download both assets from that pinned tag.

GPG signatures and a Docker image are planned for a later release.

## What the PHAR does and does not do

The PHAR always runs the inspected project with `--no-plugins`: it reads `composer.lock` and
`composer.json` and never needs that project's Composer plugins. It also never writes to
`composer.json` or `composer.lock`.

The only project-inspection commands are `lockrot` (the default, so the name can be left out) and
`self-update`. Symfony's own `help`, `list` and `completion` remain, so `php lockrot.phar list` shows
five entries — and nothing in the inspected project can be installed, updated or run through the
PHAR.

> **No walk-up.** Unlike `composer`, the PHAR never walks up to a parent directory's project —
> Composer's `use-parent-dir` setting is not honoured. Run it from the project root or point it there
> with `-d`.

## Keeping it updated

```bash
php lockrot.phar self-update          # download, verify the sha256, replace this file
php lockrot.phar self-update --check  # report only; exits 1 when an update is available
```

`self-update` reads `releases/latest` from the GitHub API directly, not through `lockrot.dev`. It
downloads the release's `lockrot.phar.sha256` alongside the archive, refuses to install anything
whose hash does not match, and checks that the PHP runtime can open the download before it replaces
the running file.

It needs write access to the directory the PHAR sits in — a PHAR in `/usr/local/bin` wants `sudo`, or
a manual download — and it writes nothing else. If the update fails at any step, the running
`lockrot.phar` is left exactly as it was. There is no rollback, because every earlier release stays
downloadable from GitHub.

Set `GITHUB_TOKEN` or `LOCKROT_GITHUB_TOKEN` to lift GitHub's 60-requests-per-hour anonymous limit if
you check often.

An update killed part-way through — a `Ctrl-C` between the download and the replace — can leave a
`lockrot.phar.<pid>-<id>.tmp.phar` file next to the PHAR. It is inert, and the next `self-update`
deletes any such file older than an hour, so there is nothing to clean up by hand.

`--check` is the CI-friendly half: it never downloads the archive, and exits `1` when a newer release
exists so a scheduled job notices.

### `self-update` exit codes

`self-update` uses lockrot's three [exit codes](ci.md) with its own meanings:

| Code | Meaning |
|---|---|
| `0` | An update was installed, or the build is already current |
| `1` | `--check` only: a newer release exists |
| `2` | Every failure: no published release, GitHub unreachable, a checksum mismatch, an archive the runtime cannot open, an unwritable directory, or running outside the PHAR |

On `2` the running `lockrot.phar` is untouched.

## In CI

```yaml
- name: lockrot
  run: |
    curl -fsSL -o lockrot.phar https://lockrot.dev/lockrot.phar
    php lockrot.phar --fail-on=silent --target-php=8.4
  env:
    GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

## The global-plugin alternative

The alternative to a downloaded PHAR is a global plugin install, which `composer global update` keeps
current:

```bash
composer global require somework/lockrot
composer global config allow-plugins.somework/lockrot true
```

That is a plugin, not a PHAR, so it also enables lockrot's [install-time
summary](install-time.md) in **every** project you run Composer in. `extra.lockrot` is read from the
project being installed, not from the global `composer.json`, so a global `install-time: off` has no
effect. Turn the summary off per project with `"extra": {"lockrot": {"install-time": "off"}}`, or
everywhere with `LOCKROT_DISABLE=1` in your environment.
