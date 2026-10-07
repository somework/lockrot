---
title: PHAR and self-update — download, verify and keep lockrot.phar current
description: "Download lockrot.phar, verify it by sha256, GPG signature, GitHub attestation or a rebuild, install it with PHIVE, and keep it current with self-update."
---

# PHAR and self-update {#the-standalone-phar}

Run lockrot on any project without adding a dependency to it. Download the archive from the GitHub
release, verify it, and point it at the project. It needs PHP 7.4 or newer.

```bash
curl -fsSL -O https://github.com/somework/lockrot/releases/latest/download/lockrot.phar
curl -fsSL -O https://github.com/somework/lockrot/releases/latest/download/lockrot.phar.sha256
curl -fsSL -O https://github.com/somework/lockrot/releases/latest/download/lockrot.phar.asc
curl -fsSL https://raw.githubusercontent.com/somework/lockrot/main/lockrot-release-key.asc | gpg --import

sha256sum -c lockrot.phar.sha256                            # intact (macOS: shasum -a 256 -c)
gpg --verify lockrot.phar.asc lockrot.phar                  # compare the printed fingerprint with SECURITY.md
gh attestation verify lockrot.phar --repo somework/lockrot  # optional: needs gh, signed in; built by lockrot's release workflow
```

Run it only when every check passed and the fingerprint matches:

```bash
php lockrot.phar -d /path/to/project --target-php=8.4
```

