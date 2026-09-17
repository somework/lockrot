---
title: lockrot.phar — verified download, GPG, PHIVE, self-update
description: "Run lockrot with nothing added to your project: download the PHAR, verify its sha256, GPG signature and build provenance, install with PHIVE, self-update."
---

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

### GPG signature

Every release from 0.5.0 on also ships `lockrot.phar.asc`, a detached OpenPGP signature made by
the lockrot release key (earlier releases have the checksum only):

```text
39EC C3F6 4AE8 D06A 9A63  FD99 AB6F 7F52 AE51 3141
lockrot release signing <i.pinchuk.work@gmail.com>
```

The public key is [`lockrot-release-key.asc`](https://github.com/somework/lockrot/blob/main/lockrot-release-key.asc)
in the repository and on `keys.openpgp.org` and `keyserver.ubuntu.com`. The primary key only
certifies; releases are signed by a subkey that expires and is rotated, so an import may pick up a
newer subkey later while the fingerprint above stays the one to trust.

```bash
gpg --keyserver hkps://keys.openpgp.org --recv-keys 39ECC3F64AE8D06A9A63FD99AB6F7F52AE513141
curl -fsSL -O https://github.com/somework/lockrot/releases/latest/download/lockrot.phar.asc
gpg --verify lockrot.phar.asc lockrot.phar
```

`gpg` prints `Good signature from "lockrot release signing …"`, followed by a warning that the key is
not certified by a trusted signature — that is gpg saying nobody *you* trust has vouched for the
key, not that the signature is bad. Check the fingerprint it prints against the one above. Should
the key ever be revoked, the revocation is published on the keyservers, `lockrot-release-key.asc`
is replaced in the repository and the changelog names the new fingerprint — re-import from either.

The checksum and the signature answer different questions. `sha256sum -c` proves the archive
matches the checksum file next to it — that the download arrived intact, given that both files came
from the same place. The signature proves the bytes were signed with the release key, which the
release assets alone cannot fake: it still holds if the assets were replaced after the fact.
`self-update` checks the checksum, and from 0.6.0 on a signature of its own — see
[Self-update signature](#self-update-signature) below. The release workflow verifies its own
signatures against the committed public keys before it uploads anything, so the keys in the
repository and the keys in CI cannot silently drift apart.

### Self-update signature

Every release from 0.6.0 on also publishes `lockrot.phar.sig.json` (named `lockrot.phar.sig` on
0.6.0 for its first hour; PHIVE reads any `.sig` asset as a GPG signature, so it was renamed): an
RSA signature (PKCS#1 v1.5 over
SHA-384) by the lockrot self-update key, in the `{"sha384": "<base64>"}` file format Composer uses
for `composer.phar`. It exists so that the archive can verify a release by itself — with
`openssl_verify()` and the public key built into it, nothing installed on the machine — which an
OpenPGP signature cannot give it without `gpg`. The key is RSA 4096 and separate from the GPG
release key; its public half is `lockrot-selfupdate-key.pub` in the repository root. To check it
by hand:

```bash
curl -fsSL -O https://github.com/somework/lockrot/releases/latest/download/lockrot.phar.sig.json
curl -fsSL -O https://raw.githubusercontent.com/somework/lockrot/main/lockrot-selfupdate-key.pub
php -r 'echo base64_decode(json_decode(file_get_contents("lockrot.phar.sig.json"), true)["sha384"]);' > lockrot.phar.sig.bin
openssl dgst -sha384 -verify lockrot-selfupdate-key.pub -signature lockrot.phar.sig.bin lockrot.phar
```

For a person, the GPG signature or the attestation is the check to make: they do not depend on a
key fetched from the same place as the archive. The self-update signature is for the archive
already on the machine, whose key arrived with a download that was verified once. Like every
signature over an archive, it proves the bytes are a lockrot release, not that they are the newest
one: the version comes from the release document, which `self-update` reads from GitHub's API over
TLS. Composer's self-update has the same shape.

### Build provenance

Each release from 0.5.0 on is also attested by GitHub: a signed statement that this exact archive was produced by
the `PHAR` workflow of `somework/lockrot` from a given commit. With the
[GitHub CLI](https://cli.github.com/):

```bash
gh attestation verify lockrot.phar --repo somework/lockrot
```

This needs no key of lockrot's at all — the trust root is GitHub's Sigstore instance — which makes
it the check to prefer in an environment that already has `gh`.

### Rebuilding it yourself

From 0.6.0 on the PHAR is [reproducible](https://reproducible-builds.org/): the tagged commit,
Box 4.7.0 and the Composer version the release was built with give the same bytes, so the archive
on the release page can be checked against its own source rather than against the machine that
built it. Box is downloaded and checked by the script; the Composer version is pinned in
`.github/workflows/phar.yml` at the tag (`tools: composer:…`), and the script prints the PHP,
Composer and Box versions it ran with. PHP is not part of the recipe: CI builds every commit on
two PHP versions and compares the bytes.

```bash
git clone https://github.com/somework/lockrot.git && cd lockrot
git checkout v0.6.0
grep 'tools: composer' .github/workflows/phar.yml   # the Composer version to build with
build/build-phar.sh                     # downloads Box 4.7.0 and checks its sha256 on the way
sha256sum build/lockrot.phar            # compare with lockrot.phar.sha256 of the release
```

What the script pins is written at its top: the dependencies of the archive come from the committed
`build/phar/composer.lock`, the autoloader suffix, the PHAR alias and the package versions
Composer records are fixed, every file inside the archive carries the commit date of the checkout
(`SOURCE_DATE_EPOCH` overrides it), only an allowlist of lockrot's own files goes in, and Box
compiles the files in sorted order. Every commit is also rebuilt on a second CI machine and compared
byte for byte, so a change that ties the archive to the build machine fails before it is released.

### Installing with PHIVE

[PHIVE](https://phar.io/) downloads the release, verifies the signature and pins the version in
`.phive/phars.xml` (0.5.0 or later; it refuses the unsigned earlier releases):

```bash
phive install somework/lockrot --trust-gpg-keys 39ECC3F64AE8D06A9A63FD99AB6F7F52AE513141
tools/lockrot --target-php=8.4
```

### The Docker image

A Docker image, `ghcr.io/somework/lockrot`, is published from the
[lockrot-action](https://github.com/somework/lockrot-action#docker-image) repository: the same
verified archive on the official PHP CLI image, signed with cosign.

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
php lockrot.phar self-update          # download, verify the sha256 and the signature, replace this file
php lockrot.phar self-update --check  # report only; exits 1 when an update is available
php lockrot.phar self-update --force  # reinstall the latest release even when it is the one running
```

`selfupdate` is accepted as an alias. `--offline` (or `COMPOSER_DISABLE_NETWORK=1`) makes the command
refuse to run rather than fail half-way: an update cannot happen without the network.

`self-update` reads `releases/latest` from the GitHub API directly, not through `lockrot.dev`. It
downloads the release's `lockrot.phar.sha256` and `lockrot.phar.sig.json` alongside the archive, refuses
to install anything whose hash does not match or whose signature does not verify against the key
built into the running archive ([above](#self-update-signature)), and checks that the PHP runtime
can open the download before it replaces the running file. The archive running 0.5.0 checks the
checksum only when it updates — the verifier arrives with 0.6.0 — and releases before 0.6.0 carry
no signature, so a signed build cannot `--force` its way back to one. The 0.6.0 archive looks for
the signature under its first name, `lockrot.phar.sig`, which no later release carries: it reports
the missing asset and leaves itself in place, so replace it by hand once (or `phive update`).

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
| `2` | Every failure: no published release, GitHub unreachable, a checksum mismatch, a signature that does not verify, an archive the runtime cannot open, an unwritable directory, or running outside the PHAR |

On `2` the running `lockrot.phar` is untouched.

## In CI

On GitHub Actions, [somework/lockrot-action](https://github.com/somework/lockrot-action) does the
download, the checksum and the run in one step — see [ci.md](ci.md). Anywhere else:

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
