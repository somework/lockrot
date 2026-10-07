# Security policy

Report a vulnerability privately, check a downloaded `lockrot.phar` against the release keys, and
see what lockrot reads, writes and contacts on your machine.

## Supported versions

We support only the newest minor release. A fix ships as a new patch release of that minor, and
earlier minors get no backports.

## Reporting a security issue

Report through GitHub's
[private vulnerability reporting](https://github.com/somework/lockrot/security/advisories/new). If
the form is not available to you, email i.pinchuk.work@gmail.com with `lockrot` in the subject.

Include the lockrot version, the command you ran and what you observed. Do not open a public issue
for a suspected vulnerability. Expect a first reply within seven days. We fix a confirmed
vulnerability in a patch release and disclose it in a GitHub security advisory.

## Verifying a downloaded PHAR

Import the release key by its fingerprint and verify the archive's signature:

```bash
gpg --keyserver hkps://keys.openpgp.org --recv-keys 39ECC3F64AE8D06A9A63FD99AB6F7F52AE513141
gpg --verify lockrot.phar.asc lockrot.phar
```

The full recipe, with the checksum, the build attestation, a rebuild and PHIVE, is in
[Verifying the download](docs/phar.md#verifying-the-download). The fingerprints to
compare against:

| Key | Signs | Fingerprint | Public half |
|---|---|---|---|
| Release key, OpenPGP (`lockrot release signing <i.pinchuk.work@gmail.com>`) | `lockrot.phar.asc`, checked by people and PHIVE | `39EC C3F6 4AE8 D06A 9A63  FD99 AB6F 7F52 AE51 3141` | [`lockrot-release-key.asc`](lockrot-release-key.asc), `keys.openpgp.org`, `keyserver.ubuntu.com` |
| Self-update key, RSA 4096 | `lockrot.phar.sig.json`, checked by `self-update` | `sha256:ec3ca71b1a3ced86f871b89cff7973b58454e5694136683680b72b18070a8f87` | [`lockrot-selfupdate-key.pub`](lockrot-selfupdate-key.pub) |

The self-update key's fingerprint is the SHA-256 of its DER public key:
`openssl pkey -pubin -in lockrot-selfupdate-key.pub -outform DER | sha256sum` prints the hex after
`sha256:`.

## What lockrot does and does not do

| Action | What |
|---|---|
| **Reads** | `composer.json` and `composer.lock` (or the manifest `COMPOSER` names, and its lock), the baseline, Composer's configuration and credentials, lockrot's own cache, and the environment: the variables in [Environment overrides](docs/configuration.md#environment-overrides) and [Testing hooks](docs/configuration.md#testing-hooks), `COLUMNS` for the table width, and Composer's own |
| **Writes** | The files you name: reports with `--output`, the baseline with `--generate-baseline`. Its repository-activity cache, under Composer's cache directory. With `self-update`, the PHAR |
| **Never writes** | `composer.json` or `composer.lock` |
| **Never runs** | In the PHAR, the inspected project's Composer plugins: it runs the project with `--no-plugins` |

How lockrot writes a report or a baseline:

- lockrot writes each report and baseline to a temporary file beside the target, then renames it
  over the target. lockrot creates the temporary file exclusively, so it never follows a file or
  symlink that is already at that name. lockrot needs write access to that directory.

- A run that stops during a write can leave a `*.tmp` file beside the target. `self-update` stages the
  new archive beside the PHAR ([Keeping it updated](docs/phar.md#keeping-it-updated)).

- lockrot writes an absolute path where it points, inside the project or not.

- A replaced file keeps its permission bits, but not its owner or its ACLs.

- Before anything runs, lockrot refuses an `--output` that names `composer.json`, `composer.lock` or
  a baseline, by any spelling or link that reaches the same file. The full list of refusals is in
  [Writing reports to files](docs/configuration.md#writing-reports-to-files).

lockrot contacts only these hosts, unless `LOCKROT_RELEASE_URL` points `self-update` elsewhere
([How self-update trusts a release](#how-self-update-trusts-a-release)). `--offline` reaches none
of them.

| Host | When | What for |
|---|---|---|
| The Composer repositories the project configures (packagist.org unless it is disabled) | Every run | Package metadata and security advisories, through Composer |
| `api.github.com` | A package's repository is on GitHub | Repository activity |
| `gitlab.com` and each host in Composer's `gitlab-domains` | A package's repository is on one of them | Repository activity |
| `api.bitbucket.org` and `bitbucket.org` | A package's repository is on Bitbucket | Repository activity, and on `bitbucket.org` only the exchange of a Composer `bitbucket-oauth` consumer for a token |
| `api.github.com`, `github.com` and the host that its release downloads redirect to | `self-update` only | The release list, the archive, its checksum, its signature and the release metadata `lockrot.phar.meta.json` (which `--check` reads too) |

Credentials:

- lockrot reads `LOCKROT_GITHUB_TOKEN`, `GITHUB_TOKEN`, `LOCKROT_GITLAB_TOKEN` and `GITLAB_TOKEN`
  from the environment
  ([Environment overrides](docs/configuration.md#environment-overrides)), and uses the
  credentials that Composer already holds.

- lockrot sends each credential only to its own host and never writes it anywhere. `self-update`
  adds its GitHub token to the release-list request only, never to a download. With
  `LOCKROT_RELEASE_URL` set, that request, and the token with it, goes to the host that the
  variable names, unless Composer holds credentials of its own for that host.

- Which host takes which credential, and the anonymous limits, are in
  [Repository hosts and credentials](docs/internals.md#repository-hosts-and-credentials).

What a report reveals about your repositories (their URLs, names and paths) is in
[Where a package came from](docs/schema.md#where-a-package-came-from).

## Known vulnerabilities

lockrot is not a vulnerability scanner: an advisory can raise a finding's priority but never
flags a package ([Security advisories](docs/verdicts.md#security-advisories)). Gate on known
vulnerabilities with `composer audit`.

## How self-update trusts a release

- **Trust starts with the first download.** Verify that one by hand with the GPG signature or the
  attestation. Every `self-update` after it checks against the self-update key in that download.

- **Two variables replace the trust root.** With `LOCKROT_RELEASE_URL` or `LOCKROT_RELEASE_KEY` set
  ([Testing hooks](docs/configuration.md#testing-hooks)), `self-update` reads the release list, and
  checks signatures, against what they name instead of GitHub and the built-in key. The release-list
  request carries the GitHub token wherever `LOCKROT_RELEASE_URL` points. Do not set either variable
  outside a test.

- **What is checked before install.** `self-update` checks the checksum and the self-update
  signature, and installs nothing when either fails
  ([Keeping it updated](docs/phar.md#keeping-it-updated)).

- **What a signature does not prove.** It proves that the bytes are a lockrot release, not that they are
  the newest one. Which release is newest comes from GitHub's release API, over TLS.

- **The release metadata is not signed.** `lockrot.phar.meta.json` names a release's lowest PHP
  and the fingerprint of the key that signed it. It decides only which release `self-update` tries,
  so doctored metadata cannot install anything that the self-update key did not sign. It can
  withhold an update. It can also understate the lowest PHP, so that `self-update` installs a
  signed release that this PHP cannot run, and that release refuses to start. If that happens,
  replace the archive by hand.

- **Terminal output.** `self-update` replaces control characters in the tags, versions and URLs
  that it prints from the release list. A release entry cannot send escape sequences to your
  terminal.

## Key custody and rotation

The release key (OpenPGP):

- The release workflow holds only a signing subkey, and we replace it before it expires. We keep
  the primary key and its revocation certificate offline.

- The workflow verifies each `lockrot.phar.asc` against the committed `lockrot-release-key.asc`
  before it publishes the release.

- If the key is lost or suspected compromised, we revoke it with that certificate on both
  keyservers and replace `lockrot-release-key.asc` in this repository. The changelog names the new
  fingerprint.

The self-update key:

- The release workflow holds the private half only as an encrypted, passphrase-protected secret.

- The release workflow publishes a release only if its signature verifies against
  `lockrot-selfupdate-key.pub` at the previous `v*` tag and its release metadata names that key.
  Every archive in the field verifies with that key.

- A planned rotation ships the new key in a transition release, signed with the old key. An archive
  on the old key skips the releases after the transition release, whose metadata names the new key. It installs the
  transition release and verifies with the new key from then on. The transition release's
  changelog names the new fingerprint.

- An archive is stranded when a newer release is signed with a key that the archive does not carry,
  and no transition release is available (missing, pulled, or it needs a newer PHP). `self-update` and `self-update --check` exit `2` and
  say so. Reinstall the archive [by hand](docs/phar.md#reinstalling-by-hand).

- A compromised key cannot vouch for a transition release. We pull every release that the key
  signed. Reinstall every archive that carries the key by hand, whatever its version, from a
  download that you verify with the GPG signature or the attestation. The advisory and the
  changelog name the new fingerprint.

Archives before 0.13.0 follow a rotation differently:
[Older releases](docs/phar.md#which-release-it-installs).

## Related

- [Verifying the download](docs/phar.md#verifying-the-download) — every check on the PHAR, what
  each proves, and the rebuild recipe
- [Repository hosts and credentials](docs/internals.md#repository-hosts-and-credentials) — which
  host takes which token, and the anonymous limits
- [Where a package came from](docs/schema.md#where-a-package-came-from) — what a report reveals
  about your repositories