Update the archive later with `php lockrot.phar self-update`
([Keeping it updated](#keeping-it-updated)).

## Verifying the download

| Check | File | It proves | It needs |
|---|---|---|---|
| sha256 | `lockrot.phar.sha256` | The archive matches the checksum published beside it: the download is intact | `sha256sum` or `shasum` |
| [GPG signature](#gpg-signature) | `lockrot.phar.asc` | The release key signed these bytes, even if the release assets were replaced | `gpg` and the key fingerprint |
| [Build provenance](#build-provenance) | none (GitHub stores it) | GitHub built these bytes in the `PHAR` workflow of `somework/lockrot` | `gh`, signed in (`gh auth login`) |
| [Rebuild](#rebuilding-it-yourself) | none | The bytes come from the tagged source | `git`, PHP, Composer |
| [Self-update signature](#self-update-signature) | `lockrot.phar.sig.json` | The self-update key signed these bytes | `openssl` |

Behind a mirror, still fetch `lockrot.phar.sha256` and `lockrot.phar.asc` from `github.com`. The
short URL `https://lockrot.dev/lockrot.phar` redirects to the same GitHub release asset. It is a
convenience, not a source of trust.

To pin a release, replace `latest/download` with `download/<tag>` in every URL, for example
`https://github.com/somework/lockrot/releases/download/<tag>/lockrot.phar`.

!!! note "Older releases"
    - Releases before 0.5.0 publish the checksum only: no `lockrot.phar.asc` and no attestation.
      PHIVE refuses them.

    - Releases before 0.6.0 publish no `lockrot.phar.sig.json` and are not reproducible.

### GPG signature

The release key is an OpenPGP key whose fingerprint is listed in
[SECURITY.md](https://github.com/somework/lockrot/blob/main/SECURITY.md#verifying-a-downloaded-phar).
Its public half is
[`lockrot-release-key.asc`](https://github.com/somework/lockrot/blob/main/lockrot-release-key.asc)
in the repository, and it is on `keys.openpgp.org` and `keyserver.ubuntu.com`.

1. Import the key, from the repository or from a keyserver by its fingerprint:

    ```bash
    curl -fsSL https://raw.githubusercontent.com/somework/lockrot/main/lockrot-release-key.asc | gpg --import
    ```

2. Verify the archive:

    ```bash
    gpg --verify lockrot.phar.asc lockrot.phar
    ```

3. Compare the `Primary key fingerprint` gpg prints with the fingerprint in SECURITY.md. That
   comparison is the check: a key file fetched from the same host as the archive proves nothing
   by itself.

A subkey that expires and is replaced signs the releases. The primary key's fingerprint stays the
one to trust.

### Self-update signature

`lockrot.phar.sig.json` is the signature `self-update` checks: RSA, PKCS#1 v1.5 over SHA-384, by
the self-update key, in Composer's `{"sha384": "<base64>"}` format. The archive verifies it with
PHP's openssl extension and the public key built into it, so an update needs no `gpg`.

To check it by hand, run these lines. The last line prints the key's fingerprint. Compare it with
the self-update key in
[SECURITY.md](https://github.com/somework/lockrot/blob/main/SECURITY.md#verifying-a-downloaded-phar).

```bash
curl -fsSL -O https://github.com/somework/lockrot/releases/latest/download/lockrot.phar.sig.json
curl -fsSL -O https://raw.githubusercontent.com/somework/lockrot/main/lockrot-selfupdate-key.pub
php -r 'echo base64_decode(json_decode(file_get_contents("lockrot.phar.sig.json"), true)["sha384"]);' > lockrot.phar.sig.bin
openssl dgst -sha384 -verify lockrot-selfupdate-key.pub -signature lockrot.phar.sig.bin lockrot.phar
openssl pkey -pubin -in lockrot-selfupdate-key.pub -outform DER | sha256sum   # compare with the hex after sha256: in SECURITY.md
```

### Build provenance

GitHub attests each release: a signed statement that the `PHAR` workflow of `somework/lockrot`
built this exact archive from a given commit. Verify it with the
[GitHub CLI](https://cli.github.com/), signed in with `gh auth login`:

```bash
gh attestation verify lockrot.phar --repo somework/lockrot
```

It needs no lockrot key: the trust root is Sigstore's public-good instance, which GitHub uses for
public repositories. Prefer it where `gh` is already installed.

### Rebuilding it yourself

The PHAR is [reproducible](https://reproducible-builds.org/): the tagged commit and the Composer
version the release was built with give the same bytes, on any PHP that the pinned Box runs on.
The steps are in
[CONTRIBUTING.md](https://github.com/somework/lockrot/blob/main/CONTRIBUTING.md#rebuilding-a-release).

### Installing with PHIVE

[PHIVE](https://phar.io/) downloads the release, verifies its GPG signature and pins the version in
`.phive/phars.xml`:

```bash
phive install somework/lockrot
tools/lockrot --target-php=8.4
```

PHIVE shows the release key's fingerprint and asks before it trusts the key. Compare it with
SECURITY.md. To install without the prompt, pass the fingerprint, without spaces, as
`--trust-gpg-keys <fingerprint>`.

### The Docker image

[lockrot-action](https://github.com/somework/lockrot-action#docker-image) publishes and signs the
image `ghcr.io/somework/lockrot`. Its README says how to verify the image.

## What the PHAR does and does not do

- **Commands.** `lockrot` (alias `rot`, the default command, so you can omit its name) and
  `self-update` (alias `selfupdate`), plus Symfony's `help`, `list` and `completion`. No Composer
  command, such as `install` or `update`, runs through the PHAR.

- **The project directory.** `-d <dir>` makes `<dir>` the working directory before lockrot starts.
  Relative `--output` and `--baseline` paths are relative to it:
  `php lockrot.phar -d app --output=json:lockrot.json` writes `app/lockrot.json`.

- **No walk-up.** The PHAR never moves up to a parent directory's project, and it ignores
  Composer's `use-parent-dir`. Run it from the project root or point `-d` there.

- **Option values.** Give lockrot's own options (`--output`, `--format`, `--fail-on`, …) their
  value with `=` (`--output=json:r.json`). With a space and no command name before it, the PHAR
  reads the value as a command name and the run exits `1`. `-d <dir>` takes a space.

- **`COMPOSER`.** Honoured as Composer honours it: `COMPOSER=alt.json php lockrot.phar` reads
  `alt.json` and `alt.lock` ([Environment overrides](configuration.md#environment-overrides)).

[SECURITY.md](https://github.com/somework/lockrot/blob/main/SECURITY.md#what-lockrot-does-and-does-not-do)
lists what lockrot reads, writes, contacts and never runs, in the PHAR and the plugin alike.

## Keeping it updated

```bash
php lockrot.phar self-update          # install the newest release this archive can take
php lockrot.phar self-update --check  # report only
```

`self-update` downloads the chosen release with its `lockrot.phar.sha256` and
`lockrot.phar.sig.json`. It installs it only when the hash matches, the signature verifies against
the key built into the running archive, and PHP can open the download. A failure at any step leaves
the running file exactly as it was.

| Option | Effect |
|---|---|
| none | Installs the newest release that passes the [release rules](#which-release-it-installs) |
| `--check` | Decides as a plain run does and installs nothing. Exit `1` when `self-update` finds a release to install. `--force` does not change what it reports |
| `--allow-major` | Also takes the next major version, one major version per run |
| `--force` | Reinstalls the newest release at or below the running version in its major version, when nothing newer is installable |
| `--offline` | Refuses to run (exit `2`), as `COMPOSER_DISABLE_NETWORK=1` does: an update needs the network |

- **What `--force` takes.** Only that one release. If the
  [release rules](#which-release-it-installs) hold it back, or the running major version has none,
  it exits `2` and looks no further down. A build whose release was withdrawn goes back to the
  newest release left in its major version, never to an older major version.

- **Where it writes.** Only the PHAR's own directory, which must be writable. `self-update` checks
  it before it downloads the archive (`--check` does not need it).

- **Leftovers.** An update killed between the download and the replace can leave a
  `<name>.<pid>-<id>.tmp.phar` file beside the PHAR. It is inert. The next `self-update` that
  downloads a release and verifies it deletes any such file older than an hour.

- **No rollback.** Every earlier release stays downloadable from GitHub.

- **Rate limit.** `LOCKROT_GITHUB_TOKEN`, `GITHUB_TOKEN` or Composer's `github-oauth` lifts GitHub's
  anonymous rate limit
  ([what is sent where](https://github.com/somework/lockrot/blob/main/SECURITY.md#what-lockrot-does-and-does-not-do)).

- **Hosts `--check` reaches.** `--check` downloads no archive, but reads release metadata from
  `github.com`, which redirects to GitHub's release-asset host
  ([hosts](https://github.com/somework/lockrot/blob/main/SECURITY.md#what-lockrot-does-and-does-not-do)).

### Which release it installs

`self-update` reads lockrot's release list from the GitHub API, not through `lockrot.dev`. It skips
drafts, pre-releases and tags that are not a stable version. It prints a line that names a published
tag, listed above the running version, that is not a version at all (`nightly`). Versions compare
the way Composer compares them (`v1.0`, `V1.0.0` and `1.0.0` are one version) and print as
`major.minor.patch`.

It installs the newest release that passes every rule, and prints a line that names the newest
release each rule held back:

| Rule | A release that breaks it | What `self-update` does |
|---|---|---|
| **Same major version.** The major version is the first number: all of 0.x is one line, and 0.x to 1.0 is a major step | Is in a later major version | Prints it, does not install it |
| **A PHP this machine has** | Needs a newer PHP than the running one, or gives a malformed PHP floor (not `major.minor.patch`) | Skips it |
| **A key this archive carries** | Is signed with a self-update key the running archive does not carry | Skips it. After a planned key rotation, this rule makes the transition release the step in between ([SECURITY.md](https://github.com/somework/lockrot/blob/main/SECURITY.md#key-custody-and-rotation)) |

`--allow-major` takes the newest release of the next major version that has a stable release, and
no further: from 1.x it installs the newest 2.x even when 3.0 exists. `self-update` suggests
`--allow-major` only when a run with that option can install a release.

The PHP floor and the signing key come from the release metadata, `lockrot.phar.meta.json`, which
each release publishes beside the archive: `{"php": "7.4.0", "selfupdate-key": "sha256:…"}`.

- If `self-update` cannot read the release metadata of the release that it chooses, it exits `2`,
  and the error names the tag.

- If `self-update` cannot read the release metadata of a next-major release that it only suggests,
  it prints a line about it. The update within the running major version continues.

The release metadata is not signed: it decides which release is tried, and the checksum and the
signature decide whether one is installed. What doctored metadata can and cannot do is in
[SECURITY.md](https://github.com/somework/lockrot/blob/main/SECURITY.md#how-self-update-trusts-a-release).

!!! note "Older releases"
    - Archives before 0.13.0 install whatever GitHub's latest release is. From them, a new major
      version installs directly, a release that needs a newer PHP installs and then refuses to
      start, and a release signed after a key rotation is refused as a signature mismatch:
      [reinstall it by hand](#reinstalling-by-hand). Once such an archive has updated to 0.13.0
      or later, the rules of this section apply.

    - Releases before 0.13.0 publish no `lockrot.phar.meta.json`. `self-update` reads them as
      needing PHP 7.4.0 and makes no claim about their key, so the signature alone decides.

    - The 0.6.0 archive looks for its signature as `lockrot.phar.sig`, which later releases do not
      publish. It reports the missing asset and stays in place: reinstall it by hand once, or run
      `phive update`.

    - The 0.5.0 archive checks the checksum only. Releases before 0.6.0 carry no self-update
      signature, so no archive that checks signatures installs one.

### `self-update` exit codes {#self-update-exit-codes}

`self-update` uses lockrot's [exit codes](ci.md#exit-codes) with meanings of its own:

| Code | Meaning |
|---|---|
| `0` | A release was installed, or nothing is installable: the build is current in its major version, or every newer release is in a later major version or needs a PHP this machine lacks ([release rules](#which-release-it-installs)), each printed on a line. A newer release held back by its key is exit `2` |
| `1` | `--check` only: `self-update` finds a release to install, in the running major version or, with `--allow-major`, in the next one. A line names it |
| `2` | A failure. The running `lockrot.phar` is untouched |

The exit `2` causes include:

- no published release, or GitHub unreachable
- the chosen release lacks an asset, or its release metadata cannot be read
- a checksum mismatch, a signature that does not verify, a PHP whose openssl extension is missing
  or has no SHA-384, or a download PHP cannot open
- an archive stranded by a key rotation, under `--check` too: a key that the archive does not carry
  is all that holds back a newer release in the running major version (or the next one with
  `--allow-major`), and `self-update` finds no release to install
- an unwritable PHAR directory, or a file that cannot be written or moved beside the PHAR
- `--force` when the newest release at or below the running version, in its major version, is
  missing or held back
- `--offline`, or `COMPOSER_DISABLE_NETWORK` set to anything but empty or `0`
- a run outside the PHAR (a plugin install updates with `composer update somework/lockrot`)
- a command line it cannot read (`self-update --nope`, `--check=yes`)

A mistyped command name (`php lockrot.phar self-updte`) is not a `self-update` error: it exits `1`
as any unknown command does ([exit `1` that is not lockrot's](ci.md#exit-1-not-from-lockrot)). A
unique prefix such as `self-up` runs `self-update`.

### Reinstalling by hand

`self-update` cannot replace the archive by itself when:

- it is stranded by a key rotation (exit `2`, and the message names the key it does not carry)
- the self-update key was compromised: replace every archive on the old key this way, whatever its
  version
  ([SECURITY.md](https://github.com/somework/lockrot/blob/main/SECURITY.md#key-custody-and-rotation))
- an installed release refuses to start
- it predates the [release rules](#which-release-it-installs) and the release it takes was signed
  after a key rotation, or it is the 0.6.0 archive (Older releases under
  [Which release it installs](#which-release-it-installs))

To reinstall:

1. Download `lockrot.phar` again, as [PHAR and self-update](#the-standalone-phar) shows.
2. Verify it with the [GPG signature](#gpg-signature) or the [build provenance](#build-provenance),
   not with a key the old archive carried.
3. Put it in place of the old file.

Every `self-update` after that verifies with the key the new download carries.

## In CI

On GitHub Actions, [somework/lockrot-action](https://github.com/somework/lockrot-action) downloads
the PHAR, checks it and runs it in one step ([GitHub Action](ci.md#github-action)). On any other
CI, run these lines in a shell step. Set `GITHUB_TOKEN` from the CI's secret store to lift GitHub's
anonymous rate limit:

```bash
set -e                  # stop at the first failed download or check, before php runs
LOCKROT_VERSION=<tag>   # the release to run; a pinned tag keeps the archive and its checksum from one release
curl -fsSL -O https://github.com/somework/lockrot/releases/download/$LOCKROT_VERSION/lockrot.phar
curl -fsSL -O https://github.com/somework/lockrot/releases/download/$LOCKROT_VERSION/lockrot.phar.sha256
sha256sum -c lockrot.phar.sha256
gh attestation verify lockrot.phar --repo somework/lockrot   # drop this line where the runner lacks gh or GH_TOKEN
php lockrot.phar --fail-on=high --target-php=8.4
```

Pick the `--fail-on` value in [ci.md](ci.md).

## The global-plugin alternative

A global Composer install is the other way to run lockrot outside a project's dependencies, and
`composer global update` keeps it current:

```bash
composer global require somework/lockrot
composer global config allow-plugins.somework/lockrot true
```

It is a plugin, not a PHAR, so it prints the [install-time summary](install-time.md) in every
project you run Composer in. It reads `extra.lockrot` from each project, not from the global
`composer.json`. [install-time.md](install-time.md) lists the switches that turn the summary off.

## When it fails

| Symptom | Cause | Fix |
|---|---|---|
| `gpg: Can't check signature: No public key` | The release key is not imported | Import it ([GPG signature](#gpg-signature)) and run `gpg --verify` again |
| gpg warns the key "is not certified with a trusted signature" | No key you trust has vouched for the release key. The signature itself is good | Compare the `Primary key fingerprint` with SECURITY.md. That comparison is the check |
| gpg reports an expired or unknown subkey | Your copy of the key predates the current signing subkey | Import the key again |
| `sha256sum: command not found` | macOS ships `shasum` instead | `shasum -a 256 -c lockrot.phar.sha256` |
| `sha256sum -c` reports `FAILED` on a fresh download | A release was published between the two `latest` downloads, or the archive is not the published one | Download both again from a pinned tag (`download/<tag>`). If it still fails, do not run the archive |
| `self-update` exits `2` saying the directory is not writable | The PHAR sits in a directory you cannot write, such as `/usr/local/bin` | Run it with `sudo`, or [reinstall by hand](#reinstalling-by-hand) |
| `self-update --check` exits `2` when a newer release exists, on a network that allows only `api.github.com` | `--check` also reads release metadata from `github.com` ([hosts](#keeping-it-updated)) | Allow `github.com` and the host it redirects to, or check on a machine that can reach them |
| `php lockrot.phar --output json:r.json` exits `1` naming a `json` command | The value after a space is read as a command name | Write it with `=`: `--output=json:r.json` |
| `self-update` exits `2` saying the newer releases are signed with a key this archive does not carry | The archive is stranded by a key rotation | [Reinstall by hand](#reinstalling-by-hand) |

## Related

- [SECURITY.md](https://github.com/somework/lockrot/blob/main/SECURITY.md) — the key fingerprints,
  key rotation, and what lockrot reads, writes and contacts
- [ci.md](ci.md#exit-codes) — the exit codes of a lockrot run
- [configuration.md](configuration.md#cli-options) — every option the PHAR's `lockrot` command takes
- [compatibility.md](compatibility.md#distribution) — what stays stable in the PHAR and `self-update`
- [install-time.md](install-time.md) — the summary a plugin install prints
